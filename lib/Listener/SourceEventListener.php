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
 * each file and folder inside it too.
 */
class SourceEventListener
{
    public const DEV_MUTE = 'dev_mute_events';

    /**
     * What a delete reports once it went through, by the path deleted: the
     * files inside a folder are gone by then.
     *
     * @var array<string, list<array{id: string, event: string, version: string, path: string, uid: string, owner: string}>>
     */
    private array $deleting = [];

    public function __construct(
        private SynaplanConfig $synaplanConfig,
        private IConfig $config,
        private IJobList $jobs,
        private IRootFolder $rootFolder,
        private IUserSession $userSession,
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
            $this->queue($this->payloads($node, 'changed', false));
        }
    }

    public function renamed(Node $target): void
    {
        if (!$this->muted()) {
            $this->queue($this->payloads($target, 'moved', true));
        }
    }

    public function deleting(Node $node): void
    {
        if (!$this->muted()) {
            $this->deleting[$node->getPath()] = $this->payloads($node, 'deleted', true);
        }
    }

    public function deleted(Node $node): void
    {
        $path = $node->getPath();
        $payloads = $this->deleting[$path] ?? [];
        unset($this->deleting[$path]);
        $this->queue($payloads);
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
        $this->queue($this->payloads($node, 'restored', true));
    }

    private function muted(): bool
    {
        return $this->config->getAppValue(Application::APP_ID, self::DEV_MUTE, '0') === '1'
            || !$this->synaplanConfig->isPerUserAccountsEnabled()
            || $this->synaplanConfig->getLinkInstanceId() === '';
    }

    /**
     * @param list<array{id: string, event: string, version: string, path: string, uid: string, owner: string}> $payloads
     */
    private function queue(array $payloads): void
    {
        foreach ($payloads as $payload) {
            foreach ($this->perPerson($payload) as $job) {
                $this->jobs->add(SourceEventsJob::class, $job);
            }
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
     * @return list<array{id: string, event: string, version: string, path: string, uid: string, owner: string}>
     */
    private function payloads(Node $node, string $kind, bool $withFolders): array
    {
        $one = $this->payload($node, $kind, $withFolders);
        if ($one === null) {
            return [];
        }
        $out = [$one];
        if ($withFolders && $node instanceof Folder) {
            array_push($out, ...$this->descendants($node, $kind));
        }

        return $out;
    }

    /**
     * @return list<array{id: string, event: string, version: string, path: string, uid: string, owner: string}>
     */
    private function descendants(Folder $folder, string $kind): array
    {
        $out = [];
        try {
            foreach ($folder->getDirectoryListing() as $child) {
                $row = $this->payload($child, $kind, true);
                if ($row !== null) {
                    $out[] = $row;
                }
                if ($child instanceof Folder) {
                    array_push($out, ...$this->descendants($child, $kind));
                }
            }
        } catch (\Throwable) {
            // What could be listed is reported.
        }

        return $out;
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
