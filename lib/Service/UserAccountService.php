<?php

declare(strict_types=1);

/**
 * Synaplan Integration for owncloud.online
 *
 * @copyright Copyright (c) 2026, Synaplan
 * @license Apache-2.0
 */

namespace OCA\OcoSynaplan\Service;

use OCA\OcoSynaplan\AppInfo\Application;
use OCA\OcoSynaplan\Exception\EmailConflictException;
use OCA\OcoSynaplan\Exception\FailureException;
use OCA\OcoSynaplan\Exception\PlatformLinkException;
use OCA\OcoSynaplan\Exception\SynaplanHttpException;
use OCA\OcoSynaplan\Http\Failure;
use OCP\AppFramework\Http;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\ILogger;
use OCP\IUser;
use OCP\IUserSession;

/**
 * Resolves a per-user Synaplan credential for the current ownCloud.online user.
 *
 * In provision mode, on first use for a user, this service uses the admin
 * API key to:
 *   1. provision a Synaplan account for the ownCloud.online user, and
 *   2. mint a per-user API key on that account.
 *
 * Once this ownCloud.online is registered, both calls also send its client id
 * and the bare user id, so Synaplan stores the canonical identity row.
 * Without a registration the external id stays "<instanceId>:<uid>", as
 * release 1.0.0 sent it.
 *
 * In link mode the person connects their own account (OAuth, see
 * {@see OAuthClient}) and the credential is their access token.
 *
 * Credentials are kept encrypted in the user's preferences (server-side only,
 * never exposed to the browser). All Synaplan calls made on behalf of that
 * user carry them, so each ownCloud.online user acts ONLY on their own
 * Synaplan account (isolated knowledge base, memories, usage).
 *
 * Deliberately talks to Synaplan's admin API directly via IClientService (not
 * via SynaplanClient) to avoid a circular dependency: SynaplanClient depends on
 * this service to resolve the per-user key.
 */
class UserAccountService
{
    public const USER_KEY_PREF = KeyStorage::USER_KEY;
    public const USER_ACCOUNT_ID_PREF = 'synaplan_user_id';
    public const USER_KEY_ID_PREF = 'synaplan_user_key_id';
    /** Scopes the stored key was minted with. Empty means a key minted before watching existed. */
    public const KEY_SCOPES_PREF = 'synaplan_key_scopes';
    public const CONSENT_PREF = 'ai_consent';
    public const CONSENT_AT_PREF = 'ai_consent_at';
    public const LINK_KIND_PREF = 'synaplan_link_kind';
    public const LINK_EMAIL_PREF = 'synaplan_link_email';
    public const LINKED_AT_PREF = 'synaplan_linked_at';
    /** Durable: survives disconnect / 401 so a removed linked person is reported, not dropped silently. */
    public const LINK_ORIGIN_PREF = 'synaplan_link_origin';
    /** '1' while an admin has paused a provisioned person at Synaplan; their key is kept for the resume. */
    public const PAUSED_PREF = 'synaplan_paused';

    public const KIND_LINKED = 'linked';
    public const KIND_PROVISIONED = 'provisioned';
    public const SOURCE = 'owncloud';

    /** Scopes granted to a per-user key (see Synaplan CORE-3 scope vocabulary). */
    private const USER_KEY_SCOPES = ['chat', 'files', 'rag', 'cloud:watch'];
    /** Synaplan refuses /api/v1/user/memories to a restricted key without it. */
    private const MEMORIES_SCOPE = 'memories';

    /**
     * Why the last provision in this request failed, for the person who asked.
     * Null when provisioning was not attempted or it succeeded.
     */
    private ?Failure $provisioningFailure = null;

    public function __construct(
        private IClientService $clientService,
        private IConfig $config,
        private IUserSession $userSession,
        private SynaplanConfig $synaplanConfig,
        private ILogger $logger,
        private KeyStorage $keys,
        private OAuthClient $oauth,
    ) {
    }

    /**
     * Resolve the credential to use for the current user.
     *
     * Returns null when per-user mode is off, when there is no logged-in user,
     * or when provisioning could not complete.
     */
    public function getCurrentUserApiKey(): ?string
    {
        $user = $this->userSession->getUser();
        if (!$user instanceof IUser) {
            return null;
        }

        return $this->resolveKeyForUser($user);
    }

    public function provisioningFailure(): ?Failure
    {
        return $this->provisioningFailure;
    }

    /**
     * Resolve the Synaplan credential for an ownCloud.online user.
     *
     * shared → null (caller uses the install-wide key).
     * provision → stored key, or provision+mint after consent.
     * link → stored credential or null — never provisions implicitly.
     */
    public function resolveKeyForUser(IUser $user): ?string
    {
        $this->provisioningFailure = null;
        if ($this->synaplanConfig->getMode() === SynaplanConfig::MODE_SHARED) {
            return null;
        }
        if ($this->isPaused($user->getUID())) {
            return null;
        }

        $stored = $this->storedKey($user->getUID());
        if ($stored !== '') {
            return $stored;
        }

        if ($this->synaplanConfig->isLinkMode()) {
            return null;
        }

        // Consent gate: never provision a Synaplan account for a user who has
        // not explicitly activated AI. Without a key the caller does NOT fall
        // back to the shared admin key (see SynaplanClient::getApiKey), so the
        // request simply fails until the user consents.
        if (!$this->hasConsent($user)) {
            return null;
        }

        try {
            return $this->provisionAndMint($user);
        } catch (\Throwable $e) {
            $this->provisioningFailure = $this->explainProvisioningFailure($e);
            $this->logger->error('Per-user Synaplan provisioning failed for {uid}: {message}', [
                'app' => Application::APP_ID,
                'uid' => $user->getUID(),
                'message' => $e->getMessage(),
                'reason' => $this->provisioningFailure->code,
            ]);

            return null;
        }
    }

    /**
     * Forget the current user's stored key (e.g. after a 401) so the next
     * request re-provisions and re-mints.
     */
    public function clearCurrentUserApiKey(): void
    {
        $user = $this->userSession->getUser();
        if ($user instanceof IUser) {
            $this->keys->deleteUserKey($user->getUID());
        }
    }

    /**
     * Forget link-mode prefs for the current user (401 on a revoked linked key).
     */
    public function clearLinkPrefs(): void
    {
        $user = $this->userSession->getUser();
        if ($user instanceof IUser) {
            $this->oauth->forget($user->getUID());
            $this->clearLinkPrefsForUid($user->getUID());
        }
    }

    public function clearLinkPrefsForUid(string $uid): void
    {
        foreach ([
            self::LINK_KIND_PREF,
            self::LINK_EMAIL_PREF,
            self::LINKED_AT_PREF,
            self::USER_KEY_ID_PREF,
            self::CONSENT_PREF,
            self::CONSENT_AT_PREF,
        ] as $key) {
            $this->config->deleteUserValue($uid, Application::APP_ID, $key);
        }
    }

    /**
     * Remember that the person connected (the grant itself is kept by
     * {@see OAuthClient}). Connecting is consent, and it replaces a key this
     * server created for them, paused or not: their own account is used now.
     *
     * @param array{id?: int|string, email?: string} $account who they are in Synaplan
     */
    public function storeLinkedAccount(IUser $user, array $account): void
    {
        $uid = $user->getUID();
        $synaplanUserId = (string) ($account['id'] ?? '');
        $email = (string) ($account['email'] ?? '');
        $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

        $this->keys->deleteUserKey($uid);
        $this->config->deleteUserValue($uid, Application::APP_ID, self::PAUSED_PREF);
        $this->config->deleteUserValue($uid, Application::APP_ID, self::USER_KEY_ID_PREF);
        if ($synaplanUserId !== '') {
            $this->config->setUserValue($uid, Application::APP_ID, self::USER_ACCOUNT_ID_PREF, $synaplanUserId);
        }
        $this->config->setUserValue($uid, Application::APP_ID, self::LINK_KIND_PREF, self::KIND_LINKED);
        $this->config->setUserValue($uid, Application::APP_ID, self::LINK_ORIGIN_PREF, self::KIND_LINKED);
        $this->config->setUserValue($uid, Application::APP_ID, self::LINK_EMAIL_PREF, $email);
        $this->config->setUserValue($uid, Application::APP_ID, self::LINKED_AT_PREF, $now);
        $this->config->setUserValue($uid, Application::APP_ID, self::CONSENT_PREF, '1');
        $this->config->setUserValue($uid, Application::APP_ID, self::CONSENT_AT_PREF, $now);
    }

    /**
     * @return 'linked'|'provisioned'|null
     */
    public function getLinkKind(string $uid): ?string
    {
        if ($this->oauth->isConnected($uid)) {
            return self::KIND_LINKED;
        }
        // Read the key first: a key that no longer decrypts takes the link with it.
        $key = $this->storedKey($uid);
        $kind = $this->config->getUserValue($uid, Application::APP_ID, self::LINK_KIND_PREF, '');
        if ($kind === self::KIND_LINKED || $kind === self::KIND_PROVISIONED) {
            return $kind;
        }

        return $key !== '' ? self::KIND_PROVISIONED : null;
    }

    /**
     * True when this ownCloud.online user ever completed a Synaplan link handshake.
     * Survives disconnect so user-deletion never calls deleteRemoteAccount.
     */
    public function wasLinked(string $uid): bool
    {
        return $this->config->getUserValue($uid, Application::APP_ID, self::LINK_ORIGIN_PREF, '') === self::KIND_LINKED;
    }

    /**
     * Status payload for the personal gate and `link#status`.
     *
     * @return array{mode: string, link_available: bool, auto_provision: bool, linked: array{email: string, since: string}|null, kind: 'linked'|'provisioned'|null}
     */
    public function getLinkStatus(): array
    {
        $user = $this->userSession->getUser();
        $uid = $user instanceof IUser ? $user->getUID() : '';
        $kind = $uid !== '' ? $this->getLinkKind($uid) : null;
        $email = $uid !== '' ? $this->config->getUserValue($uid, Application::APP_ID, self::LINK_EMAIL_PREF, '') : '';
        $since = $uid !== '' ? $this->config->getUserValue($uid, Application::APP_ID, self::LINKED_AT_PREF, '') : '';
        if ($since === '' && $uid !== '') {
            $since = $this->config->getUserValue($uid, Application::APP_ID, self::CONSENT_AT_PREF, '');
        }
        $linked = null;
        if ($kind === self::KIND_LINKED) {
            $linked = [
                'email' => $email !== '' ? $email : (string) $user->getEMailAddress(),
                'since' => $since,
            ];
        }

        return [
            'mode' => $this->synaplanConfig->getMode(),
            'link_available' => $this->synaplanConfig->isLinkAvailable(),
            'auto_provision' => $this->synaplanConfig->isAutoProvisionEnabled(),
            'linked' => $linked,
            'kind' => $kind,
        ];
    }

    /**
     * Create a Synaplan account from the two-option gate ("Create one for me").
     *
     * @throws EmailConflictException
     */
    public function provisionForLinkMode(IUser $user): ?string
    {
        if (!$this->synaplanConfig->isAutoProvisionEnabled()) {
            return null;
        }

        $key = $this->provisionAndMint($user);
        if ($key !== null) {
            $uid = $user->getUID();
            $this->config->setUserValue($uid, Application::APP_ID, self::LINK_KIND_PREF, self::KIND_PROVISIONED);
            if (!$this->wasLinked($uid)) {
                $this->config->setUserValue($uid, Application::APP_ID, self::LINK_ORIGIN_PREF, self::KIND_PROVISIONED);
            }
        }

        return $key;
    }

    /**
     * Provision + mint without the auto-provision gate (tests / consent path).
     *
     * @throws EmailConflictException
     */
    public function provisionAccount(IUser $user): ?string
    {
        return $this->provisionAndMint($user);
    }

    public function getStoredApiKey(string $uid): string
    {
        return $this->storedKey($uid);
    }

    /**
     * Whether a credential is held for this person, from what is stored here
     * alone: an access token is not renewed to answer it.
     */
    public function holdsCredential(string $uid): bool
    {
        return $this->oauth->isConnected($uid) || ($this->keys->getUserKey($uid) ?? '') !== '';
    }

    /**
     * The credential to act for this person when they are not signed in.
     * An API key is returned as stored. An OAuth access token is renewed
     * first when it is inside its expiry window, the same way a signed-in
     * request is.
     */
    public function credentialFor(string $uid): string
    {
        return $this->storedKey($uid);
    }

    public function getStoredApiKeyId(string $uid): string
    {
        return $this->config->getUserValue($uid, Application::APP_ID, self::USER_KEY_ID_PREF, '');
    }

    /**
     * Whether the current user must give consent before AI is used.
     *
     * Consent is only meaningful in per-user mode (where using AI creates a
     * personal account on the Synaplan server). In shared-key mode there is no
     * per-user external account, so no per-user consent is required here.
     */
    public function consentRequired(): bool
    {
        return $this->synaplanConfig->isPerUserAccountsEnabled()
            && $this->userSession->getUser() instanceof IUser;
    }

    /**
     * Has the current user activated AI (granted consent)?
     */
    public function hasConsentForCurrentUser(): bool
    {
        $user = $this->userSession->getUser();

        return $user instanceof IUser && $this->hasConsent($user);
    }

    /**
     * Record the current user's consent to activate AI. Provisioning happens
     * lazily on the next Synaplan call (or callers may warm it immediately).
     * A person an admin paused is resumed at Synaplan first.
     *
     * @throws FailureException when Synaplan does not resume a paused person
     */
    public function grantConsent(): void
    {
        $user = $this->userSession->getUser();
        if (!$user instanceof IUser) {
            return;
        }
        $this->resumeIfPaused($user->getUID());

        $this->config->setUserValue($user->getUID(), Application::APP_ID, self::CONSENT_PREF, '1');
        $this->config->setUserValue(
            $user->getUID(),
            Application::APP_ID,
            self::CONSENT_AT_PREF,
            (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM)
        );

        $this->logger->info('User activated AI (consent granted): {uid}', [
            'app' => Application::APP_ID,
            'uid' => $user->getUID(),
        ]);
    }

    /**
     * Admin Deactivate for a person holding a provisioned key on a registered
     * ownCloud.online: Synaplan pauses their identity (lifecycle `disabled`),
     * which refuses the key, and consent goes here. The key is kept, so
     * Activate resumes the same account. Deleting the key instead would make
     * Synaplan revoke the identity for good. Returns false when this does not
     * apply.
     *
     * @throws FailureException when Synaplan could not be told; nothing changes here then
     */
    public function pauseProvisioned(string $uid): bool
    {
        if ($this->oauth->isConnected($uid) || !$this->oauth->isRegistered()
            || ($this->keys->getUserKey($uid) ?? '') === '') {
            return false;
        }
        try {
            $answer = $this->oauth->reportLifecycle($uid, 'disabled');
        } catch (\Throwable $e) {
            $this->logger->warning('Could not pause {uid} at Synaplan: {message}', [
                'app' => Application::APP_ID,
                'uid' => $uid,
                'message' => $e->getMessage(),
            ]);
            throw new FailureException(new Failure(Http::STATUS_BAD_GATEWAY, Failure::SYNAPLAN_UNREACHABLE), 'Synaplan could not pause the person: ' . $e->getMessage());
        }
        if (self::identityGone($answer)) {
            // There is no pause to resume, so the key is forgotten here, not
            // deleted there.
            $this->deactivateUser($uid);

            return true;
        }
        $this->config->setUserValue($uid, Application::APP_ID, self::PAUSED_PREF, '1');
        $this->config->deleteUserValue($uid, Application::APP_ID, self::CONSENT_PREF);
        $this->config->deleteUserValue($uid, Application::APP_ID, self::CONSENT_AT_PREF);
        $this->logger->info('Admin paused AI for {uid} at Synaplan', [
            'app' => Application::APP_ID,
            'uid' => $uid,
        ]);

        return true;
    }

    public function isPaused(string $uid): bool
    {
        return $this->config->getUserValue($uid, Application::APP_ID, self::PAUSED_PREF, '') === '1';
    }

    /**
     * @throws FailureException when Synaplan refuses to resume the person or cannot be reached
     */
    private function resumeIfPaused(string $uid): void
    {
        if (!$this->isPaused($uid)) {
            return;
        }
        if (!$this->oauth->isRegistered()) {
            // The registration that paused them is gone, and the paused
            // identity with it: start over with a new key.
            $this->deactivateUser($uid);

            return;
        }
        try {
            $answer = $this->oauth->reportLifecycle($uid, 'enabled');
        } catch (PlatformLinkException $e) {
            $this->logger->warning('Could not resume {uid} at Synaplan: {message}', [
                'app' => Application::APP_ID,
                'uid' => $uid,
                'message' => $e->getMessage(),
            ]);
            throw new FailureException($e->getErrorCode() === PlatformLinkException::CODE_UNREACHABLE
                ? new Failure(Http::STATUS_BAD_GATEWAY, Failure::SYNAPLAN_UNREACHABLE)
                : new Failure(Http::STATUS_CONFLICT, Failure::ACCOUNT_UNAVAILABLE), 'Synaplan did not resume the person: ' . $e->getMessage());
        }
        if (self::identityGone($answer)) {
            // The paused account was deleted at Synaplan meanwhile: its key
            // is dead, so the person starts over with a new account.
            $this->deactivateUser($uid);

            return;
        }
        $this->config->deleteUserValue($uid, Application::APP_ID, self::PAUSED_PREF);
    }

    /**
     * Whether a lifecycle answer says the person has no identity on this
     * registration (a key from before it, or an account deleted at Synaplan)
     * or one Synaplan already ended: there is nothing to pause or resume.
     *
     * @param array<string, mixed> $answer
     */
    private static function identityGone(array $answer): bool
    {
        return ($answer['account'] ?? '') === 'absent' || ($answer['status'] ?? '') === 'revoked';
    }

    /**
     * Withdraw consent and forget the person's credential here. A connected
     * person's grant is revoked at Synaplan when Synaplan can be told, and is
     * forgotten here either way: nothing is sent for them once they withdrew.
     * The Synaplan account itself is not deleted here (an admin can remove it
     * server-side).
     */
    public function revokeConsent(): void
    {
        $user = $this->userSession->getUser();
        if (!$user instanceof IUser) {
            return;
        }
        $uid = $user->getUID();

        if ($this->oauth->isConnected($uid)) {
            try {
                $this->oauth->revoke($uid);
            } catch (\Throwable $e) {
                $this->oauth->forget($uid);
                $this->logger->warning('{uid} withdrew consent; Synaplan could not be told, so the grant was only forgotten here: {message}', [
                    'app' => Application::APP_ID,
                    'uid' => $uid,
                    'message' => $e->getMessage(),
                ]);
            }
        }
        $this->config->deleteUserValue($uid, Application::APP_ID, self::CONSENT_PREF);
        $this->config->deleteUserValue($uid, Application::APP_ID, self::CONSENT_AT_PREF);
        $this->keys->deleteUserKey($uid);
        $this->clearLinkPrefsForUid($uid);
    }

    /**
     * The person's stored credential, '' when there is none. A key that no
     * longer decrypts is gone: in link mode the link goes with it, so the
     * person sees Connect again; in provision mode the next request
     * provisions a new key.
     */
    private function storedKey(string $uid): string
    {
        if ($this->oauth->isConnected($uid)) {
            $token = $this->oauth->accessToken($uid);
            // '' with the grant still held is a passing failure (Synaplan
            // unreachable): the person stays connected.
            if ($token === '' && !$this->oauth->isConnected($uid)) {
                $this->clearLinkPrefsForUid($uid);
            }

            return $token;
        }
        $key = $this->keys->getUserKey($uid);
        if ($key === null && $this->synaplanConfig->isLinkMode()) {
            $this->clearLinkPrefsForUid($uid);
        }

        return $key ?? '';
    }

    private function hasConsent(IUser $user): bool
    {
        return $this->config->getUserValue($user->getUID(), Application::APP_ID, self::CONSENT_PREF, '') === '1';
    }

    // ---- Admin operations on arbitrary users (used by the admin panel) ----

    /**
     * The Synaplan account id provisioned for a given ownCloud.online user, if any.
     */
    public function getSynaplanUserId(string $uid): ?int
    {
        $id = $this->config->getUserValue($uid, Application::APP_ID, self::USER_ACCOUNT_ID_PREF, '');

        return $id !== '' ? (int) $id : null;
    }

    /**
     * Synaplan ended this person's link: forget their grant and link here.
     */
    public function linkEnded(string $uid): void
    {
        if ($uid === '') {
            return;
        }
        $this->oauth->forget($uid);
        $this->deactivateUser($uid);
    }

    /**
     * Admin action: deactivate AI for a specific user — withdraw their consent
     * and forget their cached key. The Synaplan account itself is left intact
     * (an admin can delete it server-side); the next time the user activates AI
     * a fresh key is minted.
     */
    public function deactivateUser(string $uid): void
    {
        $this->config->deleteUserValue($uid, Application::APP_ID, self::CONSENT_PREF);
        $this->config->deleteUserValue($uid, Application::APP_ID, self::CONSENT_AT_PREF);
        $this->config->deleteUserValue($uid, Application::APP_ID, self::PAUSED_PREF);
        $this->keys->deleteUserKey($uid);
        $this->oauth->forget($uid);
        $this->clearLinkPrefsForUid($uid);

        $this->logger->info('Admin deactivated AI for user {uid}', [
            'app' => Application::APP_ID,
            'uid' => $uid,
        ]);
    }

    /**
     * Fetch per-user usage from Synaplan (admin API) for a provisioned account.
     *
     * @return array<string, mixed>
     */
    public function fetchUsage(int $synaplanUserId): array
    {
        $adminKey = $this->synaplanConfig->getAdminApiKey();
        if ($adminKey === '') {
            return [];
        }

        return $this->adminRequest('GET', '/api/v1/admin/users/' . $synaplanUserId . '/usage', null, $adminKey);
    }

    /**
     * Bring an account provisioned before this ownCloud.online registered
     * (external id `<instance id>:<uid>`, no instance) onto its registration.
     * Until then Synaplan cannot find it from a lifecycle report, so deleting,
     * disabling or pausing the person here never reaches it (`02` §3.1).
     *
     * Provisioning again with the instance id rewrites that row in place.
     * The call carries the account's own email: should Synaplan not find the
     * row, it refuses the email instead of creating a second account.
     *
     * True when there is nothing left to do, false to try again later.
     */
    public function adoptLegacyAccount(string $uid): bool
    {
        $synaplanUserId = $this->getSynaplanUserId($uid);
        $instanceId = $this->synaplanConfig->getLinkInstanceId();
        $adminKey = $this->synaplanConfig->getAdminApiKey();
        if ($synaplanUserId === null || $instanceId === '' || $adminKey === '') {
            return true;
        }
        try {
            $account = $this->adminRequest('GET', '/api/v1/admin/users/' . $synaplanUserId . '/usage', null, $adminKey);
            $user = is_array($account['user'] ?? null) ? $account['user'] : [];
            $externalId = (string) ($user['external_id'] ?? '');
            $email = (string) ($user['email'] ?? '');
            if (!str_contains($externalId, ':') || $email === '') {
                return true;
            }
            $answer = $this->adminRequest('POST', '/api/v1/admin/users', [
                'source' => self::SOURCE,
                'instance_id' => $instanceId,
                'external_id' => $uid,
                'email' => $email,
                'display_name' => (string) ($user['display_name'] ?? ''),
            ], $adminKey);
        } catch (EmailConflictException) {
            $this->logger->warning('Synaplan did not find the account of {uid} to adopt it', [
                'app' => Application::APP_ID,
                'uid' => $uid,
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logger->info('Could not adopt the Synaplan account of {uid}: {message}', [
                'app' => Application::APP_ID,
                'uid' => $uid,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
        if ((int) ($answer['user']['id'] ?? 0) !== $synaplanUserId) {
            $this->logger->warning('Synaplan answered another account when adopting {uid}', [
                'app' => Application::APP_ID,
                'uid' => $uid,
            ]);
        }

        return true;
    }

    private function provisionAndMint(IUser $user): ?string
    {
        $adminKey = $this->synaplanConfig->getAdminApiKey();
        if ($adminKey === '') {
            $this->provisioningFailure = new Failure(Http::STATUS_BAD_GATEWAY, Failure::ADMIN_KEY_MISSING);
            $this->logger->warning('Per-user mode enabled but no admin API key configured', [
                'app' => Application::APP_ID,
            ]);

            return null;
        }

        $instanceId = $this->synaplanConfig->getLinkInstanceId();
        $externalId = $instanceId === ''
            ? $this->synaplanConfig->getInstanceId() . ':' . $user->getUID()
            : $user->getUID();
        $email = $user->getEMailAddress();
        if ($email === null || $email === '') {
            // Synaplan requires an email; synthesize a stable, unique one from
            // the ownCloud.online uid + instance when the user has none set.
            $email = $user->getUID() . '@' . $this->synaplanConfig->getInstanceId() . '.owncloud.local';
        }

        try {
            $account = $this->adminRequest('POST', '/api/v1/admin/users', [
                'source' => self::SOURCE,
                'instance_id' => $instanceId !== '' ? $instanceId : null,
                'external_id' => $externalId,
                'email' => $email,
                'display_name' => $user->getDisplayName(),
            ], $adminKey);
        } catch (\Throwable $e) {
            if ($this->isConflict($e)) {
                throw new EmailConflictException();
            }
            throw $e;
        }

        $synaplanUserId = (int) ($account['user']['id'] ?? 0);
        if ($synaplanUserId <= 0) {
            throw new \RuntimeException('Provisioning response missing user id');
        }

        $scopes = $this->keyScopes();
        $minted = $this->adminRequest('POST', '/api/v1/admin/users/' . $synaplanUserId . '/api-keys', [
            'instance_id' => $instanceId !== '' ? $instanceId : null,
            'name' => 'owncloud-' . $user->getUID(),
            'scopes' => $scopes,
        ], $adminKey);

        $userKey = (string) ($minted['api_key']['key'] ?? '');
        if ($userKey === '') {
            throw new \RuntimeException('Minting response missing api key');
        }

        $this->keys->setUserKey($user->getUID(), $userKey);
        $this->config->setUserValue($user->getUID(), Application::APP_ID, self::USER_ACCOUNT_ID_PREF, (string) $synaplanUserId);
        $this->rememberKeyScopes($user->getUID(), $scopes);
        $mintedKeyId = (string) ($minted['api_key']['id'] ?? '');
        if ($mintedKeyId !== '') {
            $this->config->setUserValue($user->getUID(), Application::APP_ID, self::USER_KEY_ID_PREF, $mintedKeyId);
        }

        $this->logger->info('Provisioned per-user Synaplan account for {uid} (synaplan id {sid})', [
            'app' => Application::APP_ID,
            'uid' => $user->getUID(),
            'sid' => $synaplanUserId,
        ]);

        return $userKey;
    }

    /**
     * Replace a provisioned key that lacks a scope keys are minted with now
     * (watching, or memories once they are offered), without withdrawing
     * consent. A linked credential is left alone: connecting again is what
     * gives that person the new permission. Returns the new key, or null
     * when there is nothing to replace.
     */
    public function renewProvisionedKey(): ?string
    {
        if ($this->synaplanConfig->getMode() !== SynaplanConfig::MODE_PROVISION) {
            return null;
        }
        $user = $this->userSession->getUser();
        if (!$user instanceof IUser || !$this->hasConsent($user) || $this->keyHasEveryScope($user->getUID())) {
            return null;
        }
        $uid = $user->getUID();
        $oldKey = $this->storedKey($uid);
        if ($oldKey === '') {
            return null;
        }
        $oldKeyId = $this->getStoredApiKeyId($uid);

        try {
            $minted = $this->provisionAndMint($user);
        } catch (\Throwable $e) {
            $this->logger->error('Could not renew the Synaplan key for {uid}: {message}', [
                'app' => Application::APP_ID,
                'uid' => $uid,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
        if ($minted === null || $minted === '') {
            // Nothing replaced it (no admin key, say): the old key is still
            // the person's only one.
            return null;
        }
        $this->revokeReplacedKey($oldKey, $oldKeyId);

        return $minted;
    }

    /**
     * @return list<string>
     */
    private function keyScopes(): array
    {
        return $this->synaplanConfig->isMemoriesEnabled()
            ? [...self::USER_KEY_SCOPES, self::MEMORIES_SCOPE]
            : self::USER_KEY_SCOPES;
    }

    private function keyHasEveryScope(string $uid): bool
    {
        $held = explode(' ', $this->config->getUserValue($uid, Application::APP_ID, self::KEY_SCOPES_PREF, ''));

        return array_diff($this->keyScopes(), $held) === [];
    }

    /**
     * @param list<string> $scopes
     */
    private function rememberKeyScopes(string $uid, array $scopes): void
    {
        $this->config->setUserValue($uid, Application::APP_ID, self::KEY_SCOPES_PREF, implode(' ', $scopes));
    }

    /**
     * The key just replaced can still call Synaplan until it is revoked.
     * Failure here keeps the new key: watching works, and the old key expires
     * when the person turns AI off.
     */
    private function revokeReplacedKey(string $oldKey, string $oldKeyId): void
    {
        if ($oldKey === '' || !ctype_digit($oldKeyId)) {
            return;
        }
        try {
            $this->adminRequest('DELETE', '/api/v1/apikeys/' . $oldKeyId, null, $oldKey);
        } catch (\Throwable $e) {
            $this->logger->info('The previous Synaplan key could not be revoked: {message}', [
                'app' => Application::APP_ID,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param array<string, mixed>|null $body Request body for POST; null for GET/DELETE.
     * @return array<string, mixed>
     */
    private function adminRequest(string $method, string $path, ?array $body, string $adminKey): array
    {
        $client = $this->clientService->newClient();
        $url = $this->synaplanConfig->getBaseUrl() . $path;

        $options = [
            'headers' => [
                'X-API-Key' => $adminKey,
                'Accept' => 'application/json',
            ],
            'timeout' => 30,
            'http_errors' => false,
        ];

        if ($body !== null) {
            $options['headers']['Content-Type'] = 'application/json';
            $options['body'] = json_encode($body);
        }

        $response = match ($method) {
            'POST' => $client->post($url, $options),
            'DELETE' => $client->delete($url, $options),
            default => $client->get($url, $options),
        };
        $status = (int) $response->getStatusCode();
        $raw = (string) $response->getBody();
        if ($status === 409) {
            throw new EmailConflictException();
        }
        if ($status >= 400) {
            throw new SynaplanHttpException($status, $raw, 'Synaplan admin API returned HTTP ' . $status);
        }

        $decoded = json_decode($raw, true);

        if (!is_array($decoded)) {
            throw new \RuntimeException('Invalid JSON response from Synaplan admin API');
        }

        return $decoded;
    }

    private function isConflict(\Throwable $e): bool
    {
        if ($e instanceof EmailConflictException) {
            return true;
        }

        return $e instanceof SynaplanHttpException && $e->status === 409;
    }

    /**
     * The failure the person can be shown. A 409 and Synaplan's API_PLAN_REQUIRED
     * code are named directly. Any other safe sentence Synaplan sent is passed
     * on. A server crash is only the HTTP status: its SQL and page stay in the log.
     */
    private function explainProvisioningFailure(\Throwable $e): Failure
    {
        if ($e instanceof EmailConflictException) {
            return new Failure(Http::STATUS_CONFLICT, Failure::EMAIL_ALREADY_USED);
        }
        if ($e instanceof SynaplanHttpException) {
            if (str_contains($e->body, 'API_PLAN_REQUIRED')) {
                return new Failure(Http::STATUS_FORBIDDEN, Failure::API_PLAN_REQUIRED);
            }
            $parsed = Failure::fromSynaplanResponse($e->status, $e->body);
            $reason = $parsed->message !== null ? Failure::safeSentence($parsed->message) : null;
            if ($reason !== null) {
                return new Failure(Http::STATUS_BAD_GATEWAY, Failure::ACCOUNT_UNAVAILABLE, $reason);
            }
            if ($parsed->code === Failure::SYNAPLAN_KEY_REJECTED) {
                return $parsed;
            }

            return new Failure(Http::STATUS_BAD_GATEWAY, Failure::ACCOUNT_UNAVAILABLE);
        }

        return new Failure(Http::STATUS_BAD_GATEWAY, Failure::SYNAPLAN_UNREACHABLE);
    }
}
