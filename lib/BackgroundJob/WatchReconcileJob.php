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
use OCA\OcoSynaplan\Service\WatchReconcile;

/**
 * Reconciles each active watch once per interval (an hour by default, the
 * app value watch_reconcile_minutes, 15 to 1440). The job wakes every 15
 * minutes; each watch carries when it was last reconciled.
 */
class WatchReconcileJob extends TimedJob
{
    private const BUDGET_SECONDS = 480;

    private SynaplanConfig $synaplanConfig;
    private WatchReconcile $reconcile;

    /**
     * The server builds jobs in its own container, which cannot build the
     * app's services and passes the defaults instead, so they come from the
     * app's container.
     */
    public function __construct(
        ?SynaplanConfig $synaplanConfig = null,
        ?WatchReconcile $reconcile = null,
    ) {
        if ($synaplanConfig === null || $reconcile === null) {
            $container = (new Application())->getContainer();
            $synaplanConfig = $container->query(SynaplanConfig::class);
            $reconcile = $container->query(WatchReconcile::class);
        }
        $this->synaplanConfig = $synaplanConfig;
        $this->reconcile = $reconcile;
        $this->setInterval(900);
    }

    /**
     * @param mixed $argument
     */
    protected function run($argument): void
    {
        if (!$this->synaplanConfig->isRegistered()) {
            return;
        }
        $this->reconcile->run(time(), microtime(true) + self::BUDGET_SECONDS, false);
    }
}
