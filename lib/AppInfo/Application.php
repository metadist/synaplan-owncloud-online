<?php

declare(strict_types=1);

/**
 * Synaplan Integration for owncloud.online
 *
 * @copyright Copyright (c) 2026, Synaplan
 * @license Apache-2.0
 */

namespace OCA\OcoSynaplan\AppInfo;

use OCA\OcoSynaplan\Http\ClientService;
use OCA\OcoSynaplan\Service\SeenNotifications;
use OCA\OcoSynaplan\Service\SeenNotificationStore;
use OCP\AppFramework\App;
use OCP\Files\Config\IUserMountCache;
use OCP\Http\Client\IClientService;
use OCP\Lock\ILockingProvider;

/**
 * App container.
 *
 * ownCloud.online builds app classes by type-hint via reflection, but the app
 * container only knows a fixed list of OCP interfaces
 * (OC\AppFramework\DependencyInjection\DIContainer) and does not ask the
 * server container for others. Interfaces outside that list are registered
 * here, and the HTTP client service is one that can also send PATCH.
 */
class Application extends App
{
    public const APP_ID = 'oco_synaplan';

    /**
     * @param array<string, mixed> $urlParams
     */
    public function __construct(array $urlParams = [])
    {
        parent::__construct(self::APP_ID, $urlParams);

        $this->getContainer()->registerService(
            ILockingProvider::class,
            static fn () => \OC::$server->getLockingProvider()
        );
        $this->getContainer()->registerService(
            IUserMountCache::class,
            static fn () => \OC::$server->getMountProviderCollection()->getMountCache()
        );
        $this->getContainer()->registerService(
            IClientService::class,
            static fn () => new ClientService(
                \OC::$server->getHTTPClientService(),
                \OC::$server->getConfig(),
                \OC::$server->getCertificateManager(null),
            )
        );
        $this->getContainer()->registerAlias(SeenNotificationStore::class, SeenNotifications::class);
    }
}
