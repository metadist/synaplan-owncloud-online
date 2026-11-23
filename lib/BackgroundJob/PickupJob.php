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
use OCA\OcoSynaplan\Service\FolderRead;

/**
 * Finishes a folder read that did not fit in the notification request.
 * FolderRead keeps the uploads still owed in the person's settings.
 */
class PickupJob extends QueuedJob
{
    private FolderRead $reads;

    /**
     * The server builds jobs in its own container, which looks for this app
     * by its namespace rather than its id: it cannot build the app's services
     * and passes the defaults instead, so they come from the app's container.
     */
    public function __construct(?FolderRead $reads = null)
    {
        $this->reads = $reads ?? (new Application())->getContainer()->query(FolderRead::class);
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
            $this->reads->finish($id, $uid);
        }
    }
}
