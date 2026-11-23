<?php

declare(strict_types=1);

/**
 * Synaplan Integration for owncloud.online
 *
 * @copyright Copyright (c) 2026, Synaplan
 * @license Apache-2.0
 */

namespace OCA\OcoSynaplan\Listener;

use OCA\OcoSynaplan\Service\ReadScope;
use OCP\Files\Folder;
use OCP\Files\Node;

/**
 * When a folder is renamed or moved, an allowed folder may now be somewhere
 * else (itself or inside it). Report the new paths to Synaplan, which checks
 * a run against the paths it holds, for the person who moved it and for the
 * owner of a shared folder.
 */
class ReadScopeListener
{
    public function __construct(
        private ReadScope $readScope,
    ) {
    }

    public function handle(Node $target): void
    {
        if (!$target instanceof Folder) {
            return;
        }
        $people = [];
        try {
            if (preg_match('#^/([^/]+)/files/#', $target->getPath(), $match) === 1) {
                $people[] = $match[1];
            }
            // A storage without an owner answers null despite the signature,
            // which ends the search here.
            $people[] = $target->getOwner()->getUID();
        } catch (\Throwable) {
        }
        foreach (array_unique(array_filter($people, static fn (string $uid): bool => $uid !== '')) as $uid) {
            try {
                $this->readScope->follow($uid);
            } catch (\Throwable) {
                // A rename never fails because of this; the next save or
                // rename reports the paths again.
            }
        }
    }
}
