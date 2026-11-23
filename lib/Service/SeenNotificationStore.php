<?php

declare(strict_types=1);

/**
 * Synaplan Integration for owncloud.online
 *
 * @copyright Copyright (c) 2026, Synaplan
 * @license Apache-2.0
 */

namespace OCA\OcoSynaplan\Service;

/**
 * Remembers a notification id for ten minutes. True means it was already accepted.
 */
interface SeenNotificationStore
{
    public function alreadySeen(string $type, string $id): bool;
}
