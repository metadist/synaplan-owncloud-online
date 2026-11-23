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

/**
 * Claims a copy once more after its claim failed.
 */
class DeliveryRetryJob extends QueuedJob
{
    private CopyDelivery $copies;

    /**
     * The server builds jobs in its own container, which looks for this app
     * by its namespace rather than its id: it cannot build the app's services
     * and passes the defaults instead, so they come from the app's container.
     */
    public function __construct(?CopyDelivery $copies = null)
    {
        $this->copies = $copies ?? (new Application())->getContainer()->query(CopyDelivery::class);
    }

    /**
     * @param mixed $argument
     */
    protected function run($argument): void
    {
        if (!is_array($argument)) {
            return;
        }
        $id = (string) ($argument['id'] ?? '');
        $uid = (string) ($argument['uid'] ?? '');
        if ($id !== '' && $uid !== '') {
            $this->copies->retry($id, $uid, (int) ($argument['since'] ?? 0));
        }
    }
}
