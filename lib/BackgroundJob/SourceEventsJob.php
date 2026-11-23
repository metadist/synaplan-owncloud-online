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
use OCA\OcoSynaplan\Service\ShareRecipients;
use OCA\OcoSynaplan\Service\SourceEventQueue;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\ILogger;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/**
 * Tells Synaplan, with a person's own credential, of the changes to their
 * files in the order they happened, up to 100 to a call, and queues the
 * uploads of the copies kept up to date that Synaplan asks for again. Events
 * Synaplan could not take are tried again after 1, 5, 15 and 60 minutes,
 * holding back the person's events after them, and given up after that. The
 * files of a moved folder are queued here, 500 at a time, ahead of the
 * events after the move; those of a deleted folder wait until the deletion
 * went through, for ten minutes at most.
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
    /** Files of a moved folder queued per step of its walk. */
    public const WALK_PAGE = 500;
    /** Seconds after which the held rows of a deletion that never happened go. */
    public const HELD_FOR = 600;

    private PlatformLinkService $links;
    private IJobList $jobs;
    private SourceEventQueue $queue;
    private ILockingProvider $locks;
    private ILogger $logger;
    private ITimeFactory $time;
    private IRootFolder $rootFolder;
    private ShareRecipients $recipients;

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
        ?IRootFolder $rootFolder = null,
        ?ShareRecipients $recipients = null,
    ) {
        if ($links === null || $jobs === null || $queue === null || $locks === null || $logger === null || $time === null
            || $rootFolder === null || $recipients === null) {
            $container = (new Application())->getContainer();
            $links = $container->query(PlatformLinkService::class);
            $jobs = $container->query(IJobList::class);
            $queue = $container->query(SourceEventQueue::class);
            $locks = $container->query(ILockingProvider::class);
            $logger = $container->query(ILogger::class);
            $time = $container->query(ITimeFactory::class);
            $rootFolder = $container->query(IRootFolder::class);
            $recipients = $container->query(ShareRecipients::class);
        }
        $this->links = $links;
        $this->jobs = $jobs;
        $this->queue = $queue;
        $this->locks = $locks;
        $this->logger = $logger;
        $this->time = $time;
        $this->rootFolder = $rootFolder;
        $this->recipients = $recipients;
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
            $head = $rows[0];
            if ($head['step'] === SourceEventQueue::STEP_WALK) {
                $this->walk($head);
            } elseif ($head['step'] === SourceEventQueue::STEP_HELD) {
                if ($now * 1_000_000 - $head['ord'] < self::HELD_FOR * 1_000_000) {
                    return;
                }
                $this->queue->removeHeld($uid, $head['walked']);
            } elseif ($head['step'] === SourceEventQueue::STEP_SHARE) {
                $this->share($head);
                $this->queue->remove([$head['seq']]);
            } elseif ($head['step'] === SourceEventQueue::STEP_SEND) {
                $this->send($uid, $this->leadingSends($rows), $now);
            } else {
                $this->queue->remove([$head['seq']]);
            }
            if ($this->time->getTime() >= $until) {
                return;
            }
        }
    }

    /**
     * Queue the event for each person the file is shared with, with the path
     * they see it at, in their own queue and with the same order.
     *
     * @param QueuedEvent $row
     */
    private function share(array $row): void
    {
        $node = $this->nodeOf($row['uid'], $row['file_id']);
        if ($node === null) {
            return;
        }
        try {
            // A storage without an owner answers null despite the signature.
            $owner = (string) $node->getOwner()->getUID();
        } catch (\Throwable) {
            $owner = '';
        }
        $event = ['id' => (string) $row['file_id'], 'event' => $row['event'], 'version' => $row['version']];
        foreach ($this->recipients->of($node, [$row['uid'], $owner]) as $uid) {
            $theirs = $this->nodeOf($uid, $row['file_id']);
            if ($theirs === null) {
                continue;
            }
            $path = preg_match('#^/[^/]+/files/(.+)$#', $theirs->getPath(), $match) === 1 ? trim($match[1], '/') : '';
            $this->queue->add($uid, $event + ['path' => strlen($path) <= 255 ? $path : ''], $row['ord']);
            if ($theirs instanceof Folder && $row['event'] === 'moved') {
                $this->queue->add($uid, $event + ['path' => strlen($path) <= 255 ? $path : ''], $row['ord'], SourceEventQueue::STEP_WALK);
            } elseif ($theirs instanceof Folder && $row['event'] === 'restored') {
                $this->queue->addDescendants($uid, $row['file_id'], 'restored', $row['ord'], SourceEventQueue::STEP_SEND);
            }
            $this->jobs->add(self::class, ['uid' => $uid]);
        }
    }

    private function nodeOf(string $uid, int $id): ?Node
    {
        try {
            $node = $this->rootFolder->getUserFolder($uid)->getById($id)[0] ?? null;
        } catch (\Throwable) {
            return null;
        }

        return $node instanceof Node ? $node : null;
    }

    /**
     * Queue the next page of a moved folder's files and folders, with the
     * paths the person sees them at, behind the walk and with its order.
     *
     * @param QueuedEvent $walk
     */
    private function walk(array $walk): void
    {
        $page = $this->queue->descendants($walk['file_id'], $walk['walked'], self::WALK_PAGE);
        foreach ($page as $entry) {
            $path = $walk['path'] === '' ? '' : $walk['path'] . '/' . $entry['path'];
            $this->queue->add($walk['uid'], [
                'id' => (string) $entry['id'],
                'event' => $walk['event'],
                'version' => substr($entry['etag'], 0, 255),
                'path' => strlen($path) <= 255 ? $path : '',
            ], $walk['ord']);
        }
        if (count($page) < self::WALK_PAGE) {
            $this->queue->remove([$walk['seq']]);

            return;
        }
        $this->queue->walked($walk['seq'], $page[count($page) - 1]['id']);
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
     * @param list<QueuedEvent> $batch
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
