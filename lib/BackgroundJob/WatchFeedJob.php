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
use OCA\OcoSynaplan\Service\SynaplanConfig;
use OCA\OcoSynaplan\Service\WatchFeed;
use OCA\OcoSynaplan\Service\WatchReconcile;
use OCA\OcoSynaplan\Service\WatchSync;

/**
 * Every cron run: bring the watch index up to date, give new watches their
 * first pass, and send the files that have been quiet for the quiet period.
 * One run stops starting new work after eight minutes.
 */
class WatchFeedJob extends TimedJob
{
    private const BUDGET_SECONDS = 480;

    private SynaplanConfig $synaplanConfig;
    private WatchSync $sync;
    private WatchReconcile $reconcile;
    private WatchFeed $feed;

    /**
     * The server builds jobs in its own container, which cannot build the
     * app's services and passes the defaults instead, so they come from the
     * app's container.
     */
    public function __construct(
        ?SynaplanConfig $synaplanConfig = null,
        ?WatchSync $sync = null,
        ?WatchReconcile $reconcile = null,
        ?WatchFeed $feed = null,
    ) {
        if ($synaplanConfig === null || $sync === null || $reconcile === null || $feed === null) {
            $container = (new Application())->getContainer();
            $synaplanConfig = $container->query(SynaplanConfig::class);
            $sync = $container->query(WatchSync::class);
            $reconcile = $container->query(WatchReconcile::class);
            $feed = $container->query(WatchFeed::class);
        }
        $this->synaplanConfig = $synaplanConfig;
        $this->sync = $sync;
        $this->reconcile = $reconcile;
        $this->feed = $feed;
        $this->setInterval(60);
    }

    /**
     * @param mixed $argument
     */
    protected function run($argument): void
    {
        if (!$this->synaplanConfig->isRegistered()) {
            return;
        }
        $deadline = microtime(true) + self::BUDGET_SECONDS;
        $now = time();
        $this->sync->run($now);
        $this->reconcile->run($now, $deadline, true);
        $this->feed->run(time(), $deadline);
    }
}
