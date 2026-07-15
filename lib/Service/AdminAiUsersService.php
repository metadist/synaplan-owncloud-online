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
 * Admin-facing view of which ownCloud users have activated AI and how to
 * manage them (the "control panel" cross-link: ownCloud user <-> Synaplan account).
 */
class AdminAiUsersService
{
    public function __construct(
        private IConfig $config,
        private IUserManager $userManager,
        private UserAccountService $userAccounts,
    ) {
    }

    /**
     * List every ownCloud user who has activated AI (granted consent).
     *
     * Efficient at scale: queries only the users who hold the consent flag
     * (getUsersForUserValue), never the full user base.
     *
     * @return list<array{uid: string, displayName: string, email: string, synaplanUserId: int|null, hasKey: bool, consentAt: string}>
     */
    public function listActivatedUsers(): array
    {
        $uids = $this->config->getUsersForUserValue(Application::APP_ID, 'ai_consent', '1');

        $out = [];
        foreach ($uids as $uid) {
            $user = $this->userManager->get($uid);
            $key = $this->config->getUserValue($uid, Application::APP_ID, 'synaplan_user_api_key', '');

            $out[] = [
                'uid' => $uid,
                'displayName' => $user !== null ? $user->getDisplayName() : $uid,
                'email' => $user !== null ? (string) $user->getEMailAddress() : '',
                'synaplanUserId' => $this->userAccounts->getSynaplanUserId($uid),
                'hasKey' => $key !== '',
                'consentAt' => $this->config->getUserValue($uid, Application::APP_ID, 'ai_consent_at', ''),
            ];
        }

        usort($out, static fn (array $a, array $b): int => strcmp($a['uid'], $b['uid']));

        return $out;
    }

    /**
     * Admin: deactivate AI for a user (withdraw consent + forget their key).
     */
    public function deactivate(string $uid): void
    {
        $this->userAccounts->deactivateUser($uid);
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
