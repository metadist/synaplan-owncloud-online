<?php

declare(strict_types=1);

/**
 * Synaplan Integration for owncloud.online
 *
 * @copyright Copyright (c) 2026, Synaplan
 * @license Apache-2.0
 */

namespace OCA\OcoSynaplan\Listener;

use OCA\OcoSynaplan\AppInfo\Application;
use OCA\OcoSynaplan\BackgroundJob\SourceEventsJob;
use OCA\OcoSynaplan\Service\ShareRecipients;
use OCA\OcoSynaplan\Service\SourceEventQueue;
use OCA\OcoSynaplan\Service\SynaplanConfig;
use OCP\BackgroundJob\IJobList;
use OCP\Files\FileInfo;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IConfig;
use OCP\IUserSession;

/**
 * When a file changes, moves, goes to the trash or comes back from it, a
 * source event is queued for Synaplan, which applies it to the copies it
 * keeps of the file; for a folder that moves, goes or comes back, one for
 * each file and folder inside it too. Each person's events wait in their
 * own queue, in the order they happened. The request reads no node below a
 * folder: its files come from the file cache, or, for a move, from the
 * background job.
 */
class SourceEventListener
{
    public const DEV_MUTE = 'dev_mute_events';

    /**
     * The event a delete reports once it went through, by the path deleted:
     * the node is gone by then.
     *
     * @var array<string, array{id: string, event: string, version: string, path: string, uid: string, owner: string}>
     */
    private array $deleting = [];

    public function __construct(
        private SynaplanConfig $synaplanConfig,
        private IConfig $config,
        private IJobList $jobs,
        private IRootFolder $rootFolder,
        private IUserSession $userSession,
        private SourceEventQueue $queue,
        private ShareRecipients $recipients,
    ) {
    }

    /**
     * The trash bin restores outside the person's files, which emits no
     * rename, and tells its own hook, which calls a static slot.
     *
     * @param array<string, mixed> $params
     */
    public static function onRestore(array $params): void
    {
        try {
            /** @var self $listener */
            $listener = (new Application())->getContainer()->query(self::class);
            $listener->restored($params);
        } catch (\Throwable) {
        }
    }

    public function written(Node $node): void
    {
        if (!$this->muted()) {
            $this->report($node, $this->payload($node, 'changed', false), SourceEventQueue::now());
        }
    }

    public function renamed(Node $target): void
    {
        if ($this->muted()) {
            return;
        }
        $payload = $this->payload($target, 'moved', true);
        $moverOnly = $payload !== null && self::isMountRoot($target);
        if ($moverOnly) {
            $payload['owner'] = '';
        }
        $this->report($target, $payload, SourceEventQueue::now(), [], $moverOnly);
    }

    /**
     * Before a deletion the shares still say who else holds the file: their
     * rows go in now, held until the deletion went through, as do the files
     * below a folder for everyone, from the file cache.
     */
    public function deleting(Node $node): void
    {
        if ($this->muted()) {
            return;
        }
        $payload = $this->payload($node, 'deleted', true);
        if ($payload === null) {
            return;
        }
        $this->deleting[$node->getPath()] = $payload;
        $ord = SourceEventQueue::now();
        $id = (int) $payload['id'];
        $told = array_values(array_unique(array_filter([$payload['uid'], $payload['owner']], static fn (string $uid): bool => $uid !== '')));
        $recipients = $this->recipients->of($node, $told);
        if ($node instanceof Folder) {
            foreach ([...$told, ...$recipients] as $uid) {
                $this->queueDescendants($uid, $id, 'deleted', $ord, SourceEventQueue::STEP_HELD, $id);
            }
        }
        foreach ($recipients as $uid) {
            $this->queue->add($uid, ['id' => $payload['id'], 'event' => 'deleted', 'version' => $payload['version'], 'path' => ''], $ord, SourceEventQueue::STEP_HELD, $id);
        }
    }

    public function deleted(Node $node): void
    {
        $path = $node->getPath();
        $payload = $this->deleting[$path] ?? null;
        unset($this->deleting[$path]);
        if ($payload === null) {
            return;
        }
        $ord = SourceEventQueue::now();
        $this->report($node, $payload, $ord, $this->queue->release((int) $payload['id']));
    }

    /**
     * @param array<string, mixed> $params the trash bin's `filePath`, in the files of the person restoring
     */
    public function restored(array $params): void
    {
        $user = $this->userSession->getUser();
        $filePath = is_string($params['filePath'] ?? null) ? $params['filePath'] : '';
        if ($user === null || trim($filePath, '/') === '' || $this->muted()) {
            return;
        }
        try {
            $node = $this->rootFolder->getUserFolder($user->getUID())->get(trim($filePath, '/'));
        } catch (\Throwable) {
            return;
        }
        $this->report($node, $this->payload($node, 'restored', true), SourceEventQueue::now());
    }

    private function muted(): bool
    {
        return $this->config->getAppValue(Application::APP_ID, self::DEV_MUTE, '0') === '1'
            || !$this->synaplanConfig->isPerUserAccountsEnabled()
            || $this->synaplanConfig->getLinkInstanceId() === '';
    }

    /**
     * Queue a node's event in each person's queue, with the order of when it
     * happened, and wake each person's job. A moved folder leaves a walk for
     * the job; a restored folder's files come from the file cache. The job
     * tells the people the file is shared with, through a share row ahead
     * of the event.
     *
     * @param array{id: string, event: string, version: string, path: string, uid: string, owner: string}|null $payload
     * @param list<string> $released the people whose held rows of a deletion are ready now
     * @param bool $moverOnly whether the event changed the paths of the person who acted only
     */
    private function report(Node $node, ?array $payload, int $ord, array $released = [], bool $moverOnly = false): void
    {
        if ($payload === null) {
            return;
        }
        $rows = $this->perPerson($payload);
        if ($rows !== [] && $payload['event'] !== 'deleted' && !$moverOnly) {
            $this->queue->add($rows[0]['uid'], $rows[0], $ord, SourceEventQueue::STEP_SHARE);
        }
        $people = [];
        foreach ($rows as $row) {
            $this->queue->add($row['uid'], $row, $ord);
            $people[$row['uid']] = true;
            if (!$node instanceof Folder) {
                continue;
            }
            if ($payload['event'] === 'moved') {
                $this->queue->add($row['uid'], $row, $ord, SourceEventQueue::STEP_WALK);
            } elseif ($payload['event'] === 'restored') {
                $this->queueDescendants($row['uid'], (int) $row['id'], 'restored', $ord, SourceEventQueue::STEP_SEND);
            }
        }
        foreach ($released as $uid) {
            $people[$uid] = true;
        }
        foreach (array_keys($people) as $uid) {
            $this->jobs->add(SourceEventsJob::class, ['uid' => (string) $uid]);
        }
    }

    private function queueDescendants(string $uid, int $folderId, string $event, int $ord, string $step, int $walked = 0): void
    {
        try {
            $this->queue->addDescendants($uid, $folderId, $event, $ord, $step, $walked);
        } catch (\Throwable) {
            // Best effort: the folder's own event still goes out.
        }
    }

    /**
     * Synaplan applies a source event to the copies of the person whose
     * credential reports it, and a move renames the copy to the path it
     * reports. A file is reported for the person in whose files it changed,
     * with their path, and, for a shared file, for its owner too, with the
     * owner's path.
     *
     * @param array{id: string, event: string, version: string, path: string, uid: string, owner: string} $payload
     * @return list<array{uid: string, id: string, event: string, version: string, path: string}>
     */
    private function perPerson(array $payload): array
    {
        $event = ['id' => $payload['id'], 'event' => $payload['event'], 'version' => $payload['version']];
        $jobs = [];
        if ($payload['uid'] !== '') {
            $jobs[] = ['uid' => $payload['uid']] + $event + ['path' => $payload['path']];
        }
        if ($payload['owner'] !== '' && $payload['owner'] !== $payload['uid']) {
            $jobs[] = ['uid' => $payload['owner']] + $event + ['path' => $this->ownerPath($payload['owner'], (int) $payload['id'])];
        }

        return $jobs;
    }

    /**
     * The path as sent, or '' when it does not fit Synaplan's 255 bytes:
     * Synaplan renames its copy to the path it gets and leaves it alone on
     * '', and a cut path would name another file or split a character.
     */
    private static function fitting(string $path): string
    {
        return strlen($path) <= 255 ? $path : '';
    }

    /**
     * Where the owner sees a shared node, '' when it is gone.
     */
    private function ownerPath(string $owner, int $id): string
    {
        try {
            $node = $this->rootFolder->getUserFolder($owner)->getById($id)[0] ?? null;
        } catch (\Throwable) {
            return '';
        }

        return $node instanceof Node ? self::fitting(self::relativePath($node->getPath()) ?? '') : '';
    }

    /**
     * @return array{id: string, event: string, version: string, path: string, uid: string, owner: string}|null
     */
    private function payload(Node $node, string $kind, bool $withFolders): ?array
    {
        try {
            if (!$withFolders && $node->getType() === FileInfo::TYPE_FOLDER) {
                return null;
            }
            $id = (int) $node->getId();
            $relative = self::relativePath($node->getPath());
            if ($id <= 0 || $relative === null) {
                return null;
            }
        } catch (\Throwable) {
            return null;
        }
        try {
            // A storage without an owner answers null despite the signature.
            $owner = (string) $node->getOwner()->getUID();
        } catch (\Throwable) {
            $owner = '';
        }
        try {
            $etag = (string) $node->getEtag();
        } catch (\Throwable) {
            $etag = '';
        }

        return [
            'id' => (string) $id,
            'event' => $kind,
            'version' => substr($etag, 0, 255),
            'path' => self::fitting($relative),
            'uid' => preg_match('#^/([^/]+)/files/#', $node->getPath(), $match) === 1 ? $match[1] : '',
            'owner' => $owner,
        ];
    }

    /**
     * The root of a mount, such as a share as its recipient sees it: moving
     * it renames it for that person only.
     */
    private static function isMountRoot(Node $node): bool
    {
        try {
            return $node->getInternalPath() === '';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * The path in the person's files; null for the trash, versions,
     * uploads, thumbnails and anything else outside them.
     */
    private static function relativePath(string $fullPath): ?string
    {
        if (preg_match('#^/[^/]+/files/(.+)$#', $fullPath, $match) !== 1) {
            return null;
        }
        $relative = trim($match[1], '/');

        return $relative !== '' ? $relative : null;
    }
}
