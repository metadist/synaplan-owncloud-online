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
use OCA\OcoSynaplan\Service\PlatformLinkService;
use OCP\BackgroundJob\IJobList;

/**
 * Tells Synaplan, with the person's own credential, that one of their files
 * changed, moved, went to the trash or came back, and queues the uploads of
 * the copies kept up to date that Synaplan asks for again. A Synaplan that
 * was not reached or was busy is asked three more times.
 */
class SourceEventsJob extends QueuedJob
{
    private const RETRIES = 3;

    private PlatformLinkService $links;
    private IJobList $jobs;

    /**
     * The server builds jobs in its own container, which cannot build this
     * app's services: they come from the app's container.
     */
    public function __construct(?PlatformLinkService $links = null, ?IJobList $jobs = null)
    {
        if ($links === null || $jobs === null) {
            $container = (new Application())->getContainer();
            $links = $container->query(PlatformLinkService::class);
            $jobs = $container->query(IJobList::class);
        }
        $this->links = $links;
        $this->jobs = $jobs;
    }

    /**
     * @param mixed $argument
     */
    protected function run($argument): void
    {
        if (!is_array($argument)) {
            return;
        }
        $uid = is_string($argument['uid'] ?? null) ? $argument['uid'] : '';
        $id = is_string($argument['id'] ?? null) ? $argument['id'] : '';
        $event = is_string($argument['event'] ?? null) ? $argument['event'] : '';
        $version = is_string($argument['version'] ?? null) ? $argument['version'] : '';
        $path = is_string($argument['path'] ?? null) ? $argument['path'] : '';
        $attempts = is_int($argument['attempts'] ?? null) ? $argument['attempts'] : 0;
        if ($uid === '' || $id === '' || $event === '') {
            return;
        }

        $answer = $this->links->reportSourceEvents([[
            'id' => $id,
            'event' => $event,
            'version' => $version,
            'path' => $path,
        ]], $uid);
        if (($answer['retry'] ?? false) === true) {
            if ($attempts < self::RETRIES) {
                $this->jobs->add(self::class, [
                    'uid' => $uid,
                    'id' => $id,
                    'event' => $event,
                    'version' => $version,
                    'path' => $path,
                    'attempts' => $attempts + 1,
                ]);
            }

            return;
        }
        foreach ($answer['reupload'] as $entry) {
            $this->jobs->add(ReuploadJob::class, ['uid' => $entry['external_id'], 'id' => $entry['id']]);
        }
    }
}
