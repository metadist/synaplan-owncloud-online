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
use OCP\IConfig;
use OCP\IUserManager;

/**
 * Admin-facing view of which ownCloud.online users have activated AI and how to
 * manage them (the "control panel" cross-link: ownCloud.online user <-> Synaplan account).
 */
class AdminAiUsersService
{
    public function __construct(
        private IConfig $config,
        private IUserManager $userManager,
        private UserAccountService $userAccounts,
        private PlatformLinkService $platformLinks,
    ) {
    }

    /**
     * List every ownCloud.online user who has activated AI (granted consent).
     *
     * Efficient at scale: queries only the users who hold the consent flag
     * (getUsersForUserValue), never the full user base.
     *
     * @return list<array{uid: string, displayName: string, email: string, synaplanUserId: int|null, hasKey: bool, consentAt: string, kind: string|null}>
     */
    public function listActivatedUsers(): array
    {
        $uids = $this->config->getUsersForUserValue(Application::APP_ID, 'ai_consent', '1');

        $out = [];
        foreach ($uids as $uid) {
            $user = $this->userManager->get($uid);

            $out[] = [
                'uid' => $uid,
                'displayName' => $user !== null ? $user->getDisplayName() : $uid,
                'email' => $user !== null ? (string) $user->getEMailAddress() : '',
                'synaplanUserId' => $this->userAccounts->getSynaplanUserId($uid),
                'hasKey' => $this->userAccounts->holdsCredential($uid),
                'consentAt' => $this->config->getUserValue($uid, Application::APP_ID, 'ai_consent_at', ''),
                'kind' => $this->userAccounts->getLinkKind($uid),
            ];
        }

        usort($out, static fn (array $a, array $b): int => strcmp($a['uid'], $b['uid']));

        return $out;
    }

    /**
     * Admin: deactivate AI for a user. Their key is revoked in Synaplan,
     * whether they connected their own account or the app created it, then
     * consent and key are forgotten here.
     */
    public function deactivate(string $uid): void
    {
        $this->platformLinks->disconnectUid($uid);
    }

    /**
     * Admin: per-user usage pulled from Synaplan for the mapped account.
     *
     * @return array<string, mixed>
     */
    public function usage(string $uid): array
    {
        $synaplanUserId = $this->userAccounts->getSynaplanUserId($uid);
        if ($synaplanUserId === null) {
            return ['success' => false, 'error' => 'User has no Synaplan account yet'];
        }

        return $this->userAccounts->fetchUsage($synaplanUserId);
    }
}
