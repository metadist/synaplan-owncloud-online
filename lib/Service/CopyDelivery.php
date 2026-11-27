<?php

declare(strict_types=1);

/**
 * Synaplan Integration for owncloud.online
 *
 * @copyright Copyright (c) 2026, Synaplan
 * @license Apache-2.0
 */

namespace OCA\OcoSynaplan\Service;

use OCA\OcoSynaplan\AppInfo\Application;
use OCA\OcoSynaplan\BackgroundJob\DeliveryResultJob;
use OCA\OcoSynaplan\BackgroundJob\DeliveryRetryJob;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Files\AlreadyExistsException;
use OCP\Files\EntityTooLargeException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\ForbiddenException;
use OCP\Files\NotEnoughSpaceException;
use OCP\Files\NotFoundException;
use OCP\Files\NotPermittedException;
use OCP\ICacheFactory;
use OCP\ILogger;
use OCP\IUserManager;

/**
 * The app side of a copy: claim it, download it, write it into the person's
 * files and report what happened. Synaplan also reads the same report from
 * the notification answer.
 */
class CopyDelivery
{
    /** Remember a file this app wrote, so a later copy may replace only that. */
    private const OWN_WRITE_TTL = 600;

    /** Synaplan's cap for a copy; it refuses larger files before sending. */
    public const MAX_BYTES = 100 * 1024 * 1024;

    /** How long Synaplan keeps a copy claimable, its ticket and its download path. */
    public const TICKET_TTL = 300;

    public function __construct(
        private PlatformLinkService $links,
        private PersonFiles $files,
        private SynaplanFolders $folders,
        private IUserManager $users,
        private ICacheFactory $cacheFactory,
        private Notices $notices,
        private ILogger $logger,
        private IJobList $jobs,
        private ITimeFactory $time,
        private SynaplanConfig $synaplanConfig,
        private OwnWrites $ownWrites,
    ) {
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    public function handle(string $id, string $uid): array
    {
        // Synaplan waits 30 seconds for the answer, then takes the result
        // call instead; a slow download must not stop when it hangs up.
        ignore_user_abort(true);

        return $this->deliver($id, $uid, null);
    }

    /**
     * Send a result `dev_hold_inbound` held back.
     *
     * @param array<string, mixed> $result
     */
    public function sendResult(string $id, string $uid, array $result): void
    {
        try {
            $this->links->reportDelivery($id, $uid, $result);
        } catch (\Throwable $e) {
            $this->logger->warning('Could not report a Synaplan copy: {message}', [
                'app' => Application::APP_ID,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The background try after a failed claim. Past the ticket's life the
     * claim can only fail, so Synaplan's sweep reports the copy instead.
     */
    public function retry(string $id, string $uid, int $since): void
    {
        if ($this->time->getTime() - $since > self::TICKET_TTL) {
            $this->logger->info('A Synaplan copy expired before it could be claimed again', [
                'app' => Application::APP_ID,
            ]);

            return;
        }
        $this->deliver($id, $uid, $since);
    }

    /**
     * @return array{status: int, body: array<string, mixed>}
     */
    private function deliver(string $id, string $uid, ?int $retriedSince): array
    {
        try {
            $claim = $this->links->claimDelivery($id, $uid);
        } catch (\Throwable $e) {
            // Synaplan is unreachable or the person's credential failed. A
            // 202 promises a result call, so try once more in the background.
            $this->logger->warning('Could not claim a Synaplan copy: {message}', [
                'app' => Application::APP_ID,
                'message' => $e->getMessage(),
            ]);
            if ($retriedSince === null) {
                $this->jobs->add(DeliveryRetryJob::class, ['id' => $id, 'uid' => $uid, 'since' => $this->time->getTime()]);
            }

            return ['status' => 202, 'body' => ['state' => 'pending']];
        }
        if (isset($claim['status_code']) && (int) $claim['status_code'] !== 200) {
            // Another worker claimed it, or it expired. Leave it pending.
            return ['status' => 202, 'body' => ['state' => 'pending']];
        }

        $result = (string) ($claim['external_id'] ?? '') === $uid
            ? $this->write($id, $claim)
            : ['status' => 'failed', 'error_code' => 'not_found'];
        if ($this->synaplanConfig->isDevSwitchOn(SynaplanConfig::DEV_HOLD_INBOUND)) {
            // Synaplan sees the copy as pending, then unconfirmed once its
            // sweep runs, and takes the late result after the switch is cleared.
            try {
                $this->jobs->add(DeliveryResultJob::class, ['id' => $id, 'uid' => $uid, 'result' => $result]);

                return ['status' => 202, 'body' => ['state' => 'pending']];
            } catch (\InvalidArgumentException) {
                // A job argument holds 4000 characters; a longer path is reported now.
            }
        }
        $this->sendResult($id, $uid, $result);

        return ['status' => 200, 'body' => $result];
    }

    /**
     * @param array<string, mixed> $claim
     * @return array<string, mixed>
     */
    private function write(string $id, array $claim): array
    {
        $uid = (string) ($claim['external_id'] ?? '');
        if ($uid === '' || $this->users->get($uid) === null) {
            return ['status' => 'failed', 'error_code' => 'not_found'];
        }
        if ((int) ($claim['size'] ?? 0) > self::MAX_BYTES) {
            return ['status' => 'failed', 'error_code' => 'too_large'];
        }
        $bytes = $this->links->downloadDelivery($id, (string) ($claim['download_path'] ?? ''), $uid);
        if ($bytes === null) {
            return ['status' => 'failed', 'error_code' => 'unreachable'];
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            return ['status' => 'failed', 'error_code' => 'too_large'];
        }

        try {
            $userFolder = $this->files->folderOf($uid);
            $name = SynaplanFolders::fileName((string) ($claim['name'] ?? 'file'));
            $target = is_array($claim['target'] ?? null) ? $claim['target'] : [];
            $folder = $this->folder($userFolder, $uid, $name, $target);
            SynaplanFolders::assertFits($folder, strlen($bytes));
            $replace = ($claim['on_conflict'] ?? '') === 'replace_own';
            $file = $this->place($folder, $uid, $name, $bytes, $replace);
        } catch (\Throwable $e) {
            $this->logger->warning('Could not write a Synaplan copy for {uid}: {message}', [
                'app' => Application::APP_ID,
                'uid' => $uid,
                'message' => $e->getMessage(),
            ]);

            return ['status' => 'failed', 'error_code' => self::failureCode($e)];
        }

        $relative = ltrim((string) ($userFolder->getRelativePath($file->getPath()) ?? $file->getName()), '/');
        $this->rememberOwnWrite($uid, (int) $file->getId());
        $this->notices->copied(
            $uid,
            $file->getName(),
            dirname($relative) === '.' ? '' : dirname($relative),
            (int) $file->getId(),
        );

        return [
            'status' => 'delivered',
            'remote_path' => '/' . $relative,
            'remote_id' => (string) $file->getId(),
            'open_url' => $this->links->openUrl((int) $file->getId()),
        ];
    }

    /**
     * @param array<string, mixed> $target
     */
    private function folder(Folder $userFolder, string $uid, string $name, array $target): Folder
    {
        $kind = (string) ($target['kind'] ?? 'kind_folder');
        $ref = (string) ($target['ref'] ?? '');
        if (($kind === 'folder_id' || $kind === 'next_to') && $ref !== '') {
            $nodes = $userFolder->getById((int) $ref);
            $node = $nodes[0] ?? null;
            if ($node instanceof Folder) {
                return $node;
            }
            if ($node instanceof File) {
                return $node->getParent();
            }
            throw new NotFoundException('The folder Synaplan named is not in this account.');
        }

        return $this->folders->kindFolder($userFolder, $uid, $name);
    }

    private function place(Folder $folder, string $uid, string $name, string $bytes, bool $replaceOwn): File
    {
        if ($replaceOwn && $folder->nodeExists($name)) {
            $existing = $folder->get($name);
            if ($existing instanceof File && $this->wrote($uid, (int) $existing->getId())) {
                $existing->putContent($bytes);
                $this->ownWrites->note($existing, $this->time->getTime());

                return $existing;
            }
        }
        $file = SynaplanFolders::write($folder, $name, $bytes);
        $this->ownWrites->note($file, $this->time->getTime());

        return $file;
    }

    /**
     * The failure code Synaplan shows the person (`03` §6).
     */
    private static function failureCode(\Throwable $e): string
    {
        return match (true) {
            $e instanceof NotFoundException => 'not_found',
            $e instanceof NotEnoughSpaceException => 'quota_exceeded',
            $e instanceof NotPermittedException, $e instanceof ForbiddenException => 'forbidden',
            $e instanceof AlreadyExistsException => 'conflict',
            $e instanceof EntityTooLargeException => 'too_large',
            default => 'unreachable',
        };
    }

    private function rememberOwnWrite(string $uid, int $fileId): void
    {
        $this->cacheFactory->create(Application::APP_ID)
            ->set($this->ownKey($uid, $fileId), '1', self::OWN_WRITE_TTL);
    }

    private function wrote(string $uid, int $fileId): bool
    {
        return $this->cacheFactory->create(Application::APP_ID)
            ->get($this->ownKey($uid, $fileId)) !== null;
    }

    private function ownKey(string $uid, int $fileId): string
    {
        return 'own_' . hash('sha256', $uid) . '_' . $fileId;
    }
}
