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
 * When an ownCloud.online user is deleted, tell Synaplan that this person is
 * gone. Synaplan revokes that identity with its key and grant, and removes
 * the Synaplan account only while this cloud alone manages it.
 *
 * Runs on the user manager's preDelete hook, while the person's preferences
 * are still readable. Never throws: it cannot block the deletion.
 */
class UserDeletedListener
{
    public function __construct(
        private UserAccountService $userAccounts,
        private PlatformLinkService $platformLinks,
    ) {
    }

    public function handle(IUser $user): void
    {
        $uid = $user->getUID();
        $kind = $this->userAccounts->getLinkKind($uid);
        $linked = $kind === UserAccountService::KIND_LINKED || $this->userAccounts->wasLinked($uid);
        $holdsCredential = $this->userAccounts->holdsCredential($uid);
        $known = $linked || $holdsCredential || $kind !== null || $this->userAccounts->getSynaplanUserId($uid) !== null;
        if ($known && $this->platformLinks->reportLifecycle($uid, 'deleted')) {
            // Synaplan ended the key and the grant with the identity: a
            // revocation sent now would only be refused.
            $this->userAccounts->linkEnded($uid);

            return;
        }
        if ($linked || $holdsCredential) {
            $this->platformLinks->disconnectUid($uid);
        }
    }
}
