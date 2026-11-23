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
use OCA\OcoSynaplan\Service\CloudStateService;
use OCA\OcoSynaplan\Service\SynaplanClient;
use OCA\OcoSynaplan\Service\UserAccountService;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\ILogger;

/**
 * Uploads a file again that Synaplan keeps up to date and asked for after it
 * changed here: with the credential of the person it belongs to, an access
 * token for a person connected through OAuth, into the knowledge folder it
 * is in, over the copy Synaplan has.
 */
class ReuploadJob extends QueuedJob
{
    private const MAX_UPLOAD_BYTES = 100 * 1024 * 1024;

    private UserAccountService $userAccounts;
    private IRootFolder $rootFolder;
    private CloudStateService $cloudState;
    private SynaplanClient $synaplanClient;
    private ILogger $logger;

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
    ) {
        if ($userAccounts === null || $rootFolder === null || $cloudState === null || $synaplanClient === null || $logger === null) {
            $container = (new Application())->getContainer();
            $userAccounts = $container->query(UserAccountService::class);
            $rootFolder = $container->query(IRootFolder::class);
            $cloudState = $container->query(CloudStateService::class);
            $synaplanClient = $container->query(SynaplanClient::class);
            $logger = $container->query(ILogger::class);
        }
        $this->userAccounts = $userAccounts;
        $this->rootFolder = $rootFolder;
        $this->cloudState = $cloudState;
        $this->synaplanClient = $synaplanClient;
        $this->logger = $logger;
    }

    /**
     * @param mixed $argument
     */
    protected function run($argument): void
    {
        if (!is_array($argument)) {
            return;
        }
        $uid = is_string($argument['uid'] ?? null) ? trim($argument['uid']) : '';
        $remoteId = is_string($argument['id'] ?? null) ? trim($argument['id']) : '';
        if ($uid === '' || !ctype_digit($remoteId)) {
            return;
        }
        $key = $this->userAccounts->credentialFor($uid);
        if ($key === '') {
            return;
        }

        try {
            $userFolder = $this->rootFolder->getUserFolder($uid);
            $node = $userFolder->getById((int) $remoteId)[0] ?? null;
            if (!$node instanceof File || $node->getSize() > self::MAX_UPLOAD_BYTES) {
                return;
            }
            $etag = (string) $node->getEtag();
            $groupKey = $this->keptIn($remoteId, $etag, $key);
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
        }
    }

    /**
     * The knowledge folder of the copy, while Synaplan still keeps it up to
     * date; null otherwise.
     */
    private function keptIn(string $remoteId, string $etag, string $key): ?string
    {
        $status = $this->cloudState->status([['id' => $remoteId, 'version' => $etag]], $key);
        $item = $status['items'][0] ?? null;
        if (!is_array($item) || ($item['kept_up_to_date'] ?? false) !== true || ($item['state'] ?? 'none') === 'none') {
            return null;
        }
        $folder = $item['knowledge_folder'] ?? null;

        return is_array($folder) && is_string($folder['key'] ?? null) && $folder['key'] !== '' ? $folder['key'] : 'DEFAULT';
    }
}
