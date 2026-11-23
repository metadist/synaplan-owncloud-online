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

/**
 * Hourly: fetches the keys Synaplan signs its notifications with, and tells
 * Synaplan again where to call and what this app does, so a changed address
 * or a new version reaches it.
 */
class RefreshNotifyKeysJob extends TimedJob
{
    private NotifyKeyCache $keys;
    private PlatformLinkService $links;

    /**
     * The server builds jobs in its own container, which looks for this app
     * by its namespace rather than its id: it cannot build the app's services
     * and passes the defaults instead, so they come from the app's container.
     */
    public function __construct(
        ?NotifyKeyCache $keys = null,
        ?PlatformLinkService $links = null,
    ) {
        if ($keys === null || $links === null) {
            $container = (new Application())->getContainer();
            $keys = $container->query(NotifyKeyCache::class);
            $links = $container->query(PlatformLinkService::class);
        }
        $this->keys = $keys;
        $this->links = $links;
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
    }
}
