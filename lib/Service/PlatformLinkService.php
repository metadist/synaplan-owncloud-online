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
use OCA\OcoSynaplan\Exception\PlatformLinkException;
use OCP\App\IAppManager;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\ILogger;
use OCP\IURLGenerator;
use OCP\IUser;

/**
 * The calls this ownCloud.online makes to Synaplan's platform-links API for
 * one person, with that person's own credential, and the registration's
 * lifecycle.
 *
 * Talks via IClientService (never SynaplanClient) so a 401 on a person's
 * credential cannot re-enter connecting.
 */
class PlatformLinkService
{
    /**
     * What this app does, as Synaplan's registration lists it. Only what is
     * built here: Synaplan offers a feature to a person only when the app
     * says it has it.
     */
    public const CAPABILITIES = ['deliveries'];
    private const REGISTRATION_STATUS = 'link_registration_status';
    /** A copy may be 100 MB; its one-time download path is valid this long. */
    private const DOWNLOAD_TIMEOUT = 300;
    /** Synaplan no longer accepts this registration; its own statuses are pending and active. */
    public const STATUS_REFUSED = 'refused';

    public function __construct(
        private IClientService $clientService,
        private IConfig $config,
        private IURLGenerator $urlGenerator,
        private OAuthClient $oauth,
        private IAppManager $appManager,
        private SynaplanConfig $synaplanConfig,
        private UserAccountService $userAccounts,
        private ILogger $logger,
    ) {
    }

    /**
     * The redirect URI registered with Synaplan: a static page that hops to
     * the link.callback route. ownCloud.online's session cookie is
     * SameSite=Strict, so Synaplan's cross-site redirect must not land on a
     * PHP page (see public/link-return.js).
     */
    public function callbackUrl(): string
    {
        return $this->urlGenerator->getAbsoluteURL(
            $this->urlGenerator->linkTo(Application::APP_ID, 'public/link-return.html')
        );
    }

    /**
     * @param array<string, string> $query
     */
    public function personalSettingsUrl(array $query = []): string
    {
        return $this->urlGenerator->linkToRouteAbsolute('settings.SettingsPage.getPersonal', [
            'sectionid' => Application::APP_ID,
        ] + $query);
    }

    /**
     * Leave Synaplan: its registration of this ownCloud.online ends, and with
     * it every person's grant.
     */
    public function forgetRegistration(): void
    {
        $this->oauth->unregister();
        $this->oauth->forgetIssuer();
        $this->config->deleteAppValue(Application::APP_ID, self::REGISTRATION_STATUS);
    }

    public function disconnect(IUser $user): void
    {
        $uid = $user->getUID();
        $this->revokeRemoteKey($uid);
        $this->userAccounts->deactivateUser($uid);
    }

    /**
     * Best-effort cleanup for user deletion / admin deactivate.
     * Interactive disconnect must use {@see disconnect()} so a failed
     * revocation keeps the local key for a retry.
     */
    public function disconnectUid(string $uid): void
    {
        try {
            $this->revokeRemoteKey($uid);
        } catch (\Throwable $e) {
            $this->logger->warning('Could not revoke linked Synaplan key for {uid}: {message}', [
                'app' => Application::APP_ID,
                'uid' => $uid,
                'message' => $e->getMessage(),
            ]);
        }

        $this->userAccounts->deactivateUser($uid);
    }

    /**
     * A connected person's grant is revoked at Synaplan (which ends their
     * link there); a provisioned key is deleted with itself.
     *
     * @throws PlatformLinkException when the remote key is still valid and
     *                               could not be revoked
     */
    private function revokeRemoteKey(string $uid): void
    {
        if ($this->oauth->isConnected($uid)) {
            $this->oauth->revoke($uid);

            return;
        }
        $keyId = $this->userAccounts->getStoredApiKeyId($uid);
        $key = $this->userAccounts->getStoredApiKey($uid);
        if ($keyId === '' || $key === '') {
            return;
        }

        $this->request('DELETE', '/api/v1/apikeys/' . rawurlencode($keyId), null, [
            'Accept' => 'application/json',
            'X-API-Key' => $key,
        ], true);
    }

    /**
     * Register this ownCloud.online with Synaplan as an OAuth2 client, with
     * its address, the page people return to, where Synaplan notifies it and
     * what this app does.
     *
     * @return array{instance_id: string, status: string}
     */
    public function register(string $registrationToken): array
    {
        $this->oauth->forgetIssuer();
        $answer = $this->oauth->register([
            'client_uri' => $this->urlGenerator->getAbsoluteURL('/'),
            'redirect_uri' => $this->callbackUrl(),
            'delivery_url' => $this->deliveryUrl(),
            'capabilities' => self::CAPABILITIES,
            'server_id' => $this->synaplanConfig->getInstanceId(),
            'version' => $this->appVersion(),
        ], $registrationToken);
        $status = is_string($answer['status'] ?? null) ? $answer['status'] : '';
        $this->config->setAppValue(Application::APP_ID, self::REGISTRATION_STATUS, $status);

        return [
            'instance_id' => $this->oauth->clientId(),
            'status' => $status,
        ];
    }

    /**
     * Where Synaplan's servers call this ownCloud.online: the address the
     * administrator set, or this server's own inbound route.
     */
    public function deliveryUrl(): string
    {
        $configured = trim((string) $this->config->getAppValue(Application::APP_ID, 'delivery_url', ''));

        return $configured !== '' ? $configured : $this->defaultDeliveryUrl();
    }

    /**
     * Where people open one of their files from Synaplan. Synaplan may reach
     * this ownCloud.online at another address than people use, so the address
     * of its request is not it: overwrite.cli.url is, which ownCloud.online
     * sets when it is installed.
     */
    public function openUrl(int $fileId): string
    {
        $path = $this->urlGenerator->linkToRoute('files.viewcontroller.showFile', ['fileId' => $fileId]);
        $base = rtrim((string) $this->config->getSystemValue('overwrite.cli.url', ''), '/');
        if ($base === '') {
            return $this->urlGenerator->getAbsoluteURL($path);
        }
        $root = rtrim((string) parse_url($base, PHP_URL_PATH), '/');
        if ($root !== '' && str_starts_with($path, $root . '/')) {
            $path = substr($path, strlen($root));
        }

        return $base . $path;
    }

    public function defaultDeliveryUrl(): string
    {
        return $this->urlGenerator->linkToRouteAbsolute(Application::APP_ID . '.inbound.receive');
    }

    /**
     * Synaplan's notification issuer and Ed25519 keys, from its two public
     * documents.
     *
     * @return array{issuer: string, keys: list<array{publicKey: string, kid: string}>}
     */
    public function notificationTrust(): array
    {
        $accept = ['Accept' => 'application/json'];
        $configuration = $this->request('GET', '/.well-known/synaplan-configuration', null, $accept);
        $jwks = $this->request('GET', '/.well-known/jwks.json', null, $accept);
        $keys = [];
        foreach (is_array($jwks['keys'] ?? null) ? $jwks['keys'] : [] as $jwk) {
            if (!is_array($jwk) || ($jwk['kty'] ?? '') !== 'OKP' || ($jwk['crv'] ?? '') !== 'Ed25519') {
                continue;
            }
            $x = $jwk['x'] ?? null;
            if (!is_string($x) || $x === '') {
                continue;
            }
            $keys[] = [
                'publicKey' => 'ed25519:' . $x,
                'kid' => is_string($jwk['kid'] ?? null) ? $jwk['kid'] : '',
            ];
        }

        return [
            'issuer' => is_string($configuration['issuer'] ?? null) ? $configuration['issuer'] : '',
            'keys' => $keys,
        ];
    }

    /**
     * Claim a copy Synaplan announced, with the person's own credential. A
     * refusal comes back with its `status_code`.
     *
     * @return array<string, mixed>
     */
    public function claimDelivery(string $id, string $uid): array
    {
        return $this->request(
            'POST',
            '/api/v1/platform-links/deliveries/' . rawurlencode($id) . '/claim',
            null,
            $this->personHeaders($uid),
            false,
            true,
        );
    }

    /**
     * The bytes of a claimed copy, or null when they could not be fetched.
     * The path must be that copy's own file: the request carries the
     * person's credential, so a claim answer cannot point it at any other
     * Synaplan address.
     */
    public function downloadDelivery(string $id, string $downloadPath, string $uid): ?string
    {
        $file = '/api/v1/platform-links/deliveries/' . rawurlencode($id) . '/file?ticket=';
        if (!str_starts_with($downloadPath, $file)
            || preg_match('/^[A-Za-z0-9_-]+$/', substr($downloadPath, strlen($file))) !== 1) {
            $this->logger->warning('Refused a Synaplan copy download outside the copy\'s own path', [
                'app' => Application::APP_ID,
            ]);

            return null;
        }
        $client = $this->clientService->newClient();
        try {
            $response = $client->get($this->synaplanConfig->getBaseUrl() . $downloadPath, [
                'headers' => $this->personHeaders($uid),
                'timeout' => self::DOWNLOAD_TIMEOUT,
                'http_errors' => false,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('Could not download a Synaplan copy: {message}', [
                'app' => Application::APP_ID,
                'message' => $e->getMessage(),
            ]);

            return null;
        }
        if ((int) $response->getStatusCode() !== 200) {
            return null;
        }

        return (string) $response->getBody();
    }

    /**
     * Tell Synaplan how a copy ended: `delivered` with where it is, or
     * `failed` with one of its failure codes.
     *
     * @param array<string, mixed> $result
     */
    public function reportDelivery(string $id, string $uid, array $result): void
    {
        $this->request(
            'POST',
            '/api/v1/platform-links/deliveries/' . rawurlencode($id) . '/result',
            $result,
            array_merge($this->personHeaders($uid), ['Content-Type' => 'application/json']),
            false,
            true,
        );
    }

    /**
     * Whether Synaplan still holds this registration for its administrator's
     * approval. Asked again only while it was pending, so people are told
     * here instead of meeting an error page in Synaplan.
     */
    public function isWaitingForApproval(): bool
    {
        if ($this->config->getAppValue(Application::APP_ID, self::REGISTRATION_STATUS, '') !== 'pending') {
            return false;
        }
        try {
            $status = $this->registrationStatus()['status'] ?? '';
        } catch (\Throwable) {
            return true;
        }

        return $status === 'pending';
    }

    /**
     * The registration as Synaplan has it, or null when there is none. A
     * Synaplan that no longer accepts this client (revoked or removed there)
     * answers the question itself with a refusal, which is the status then.
     *
     * @return array{status: string, host: string, delivery_url: string, reachable: ?bool}|null
     */
    public function registrationStatus(): ?array
    {
        if (!$this->oauth->isRegistered()) {
            return null;
        }
        try {
            $answer = $this->oauth->configuration();
        } catch (PlatformLinkException $e) {
            if ($e->getErrorCode() !== PlatformLinkException::CODE_CLIENT_REFUSED) {
                throw $e;
            }
            $this->config->setAppValue(Application::APP_ID, self::REGISTRATION_STATUS, self::STATUS_REFUSED);

            return ['status' => self::STATUS_REFUSED, 'host' => '', 'delivery_url' => '', 'reachable' => null];
        }
        $status = is_string($answer['status'] ?? null) ? $answer['status'] : '';
        if ($status !== '') {
            $this->config->setAppValue(Application::APP_ID, self::REGISTRATION_STATUS, $status);
        }

        return [
            'status' => $status,
            'host' => $this->publicHostFromUrl(is_string($answer['client_uri'] ?? null) ? $answer['client_uri'] : ''),
            'delivery_url' => is_string($answer['delivery_url'] ?? null) ? $answer['delivery_url'] : '',
            'reachable' => is_bool($answer['reachable'] ?? null) ? $answer['reachable'] : null,
        ];
    }

    /**
     * Tell Synaplan where to call, which server this is, and what this app
     * does (client configuration, RFC 7592). Synaplan refuses the whole
     * change for a delivery address it does not call; the rest is then sent
     * on its own, so what this app does is never held back by the address.
     */
    public function announce(): void
    {
        if (!$this->oauth->isRegistered()) {
            return;
        }
        $about = [
            'server_id' => $this->synaplanConfig->getInstanceId(),
            'app_capabilities' => self::CAPABILITIES,
            'software_version' => $this->appVersion(),
        ];
        try {
            $this->oauth->updateConfiguration(['delivery_url' => $this->deliveryUrl()] + $about);
        } catch (PlatformLinkException $e) {
            if ($e->getErrorCode() !== PlatformLinkException::CODE_METADATA_REFUSED) {
                throw $e;
            }
            $this->oauth->updateConfiguration($about);

            throw new PlatformLinkException($e->getMessage(), PlatformLinkException::CODE_DELIVERY_REFUSED);
        }
    }

    /**
     * Tell Synaplan that a person here was deleted, disabled or enabled
     * again. The change here goes ahead whether or not Synaplan could be
     * told.
     *
     * @return bool whether Synaplan took it
     */
    public function reportLifecycle(string $externalId, string $event): bool
    {
        if ($externalId === '' || !$this->oauth->isRegistered()) {
            return false;
        }
        try {
            $this->oauth->reportLifecycle($externalId, $event);
        } catch (\Throwable $e) {
            $this->logger->warning('Could not tell Synaplan that {uid} was {event}: {message}', [
                'app' => Application::APP_ID,
                'uid' => $externalId,
                'event' => $event,
                'message' => $e->getMessage(),
            ]);

            return false;
        }

        return true;
    }

    /**
     * Who the person is in Synaplan, asked with their own credential.
     *
     * @return array{id?: int|string, email?: string}
     */
    public function whoAmI(string $uid): array
    {
        $me = $this->request('GET', '/api/v1/auth/me', null, $this->personHeaders($uid));
        $user = is_array($me['user'] ?? null) ? $me['user'] : [];

        return [
            'id' => is_int($user['id'] ?? null) || is_string($user['id'] ?? null) ? $user['id'] : '',
            'email' => is_string($user['email'] ?? null) ? $user['email'] : '',
        ];
    }

    /**
     * Whether this person can be acted for: their own Synaplan credential is held.
     */
    public function canActFor(string $uid): bool
    {
        return $uid !== '' && $this->userAccounts->getStoredApiKey($uid) !== '';
    }

    /**
     * Calls done for one person carry that person's own credential: their
     * access token, renewed when it has expired, or a provisioned person's
     * key. Synaplan takes the cloud from it and checks that the copy, folder
     * read or file belongs to that person.
     *
     * @return array<string, string>
     */
    private function personHeaders(string $uid): array
    {
        $key = $uid !== '' ? $this->userAccounts->credentialFor($uid) : '';
        if ($key === '') {
            throw new \RuntimeException('No Synaplan credential is held for this person.');
        }

        return SynaplanCredential::headers($key);
    }

    public function publicHostFromUrl(string $url): string
    {
        $parts = parse_url($url);
        $scheme = (string) ($parts['scheme'] ?? 'https');
        $host = (string) ($parts['host'] ?? 'localhost');
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';

        return $scheme . '://' . $host . $port;
    }

    private function appVersion(): string
    {
        $info = $this->appManager->getAppInfo(Application::APP_ID);

        return is_string($info['version'] ?? null) ? $info['version'] : '';
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, string> $headers
     * @param bool $keepErrors return the decoded body of a 4xx instead of throwing,
     *                         with `status_code` set, so a refused claim can be told apart
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, ?array $body, array $headers, bool $notFoundOk = false, bool $keepErrors = false): array
    {
        $client = $this->clientService->newClient();
        $url = $this->synaplanConfig->getBaseUrl() . $path;
        $options = [
            'headers' => $headers,
            'timeout' => 30,
            'http_errors' => false,
        ];
        if ($body !== null) {
            $options['body'] = json_encode($body);
        }

        try {
            $response = match (strtoupper($method)) {
                'POST' => $client->post($url, $options),
                'PUT' => $client->put($url, $options),
                'DELETE' => $client->delete($url, $options),
                default => $client->get($url, $options),
            };
        } catch (\Throwable $e) {
            $this->logger->warning('Synaplan platform-link request failed: {message}', [
                'app' => Application::APP_ID,
                'message' => $e->getMessage(),
            ]);
            throw new PlatformLinkException('Synaplan request failed.', PlatformLinkException::CODE_UNREACHABLE);
        }

        $status = (int) $response->getStatusCode();
        $decoded = json_decode((string) $response->getBody(), true);
        if ($keepErrors && $status >= 400) {
            return ['status_code' => $status] + (is_array($decoded) ? $decoded : []);
        }
        if ($status === 404) {
            if ($notFoundOk) {
                return [];
            }
            throw new PlatformLinkException(
                'Synaplan is not accepting platform connections yet.',
                PlatformLinkException::CODE_INSTANCE_PENDING
            );
        }
        if ($status === 403) {
            throw new PlatformLinkException(
                'This ownCloud.online is waiting for approval.',
                PlatformLinkException::CODE_INSTANCE_PENDING
            );
        }
        if ($status >= 400) {
            throw new PlatformLinkException(
                'Could not finish connecting this account.',
                PlatformLinkException::CODE_EXCHANGE
            );
        }
        if ($method === 'DELETE' && $decoded === null) {
            return [];
        }

        if (!is_array($decoded)) {
            throw new PlatformLinkException(
                'Invalid JSON response from Synaplan.',
                PlatformLinkException::CODE_EXCHANGE
            );
        }

        return $decoded;
    }
}
