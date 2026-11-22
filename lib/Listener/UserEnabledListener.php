<?php

declare(strict_types=1);

/**
 * Synaplan Integration for owncloud.online
 *
 * @copyright Copyright (c) 2026, Synaplan
 * @license Apache-2.0
 */

namespace OCA\OcoSynaplan\Listener;

use OCA\OcoSynaplan\Service\PlatformLinkService;
use OCA\OcoSynaplan\Service\UserAccountService;
use OCP\IUser;

/**
 * When an ownCloud.online account is disabled or enabled, tell Synaplan
 * about that person only. Synaplan pauses or resumes the identity; it does
 * not delete the account.
 *
 * Runs on `OC\User\User::postSetEnabled`, which carries no old value and
 * fires on every call: Synaplan answers a repeated event with "unchanged".
 */
class UserEnabledListener
{
    public function __construct(
        private UserAccountService $userAccounts,
        private PlatformLinkService $platformLinks,
    ) {
    }

    public function handle(IUser $user): void
    {
        $uid = $user->getUID();
        if (!$this->hasIdentity($uid)) {
            return;
        }
        if (!$user->isEnabled()) {
            $this->platformLinks->reportLifecycle($uid, 'disabled');

            return;
        }
        // An admin's Deactivate outlasts the account: the person resumes
        // when they activate AI again.
        if (!$this->userAccounts->isPaused($uid)) {
            $this->platformLinks->reportLifecycle($uid, 'enabled');
        }
    }

    private function hasIdentity(string $uid): bool
    {
        return $this->userAccounts->wasLinked($uid)
            || $this->userAccounts->getLinkKind($uid) !== null
            || $this->userAccounts->holdsCredential($uid)
            || $this->userAccounts->getSynaplanUserId($uid) !== null;
    }
}
