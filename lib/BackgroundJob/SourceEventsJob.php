<?php

declare(strict_types=1);

/**
 * Synaplan Integration for owncloud.online
 *
 * @copyright Copyright (c) 2026, Synaplan
 * @license Apache-2.0
 */

namespace OCA\OcoSynaplan\BackgroundJob;

use OC\BackgroundJob\Job;
use OCA\OcoSynaplan\AppInfo\Application;
use OCA\OcoSynaplan\Service\PlatformLinkService;
use OCA\OcoSynaplan\Service\SourceEventQueue;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\ILogger;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/**
 * Tells Synaplan, with a person's own credential, of the changes to their
 * files in the order they happened, up to 100 to a call, and queues the
 * uploads of the copies kept up to date that Synaplan asks for again. Events
 * Synaplan could not take are tried again after 1, 5, 15 and 60 minutes,
 * holding back the person's events after them, and given up after that.
 *
 * ownCloud.online cannot schedule a job for later: each person's job stays
 * in the list while their queue holds events, and a run that finds the head
 * waiting returns at once.
 *
 * @phpstan-import-type QueuedEvent from SourceEventQueue
 */
class SourceEventsJob extends Job
{
    /** Events per call; Synaplan takes up to 500. */
    public const BATCH = 100;
    /** Seconds to wait before each retry; after the last, the events are given up. */
    public const RETRY_DELAYS = [60, 300, 900, 3600];
    /** Seconds of work per run before the job leaves the rest to its next run. */
    private const BUDGET = 60;

    private PlatformLinkService $links;
    private IJobList $jobs;
    private SourceEventQueue $queue;
    private ILockingProvider $locks;
    private ILogger $logger;
    private ITimeFactory $time;

    /**
     * The server builds jobs in its own container, which cannot build this
     * app's services: they come from the app's container.
     */
    public function __construct(
        ?PlatformLinkService $links = null,
        ?IJobList $jobs = null,
        ?SourceEventQueue $queue = null,
        ?ILockingProvider $locks = null,
        ?ILogger $logger = null,
        ?ITimeFactory $time = null,
    ) {
        if ($links === null || $jobs === null || $queue === null || $locks === null || $logger === null || $time === null) {
            $container = (new Application())->getContainer();
            $links = $container->query(PlatformLinkService::class);
            $jobs = $container->query(IJobList::class);
            $queue = $container->query(SourceEventQueue::class);
            $locks = $container->query(ILockingProvider::class);
            $logger = $container->query(ILogger::class);
            $time = $container->query(ITimeFactory::class);
        }
        $this->links = $links;
        $this->jobs = $jobs;
        $this->queue = $queue;
        $this->locks = $locks;
        $this->logger = $logger;
        $this->time = $time;
    }

    /**
     * @param mixed $argument
     */
    protected function run($argument): void
    {
        $uid = is_array($argument) && is_string($argument['uid'] ?? null) ? $argument['uid'] : '';
        if ($uid === '') {
            // By its id: remove() with no argument would take every person's job.
            $this->jobs->removeById($this->getId());

            return;
        }
        if ($argument !== ['uid' => $uid]) {
            $this->takeOverQueuedEvent($uid, $argument);
            $this->jobs->removeById($this->getId());
            $this->jobs->add(self::class, ['uid' => $uid]);
        }

        // The lock table keeps keys of up to 64 characters.
        $lock = Application::APP_ID . '/source-events/' . md5($uid);
        try {
            $this->locks->acquireLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
        } catch (LockedException) {
            return;
        }
        try {
            $this->drain($uid);
        } finally {
            $this->locks->releaseLock($lock, ILockingProvider::LOCK_EXCLUSIVE);
        }
    }

    private function drain(string $uid): void
    {
        $until = $this->time->getTime() + self::BUDGET;
        while (true) {
            $rows = $this->queue->head($uid, self::BATCH);
            if ($rows === []) {
                $this->leave($uid);

                return;
            }
            $now = $this->time->getTime();
            if ($rows[0]['due'] > $now) {
                return;
            }
            $batch = $this->leadingSends($rows);
            if ($batch === []) {
                return;
            }
            $this->send($uid, $batch, $now);
            if ($this->time->getTime() >= $until) {
                return;
            }
        }
    }

    /**
     * With the person's queue empty, their job leaves the list, and comes
     * back at once for an event a request queued meanwhile.
     */
    private function leave(string $uid): void
    {
        $this->jobs->remove(self::class, ['uid' => $uid]);
        if ($this->queue->head($uid, 1) !== []) {
            $this->jobs->add(self::class, ['uid' => $uid]);
        }
    }

    /**
     * @param list<QueuedEvent> $rows
     * @return list<QueuedEvent>
     */
    private function leadingSends(array $rows): array
    {
        $batch = [];
        foreach ($rows as $row) {
            if ($row['step'] !== SourceEventQueue::STEP_SEND) {
                break;
            }
            $batch[] = $row;
        }

        return $batch;
    }

    /**
     * @param non-empty-list<QueuedEvent> $batch
     */
    private function send(string $uid, array $batch, int $now): void
    {
        $answer = $this->links->reportSourceEvents(array_map(static fn (array $row): array => [
            'id' => (string) $row['file_id'],
            'event' => $row['event'],
            'version' => $row['version'],
            'path' => $row['path'],
        ], $batch), $uid);
        $seqs = array_map(static fn (array $row): int => $row['seq'], $batch);

        if (($answer['retry'] ?? false) === true) {
            $attempts = $batch[0]['attempts'] + 1;
            if ($attempts <= count(self::RETRY_DELAYS)) {
                $this->queue->retryLater($seqs, $attempts, $now + self::RETRY_DELAYS[$attempts - 1]);

                return;
            }
            $this->logger->warning('Gave up telling Synaplan of {count} file events of {uid}', [
                'app' => Application::APP_ID,
                'count' => count($seqs),
                'uid' => $uid,
            ]);
        }
        $this->queue->remove($seqs);
        foreach ($answer['reupload'] as $entry) {
            $this->jobs->add(ReuploadJob::class, ['uid' => $entry['external_id'], 'id' => $entry['id']]);
        }
    }

    /**
     * A job queued before the queue carried one event in its argument.
     *
     * @param array<mixed> $argument
     */
    private function takeOverQueuedEvent(string $uid, array $argument): void
    {
        $id = is_string($argument['id'] ?? null) ? $argument['id'] : '';
        $event = is_string($argument['event'] ?? null) ? $argument['event'] : '';
        if ($id === '' || $event === '') {
            return;
        }
        $this->queue->add($uid, [
            'id' => $id,
            'event' => $event,
            'version' => is_string($argument['version'] ?? null) ? $argument['version'] : '',
            'path' => is_string($argument['path'] ?? null) ? $argument['path'] : '',
        ], SourceEventQueue::now());
    }
}
