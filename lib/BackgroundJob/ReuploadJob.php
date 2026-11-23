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
use OCA\OcoSynaplan\Exception\FailureException;
use OCA\OcoSynaplan\Service\CloudStateService;
use OCA\OcoSynaplan\Service\SynaplanClient;
use OCA\OcoSynaplan\Service\UserAccountService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\ILogger;

/**
 * Uploads a file again that Synaplan keeps up to date and asked for after it
 * changed here: with the credential of the person it belongs to, an access
 * token for a person connected through OAuth, into the knowledge folder it
 * is in, over the copy Synaplan has. Synaplan asks for it once, so a passing
 * failure (a held grant whose token cannot be renewed now, Synaplan
 * unreachable or busy) is tried again a few times, later each time.
 *
 * ownCloud.online's job list runs a job at the next cron run only, so a
 * retry is a job of its own that stays in the list until it is due.
 */
class ReuploadJob extends Job
{
    private const MAX_UPLOAD_BYTES = 100 * 1024 * 1024;

    /** Seconds to wait before each retry; after the last, the new version is not sent. */
    public const RETRY_DELAYS = [60, 300, 900, 3600];

    private UserAccountService $userAccounts;
    private IRootFolder $rootFolder;
    private CloudStateService $cloudState;
    private SynaplanClient $synaplanClient;
    private ILogger $logger;
    private IJobList $jobs;
    private ITimeFactory $time;

    /**
     * The server builds jobs in its own container, which cannot build this
     * app's services: they come from the app's container.
     */
    public function __construct(
        ?UserAccountService $userAccounts = null,
        ?IRootFolder $rootFolder = null,
        ?CloudStateService $cloudState = null,
        ?SynaplanClient $synaplanClient = null,
        ?ILogger $logger = null,
        ?IJobList $jobs = null,
        ?ITimeFactory $time = null,
    ) {
        if ($userAccounts === null || $rootFolder === null || $cloudState === null || $synaplanClient === null || $logger === null || $jobs === null || $time === null) {
            $container = (new Application())->getContainer();
            $userAccounts = $container->query(UserAccountService::class);
            $rootFolder = $container->query(IRootFolder::class);
            $cloudState = $container->query(CloudStateService::class);
            $synaplanClient = $container->query(SynaplanClient::class);
            $logger = $container->query(ILogger::class);
            $jobs = $container->query(IJobList::class);
            $time = $container->query(ITimeFactory::class);
        }
        $this->userAccounts = $userAccounts;
        $this->rootFolder = $rootFolder;
        $this->cloudState = $cloudState;
        $this->synaplanClient = $synaplanClient;
        $this->logger = $logger;
        $this->jobs = $jobs;
        $this->time = $time;
    }

    /**
     * @param mixed $argument
     */
    protected function run($argument): void
    {
        if (is_array($argument) && is_int($argument['due'] ?? null) && $argument['due'] > $this->time->getTime()) {
            return;
        }
        $this->jobs->removeById($this->getId());
        if (!is_array($argument)) {
            return;
        }
        $uid = is_string($argument['uid'] ?? null) ? trim($argument['uid']) : '';
        $remoteId = is_string($argument['id'] ?? null) ? trim($argument['id']) : '';
        if ($uid === '' || !ctype_digit($remoteId)) {
            return;
        }
        $attempt = is_int($argument['attempt'] ?? null) ? $argument['attempt'] : 0;
        $retry = function () use ($uid, $remoteId, $attempt): void {
            $this->retry($uid, $remoteId, $attempt);
        };

        $key = $this->userAccounts->credentialFor($uid);
        if ($key === '') {
            if ($this->userAccounts->holdsCredential($uid)) {
                $retry();
            }

            return;
        }

        try {
            $userFolder = $this->rootFolder->getUserFolder($uid);
            $node = $userFolder->getById((int) $remoteId)[0] ?? null;
            if (!$node instanceof File || $node->getSize() > self::MAX_UPLOAD_BYTES) {
                return;
            }
            $etag = (string) $node->getEtag();
            $groupKey = $this->keptIn($remoteId, $etag, $key, $retry);
            if ($groupKey === null) {
                return;
            }
            $relative = $userFolder->getRelativePath($node->getPath()) ?? $node->getName();
            $this->synaplanClient->uploadFile(
                $node->getName(),
                $node->getContent(),
                $groupKey,
                'vectorize',
                ltrim($relative, '/'),
                $remoteId,
                $etag,
                true,
                $key,
                true,
            );
        } catch (\Throwable $e) {
            $this->logger->warning('Could not upload file {id} of {uid} again: {message}', [
                'app' => Application::APP_ID,
                'uid' => $uid,
                'id' => $remoteId,
                'message' => $e->getMessage(),
            ]);
            if (self::passing($e)) {
                $retry();
            }
        }
    }

    /**
     * No answer, a busy or failing Synaplan, or an answer it sent that
     * could not be read; not a refusal of the file or the key.
     */
    private static function passing(\Throwable $e): bool
    {
        if ($e instanceof FailureException) {
            return $e->passing;
        }
        $status = (int) $e->getCode();

        return $status === 0 || $status === 429 || $status >= 500;
    }

    private function retry(string $uid, string $remoteId, int $attempt): void
    {
        if ($attempt >= count(self::RETRY_DELAYS)) {
            $this->logger->warning('Gave up uploading file {id} of {uid} again', [
                'app' => Application::APP_ID,
                'uid' => $uid,
                'id' => $remoteId,
            ]);

            return;
        }
        $this->jobs->add(self::class, [
            'uid' => $uid,
            'id' => $remoteId,
            'attempt' => $attempt + 1,
            'due' => $this->time->getTime() + self::RETRY_DELAYS[$attempt],
        ]);
    }

    /**
     * The knowledge folder of the copy, while Synaplan still keeps it up to
     * date; null otherwise, and when Synaplan did not answer, after asking
     * for a retry.
     *
     * @param \Closure(): void $retry
     */
    private function keptIn(string $remoteId, string $etag, string $key, \Closure $retry): ?string
    {
        $status = $this->cloudState->status([['id' => $remoteId, 'version' => $etag]], $key);
        if (($status['unreachable'] ?? false) === true) {
            $retry();

            return null;
        }
        $item = $status['items'][0] ?? null;
        if (!is_array($item) || ($item['kept_up_to_date'] ?? false) !== true || ($item['state'] ?? 'none') === 'none') {
            return null;
        }
        $folder = $item['knowledge_folder'] ?? null;

        return is_array($folder) && is_string($folder['key'] ?? null) && $folder['key'] !== '' ? $folder['key'] : 'DEFAULT';
    }
}
