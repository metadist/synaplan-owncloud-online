<?php

declare(strict_types=1);

/**
 * Synaplan Integration for owncloud.online
 *
 * @copyright Copyright (c) 2026, Synaplan
 * @license Apache-2.0
 */

namespace OCA\OcoSynaplan\AppInfo;

use OCP\AppFramework\App;
use OCP\Lock\ILockingProvider;

/**
 * App container.
 *
 * ownCloud.online builds app classes by type-hint via reflection, but the app
 * container only knows a fixed list of OCP interfaces
 * (OC\AppFramework\DependencyInjection\DIContainer) and does not ask the
 * server container for others. Interfaces outside that list are registered
 * here.
 */
class Application extends App
{
    public const APP_ID = 'oco_synaplan';

    public function __construct(array $urlParams = [])
    {
        parent::__construct(self::APP_ID, $urlParams);

        $this->getContainer()->registerService(
            ILockingProvider::class,
            static fn () => \OC::$server->getLockingProvider()
        );
    }
}
