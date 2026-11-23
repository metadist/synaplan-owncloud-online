<?php

declare(strict_types=1);

/**
 * Synaplan Integration for owncloud.online
 *
 * @copyright Copyright (c) 2026, Synaplan
 * @license Apache-2.0
 */

namespace OCA\OcoSynaplan\BackgroundJob;

use OC\BackgroundJob\QueuedJob;
use OCA\OcoSynaplan\AppInfo\Application;
use OCA\OcoSynaplan\Service\CopyDelivery;
use OCA\OcoSynaplan\Service\SynaplanConfig;
use OCP\ILogger;

/**
 * Sends the result of a copy `dev_hold_inbound` held back, at the first cron
 * run after the switch is cleared.
 */
class DeliveryResultJob extends QueuedJob
{
    private CopyDelivery $copies;
    private SynaplanConfig $synaplanConfig;

    /**
     * The server builds jobs in its own container, which looks for this app
     * by its namespace rather than its id: it cannot build the app's services
     * and passes the defaults instead, so they come from the app's container.
     */
    public function __construct(
        ?CopyDelivery $copies = null,
        ?SynaplanConfig $synaplanConfig = null,
    ) {
        if ($copies === null || $synaplanConfig === null) {
            $container = (new Application())->getContainer();
            $copies = $container->query(CopyDelivery::class);
            $synaplanConfig = $container->query(SynaplanConfig::class);
        }
        $this->copies = $copies;
        $this->synaplanConfig = $synaplanConfig;
    }

    /**
     * While the switch is on the job stays in the list untouched, so the
     * next cron run looks again; adding it anew would run it again in this one.
     *
     * @param \OCP\BackgroundJob\IJobList $jobList
     */
    public function execute($jobList, ?ILogger $logger = null): void
    {
        if ($this->synaplanConfig->isDevSwitchOn(SynaplanConfig::DEV_HOLD_INBOUND)) {
            return;
        }
        parent::execute($jobList, $logger);
    }

    /**
     * @param mixed $argument
     */
    protected function run($argument): void
    {
        if (!is_array($argument) || !is_array($argument['result'] ?? null)) {
            return;
        }
        $id = (string) ($argument['id'] ?? '');
        $uid = (string) ($argument['uid'] ?? '');
        if ($id !== '' && $uid !== '') {
            $this->copies->sendResult($id, $uid, $argument['result']);
        }
    }
}
