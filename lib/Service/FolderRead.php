<?php

declare(strict_types=1);

/**
 * Synaplan Integration for owncloud.online
 *
 * @copyright Copyright (c) 2026, Synaplan
 * @license Apache-2.0
 */

namespace OCA\OcoSynaplan\Service;

use Doctrine\DBAL\Result;
use OCA\OcoSynaplan\AppInfo\Application;
use OCA\OcoSynaplan\BackgroundJob\PickupJob;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\ILogger;
use OCP\IUserManager;

/**
 * The app side of a folder read: claim it, check the person's own setting,
 * list the folder, send what was asked for, and report.
 *
 * The whole answer has {@see self::BUDGET_SECONDS} inside the notification
 * request, claim, listing and every call to Synaplan included: Synaplan
 * waits 30 seconds for it. What is left is kept in the person's settings
 * under the read's id and finished by {@see PickupJob}: a job argument holds
 * 4000 characters, and a large folder leaves more than that.
 */
class FolderRead
{
    public const BUDGET_SECONDS = 20;
    private const LISTING_CHUNK = 500;
    private const CALL_SECONDS = 30;
    private const UPLOAD_SECONDS = 120;

    public function __construct(
        private PlatformLinkService $links,
        private ReadScope $scope,
        private PersonFiles $files,
        private IUserManager $users,
        private IJobList $jobs,
        private ILogger $logger,
        private IConfig $config,
        private IDBConnection $db,
        private ITimeFactory $time,
    ) {
    }

    public function handle(string $id, string $externalId, int $budgetSeconds = self::BUDGET_SECONDS): void
    {
        // A claimed read ends in a result call, even when Synaplan stops
        // waiting for this answer.
        ignore_user_abort(true);
        try {
            $this->start($id, $externalId, $this->time->getTime() + max(1, $budgetSeconds));
        } catch (\Throwable $e) {
            $this->logger->warning('A folder read could not be answered: {message}', [
                'app' => Application::APP_ID,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * What a read left when the request ran out of time: the whole read when
     * that was before Synaplan said what to send, the uploads still owed, or
     * only the result. It ends in a result call whatever fails on the way.
     */
    public function finish(string $id, string $uid): void
    {
        $key = self::pendingKey($id);
        $raw = $this->savedValue($uid, $key);
        $this->config->deleteUserValue($uid, Application::APP_ID, $key);
        $pending = $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($pending)) {
            return;
        }
        if (is_array($pending['result'] ?? null)) {
            $this->result($id, $uid, $pending['result'], null);

            return;
        }
        $sent = (int) ($pending['sent'] ?? 0);
        $listed = (int) ($pending['listed'] ?? 0);
        $skipped = is_array($pending['skipped'] ?? null) ? array_values($pending['skipped']) : [];
        try {
            if (is_array($pending['selector'] ?? null)) {
                $this->read($id, $uid, $pending['selector'], null);

                return;
            }
            $left = [];
            foreach (is_array($pending['left'] ?? null) ? $pending['left'] : [] as $item) {
                if (is_array($item)) {
                    $left[] = ['id' => (string) ($item['id'] ?? ''), 'version' => (string) ($item['version'] ?? '')];
                }
            }
            $this->send($id, $uid, $this->files->folderOf($uid), $left, $listed, $sent, $skipped, null);
        } catch (\Throwable $e) {
            $this->logger->warning('A folder read could not be finished: {message}', [
                'app' => Application::APP_ID,
                'message' => $e->getMessage(),
            ]);
            $this->report($id, $uid, 'failed', $listed, $sent, $skipped, 'unreachable', null);
        }
    }

    /**
     * The read is claimed and answered with the key of the person the signed
     * notification names; the claim must name the same person.
     */
    private function start(string $id, string $externalId, int $deadline): void
    {
        $uid = $this->uid($externalId);
        if ($uid === null || !$this->links->canActFor($uid)) {
            return;
        }
        $claim = $this->links->claimPickup($id, $uid, max(1, min(self::CALL_SECONDS, $deadline - $this->time->getTime())));
        if (isset($claim['status_code']) && (int) $claim['status_code'] !== 200) {
            return;
        }
        if ($this->uid((string) ($claim['external_id'] ?? '')) !== $uid) {
            $this->report($id, $uid, 'failed', 0, 0, [], 'not_found', $deadline);

            return;
        }
        // Once claimed, Synaplan waits for a result until its timeout: every
        // way out of the read has to report one.
        try {
            $this->read($id, $uid, is_array($claim['selector'] ?? null) ? $claim['selector'] : [], $deadline);
        } catch (\Throwable $e) {
            $this->logger->warning('A claimed folder read failed: {message}', [
                'app' => Application::APP_ID,
                'message' => $e->getMessage(),
            ]);
            $this->report($id, $uid, 'failed', 0, 0, [], 'unreachable', $deadline);
        }
    }

    /**
     * @param array<mixed> $selector
     * @param int|null $deadline when the request's time runs out; null in the background job
     */
    private function read(string $id, string $uid, array $selector, ?int $deadline): void
    {
        $userFolder = $this->files->folderOf($uid);
        $folder = $this->folder($userFolder, $selector);
        if (!$folder instanceof Folder) {
            $this->report($id, $uid, 'failed', 0, 0, [], 'not_found', $deadline);

            return;
        }
        $relative = $this->relativeFolder($userFolder, $folder);
        if (!$this->scope->mayRead($uid, $relative)) {
            $this->report($id, $uid, 'failed', 0, 0, [], 'outside_scope', $deadline);

            return;
        }

        $since = self::since($selector['changed_since'] ?? null);
        $recursive = ($selector['recursive'] ?? false) === true;
        $files = $this->filesIn($uid, $userFolder, $folder, $recursive, $since, $deadline);
        if ($files === null) {
            // The job reads the folder again; Synaplan repeats what it
            // decided for files it was already told of.
            $this->later($id, $uid, ['selector' => $selector]);

            return;
        }
        // Synaplan reads at most 500 items of one listing call and keeps
        // its decisions across calls (`07` §4).
        $listing = ['upload' => [], 'skip' => []];
        foreach (array_chunk($files, self::LISTING_CHUNK) ?: [[]] as $chunk) {
            $seconds = $this->secondsFor($deadline, self::CALL_SECONDS);
            if ($seconds === null) {
                $this->later($id, $uid, ['selector' => $selector]);

                return;
            }
            $answer = $this->links->submitPickupListing($id, $uid, $chunk, $seconds);
            if (isset($answer['status_code']) && (int) $answer['status_code'] !== 200) {
                $this->report($id, $uid, 'failed', count($files), 0, [], 'unreachable', $deadline);

                return;
            }
            foreach (['upload', 'skip'] as $key) {
                if (is_array($answer[$key] ?? null)) {
                    $listing[$key] = array_merge($listing[$key], array_values($answer[$key]));
                }
            }
        }

        $byId = [];
        foreach ($files as $file) {
            $byId[$file['id']] = $file;
        }
        $skipped = [];
        foreach ($listing['skip'] as $skip) {
            if (!is_array($skip)) {
                continue;
            }
            $remoteId = (string) ($skip['id'] ?? '');
            if (!isset($byId[$remoteId])) {
                continue;
            }
            $skipped[] = [
                'id' => $remoteId,
                'name' => $byId[$remoteId]['name'],
                'reason' => (string) ($skip['reason'] ?? ''),
            ];
        }
        $upload = [];
        foreach ($listing['upload'] as $remoteId) {
            if (is_string($remoteId) && isset($byId[$remoteId])) {
                $upload[] = ['id' => $remoteId, 'version' => $byId[$remoteId]['version']];
            }
        }

        $this->send($id, $uid, $userFolder, $upload, count($files), 0, $skipped, $deadline);
    }

    /**
     * Send the files Synaplan asked for while the request has time, and
     * leave the rest to the job.
     *
     * @param list<array{id: string, version: string}> $upload
     * @param list<array{id: string, name: string, reason: string}> $skipped
     */
    private function send(string $id, string $uid, Folder $userFolder, array $upload, int $listed, int $sent, array $skipped, ?int $deadline): void
    {
        $left = [];
        foreach ($upload as $item) {
            $outcome = $left === [] && $this->secondsFor($deadline, self::UPLOAD_SECONDS) !== null
                ? $this->uploadOne($id, $uid, $userFolder, $item, $deadline)
                : false;
            if ($outcome === false) {
                $left[] = $item;
            } elseif ($outcome === null) {
                $sent++;
            } else {
                $skipped[] = $outcome;
            }
        }
        if ($left !== []) {
            $this->later($id, $uid, ['left' => $left, 'sent' => $sent, 'listed' => $listed, 'skipped' => $skipped]);

            return;
        }
        $this->close($id, $uid, $listed, $sent, $skipped, $deadline);
    }

    /**
     * Send one listed file. The person's setting and the file's version are
     * checked again here, at the moment of reading: both may have changed
     * since the listing, above all while a background job was waiting. A
     * file that fails is skipped, so the rest still goes and the read still
     * reports.
     *
     * @param array{id: string, version: string} $item
     * @return array{id: string, name: string, reason: string}|false|null null when the file was stored, false when the request has no time left to send it
     */
    private function uploadOne(string $pickupId, string $uid, Folder $userFolder, array $item, ?int $deadline): array|false|null
    {
        $name = $item['id'];
        try {
            $nodes = $item['id'] !== '' ? $userFolder->getById((int) $item['id']) : [];
            $node = $nodes[0] ?? null;
            if (!$node instanceof File) {
                return ['id' => $item['id'], 'name' => $name, 'reason' => 'unreadable'];
            }
            $name = $node->getName();
            if (!$this->scope->mayRead($uid, $this->parentFolder($userFolder, $node))) {
                return ['id' => $item['id'], 'name' => $name, 'reason' => 'outside_scope'];
            }
            if ((string) $node->getEtag() !== $item['version']) {
                return ['id' => $item['id'], 'name' => $name, 'reason' => 'changed_since_listing'];
            }
            $mime = $node->getMimeType();
            $bytes = (string) $node->getContent();
        } catch (\Throwable) {
            return ['id' => $item['id'], 'name' => $name, 'reason' => 'unreadable'];
        }
        // Finding, checking and reading the file count against the time too.
        $seconds = $this->secondsFor($deadline, self::UPLOAD_SECONDS);
        if ($seconds === null) {
            return false;
        }
        try {
            $stored = $this->links->uploadPickupFile(
                $pickupId,
                $uid,
                $item['id'],
                $item['version'],
                $bytes,
                $name,
                $mime,
                $seconds,
            );
        } catch (\Throwable) {
            return ['id' => $item['id'], 'name' => $name, 'reason' => 'unreachable'];
        }
        if (($stored['status_code'] ?? 0) === 200) {
            return null;
        }

        return [
            'id' => $item['id'],
            'name' => $name,
            'reason' => (string) ($stored['error'] ?? 'unreadable'),
        ];
    }

    /**
     * Leave the rest of the read to {@see PickupJob}.
     *
     * @param array<string, mixed> $pending
     */
    private function later(string $id, string $uid, array $pending): void
    {
        $this->config->setUserValue(
            $uid,
            Application::APP_ID,
            self::pendingKey($id),
            (string) json_encode($pending, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        );
        $this->jobs->add(PickupJob::class, ['id' => $id, 'uid' => $uid]);
    }

    /**
     * How long the next call to Synaplan may take: its own limit in the job,
     * no longer than the request has left in it, and null when that is a
     * second or less.
     *
     * @phpstan-impure
     */
    private function secondsFor(?int $deadline, int $limit): ?int
    {
        if ($deadline === null) {
            return $limit;
        }
        $left = $deadline - $this->time->getTime();

        return $left > 1 ? min($limit, $left) : null;
    }

    private function parentFolder(Folder $userFolder, File $file): string
    {
        $relative = $userFolder->getRelativePath($file->getPath());
        $relative = is_string($relative) ? trim($relative, '/') : $file->getName();
        $parent = str_contains($relative, '/') ? dirname($relative) : '';

        return $parent === '.' ? '' : $parent;
    }

    /**
     * @param list<array{id: string, name: string, reason: string}> $skipped
     */
    private function close(string $id, string $uid, int $listed, int $sent, array $skipped, ?int $deadline): void
    {
        $missing = array_filter(
            $skipped,
            static fn (array $row): bool => $row['reason'] !== 'type_not_read',
        );
        $this->report($id, $uid, $missing === [] ? 'done' : 'partial', $listed, $sent, $skipped, null, $deadline);
    }

    /**
     * @param list<array{id: string, name: string, reason: string}> $skipped
     */
    private function report(string $id, string $uid, string $status, int $listed, int $sent, array $skipped, ?string $error, ?int $deadline): void
    {
        $body = [
            'status' => $status,
            'listed' => $listed,
            'sent' => $sent,
            'skipped' => $skipped,
        ];
        if ($error !== null) {
            $body['error_code'] = $error;
        }
        $this->result($id, $uid, $body, $deadline);
    }

    /**
     * Synaplan waits for the result past the notification request, so a
     * result the request has no time left for is sent by the job.
     *
     * @param array<string, mixed> $body
     */
    private function result(string $id, string $uid, array $body, ?int $deadline): void
    {
        $seconds = $this->secondsFor($deadline, self::CALL_SECONDS);
        if ($seconds === null) {
            $this->later($id, $uid, ['result' => $body]);

            return;
        }
        try {
            $this->links->reportPickupResult($id, $uid, $body, $seconds);
        } catch (\Throwable $e) {
            $this->logger->warning('Could not report a folder read: {message}', [
                'app' => Application::APP_ID,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private static function pendingKey(string $id): string
    {
        return 'pickup_pending_' . md5($id);
    }

    /**
     * Read from the table: the settings cache of a background run that
     * already read this person's settings predates what the request stored.
     */
    private function savedValue(string $uid, string $key): string
    {
        $query = $this->db->getQueryBuilder();
        $query->select('configvalue')
            ->from('preferences')
            ->where($query->expr()->eq('userid', $query->createNamedParameter($uid)))
            ->andWhere($query->expr()->eq('appid', $query->createNamedParameter(Application::APP_ID)))
            ->andWhere($query->expr()->eq('configkey', $query->createNamedParameter($key)));
        $result = $query->execute();
        if (!$result instanceof Result) {
            return '';
        }
        $value = $result->fetchOne();
        $result->free();

        return is_string($value) ? $value : '';
    }

    private function uid(string $externalId): ?string
    {
        if ($externalId !== '' && $this->users->get($externalId) !== null) {
            return $externalId;
        }
        $suffix = str_contains($externalId, ':') ? substr($externalId, (int) strrpos($externalId, ':') + 1) : '';

        return $suffix !== '' && $this->users->get($suffix) !== null ? $suffix : null;
    }

    /**
     * The folder a selector names. A selector naming no folder, or the root
     * by its path or by its id, is refused even when the person lets
     * Synaplan read all their files, and so is a path that cannot be cleaned
     * (`Invoices//2026`, `Invoices/../HR`): none is read as the whole home
     * folder.
     *
     * @param array<mixed> $selector
     */
    private function folder(Folder $userFolder, array $selector): ?Folder
    {
        $id = is_string($selector['folder_id'] ?? null) ? $selector['folder_id'] : '';
        if ($id !== '' && ctype_digit($id)) {
            $nodes = $userFolder->getById((int) $id);
            $node = $nodes[0] ?? null;

            // ownCloud finds the home folder by its own id too.
            return $node instanceof Folder && $node->getPath() !== $userFolder->getPath() ? $node : null;
        }
        $path = ReadScope::clean(trim(is_string($selector['folder_path'] ?? null) ? $selector['folder_path'] : ''));
        if ($path === '') {
            return null;
        }
        try {
            $node = $userFolder->get($path);
        } catch (NotFoundException) {
            return null;
        }

        return $node instanceof Folder ? $node : null;
    }

    private function relativeFolder(Folder $userFolder, Folder $folder): string
    {
        if ($folder->getPath() === $userFolder->getPath()) {
            return '';
        }
        $relative = $userFolder->getRelativePath($folder->getPath());

        return ReadScope::clean(is_string($relative) ? $relative : $folder->getName());
    }

    /**
     * A recursive read only enters the sub-folders the person's setting
     * allows, as a listing already tells Synaplan names, paths and sizes.
     * Null when the request's time ran out first.
     *
     * @return list<array{id: string, version: string, name: string, folder: string, size: int, mime: string}>|null
     */
    private function filesIn(string $uid, Folder $userFolder, Folder $folder, bool $recursive, int $since, ?int $deadline): ?array
    {
        // A slow claim or folder lookup can have spent the time already.
        if ($this->secondsFor($deadline, 1) === null) {
            return null;
        }
        $found = [];
        foreach ($folder->getDirectoryListing() as $node) {
            if ($this->secondsFor($deadline, 1) === null) {
                return null;
            }
            if ($node instanceof Folder) {
                if (!$recursive || !$this->scope->mayRead($uid, $this->relativeFolder($userFolder, $node))) {
                    continue;
                }
                $inner = $this->filesIn($uid, $userFolder, $node, true, $since, $deadline);
                if ($inner === null) {
                    return null;
                }
                $found = array_merge($found, $inner);
                continue;
            }
            if (!$node instanceof File || $node->getMTime() < $since) {
                continue;
            }
            $relative = $userFolder->getRelativePath($node->getPath());
            $relative = is_string($relative) ? trim($relative, '/') : $node->getName();
            $parent = str_contains($relative, '/') ? dirname($relative) : '';
            $found[] = [
                'id' => (string) $node->getId(),
                'version' => (string) $node->getEtag(),
                'name' => $node->getName(),
                'folder' => $parent === '.' ? '' : $parent,
                'size' => (int) $node->getSize(),
                'mime' => $node->getMimeType(),
            ];
        }

        return $found;
    }

    /**
     * "7d", "12h" or "2w" as a cut-off. Anything else means no cut-off.
     */
    public static function since(mixed $value): int
    {
        if (!is_string($value) || preg_match('/^(\d+)([hdw])$/', $value, $match) !== 1) {
            return 0;
        }

        return time() - (int) $match[1] * ['h' => 3600, 'd' => 86400, 'w' => 604800][$match[2]];
    }
}
