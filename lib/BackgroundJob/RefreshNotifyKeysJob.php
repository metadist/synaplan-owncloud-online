<?php

declare(strict_types=1);

/**
 * Synaplan Integration for owncloud.online
 *
 * @copyright Copyright (c) 2026, Synaplan
 * @license Apache-2.0
 */

namespace OCA\OcoSynaplan\BackgroundJob;

use OC\BackgroundJob\TimedJob;
use OCA\OcoSynaplan\AppInfo\Application;
use OCA\OcoSynaplan\Service\NotifyKeyCache;
use OCA\OcoSynaplan\Service\PlatformLinkService;
use OCA\OcoSynaplan\Service\ReadScope;
use OCP\IConfig;
use OCP\Lock\LockedException;

/**
 * Hourly: fetches the keys Synaplan signs its notifications with, tells
 * Synaplan again where to call and what this app does, so a changed address
 * or a new version reaches it, and sends read settings whose report never
 * arrived.
 */
class RefreshNotifyKeysJob extends TimedJob
{
    private NotifyKeyCache $keys;
    private PlatformLinkService $links;
    private IConfig $config;
    private ReadScope $scope;

    /**
     * The server builds jobs in its own container, which looks for this app
     * by its namespace rather than its id: it cannot build the app's services
     * and passes the defaults instead, so they come from the app's container.
     */
    public function __construct(
        ?NotifyKeyCache $keys = null,
        ?PlatformLinkService $links = null,
        ?IConfig $config = null,
        ?ReadScope $scope = null,
    ) {
        if ($keys === null || $links === null || $config === null || $scope === null) {
            $container = (new Application())->getContainer();
            $keys = $container->query(NotifyKeyCache::class);
            $links = $container->query(PlatformLinkService::class);
            $config = $container->query(IConfig::class);
            $scope = $container->query(ReadScope::class);
        }
        $this->keys = $keys;
        $this->links = $links;
        $this->config = $config;
        $this->scope = $scope;
        $this->setInterval(3600);
    }

    /**
     * @param mixed $argument
     */
    protected function run($argument): void
    {
        $this->keys->refresh();
        try {
            $this->links->announce();
        } catch (\Throwable) {
            // The next hour tries again.
        }
        foreach ($this->config->getUsersForUserValue(Application::APP_ID, ReadScope::PENDING, '1') as $uid) {
            try {
                $this->scope->republish((string) $uid);
            } catch (LockedException) {
                // Still pending: the next hour tries again.
            }
        }
    }
}
