<?php

declare(strict_types=1);

/**
 * Synaplan Integration for owncloud.online
 *
 * @copyright Copyright (c) 2026, Synaplan
 * @license Apache-2.0
 */

namespace OCA\OcoSynaplan\Controller;

use OCA\OcoSynaplan\AppInfo\Application;
use OCA\OcoSynaplan\Exception\FailureException;
use OCA\OcoSynaplan\Http\RespondsToFailures;
use OCA\OcoSynaplan\Service\CopyDelivery;
use OCA\OcoSynaplan\Service\OwnWrites;
use OCA\OcoSynaplan\Service\SynaplanClient;
use OCA\OcoSynaplan\Service\SynaplanFolders;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\IRootFolder;
use OCP\ILogger;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Controller for media generation (image/video) and saving to ownCloud.online.
 */
class MediaController extends Controller
{
    use RespondsToFailures;

    public function __construct(
        IRequest $request,
        private SynaplanClient $synaplanClient,
        private IRootFolder $rootFolder,
        private IUserSession $userSession,
        private SynaplanFolders $folders,
        private ILogger $logger,
        private OwnWrites $ownWrites,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * Generate an image or video via Synaplan.
     *
     * @NoAdminRequired
     */
    public function generate(): JSONResponse
    {
        $body = file_get_contents('php://input');
        $data = $body !== false ? json_decode($body, true) : [];

        if (!is_array($data)) {
            $data = [];
        }

        $prompt = trim((string) ($data['prompt'] ?? ''));
        $type = trim((string) ($data['type'] ?? ''));
        $modelId = isset($data['modelId']) ? (int) $data['modelId'] : null;

        if ($prompt === '') {
            return new JSONResponse(['success' => false, 'error' => 'Prompt is required'], Http::STATUS_BAD_REQUEST);
        }

        if (!in_array($type, ['image', 'video'], true)) {
            return new JSONResponse(
                ['success' => false, 'error' => 'Type must be "image" or "video"'],
                Http::STATUS_BAD_REQUEST,
            );
        }

        try {
            $result = $this->synaplanClient->generateMedia($prompt, $type, $modelId);

            return new JSONResponse([
                'success' => true,
                'file' => $result['file'] ?? null,
                'provider' => $result['provider'] ?? 'unknown',
                'model' => $result['model'] ?? 'unknown',
            ]);
        } catch (\Exception $e) {
            return $this->failed($e, 'Media generation failed');
        }
    }

    /**
     * Save a generated file from Synaplan where copies from Synaplan land:
     * the person's chosen folder or Synaplan, in the sub-folder for its kind.
     *
     * @NoAdminRequired
     */
    public function save(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            return new JSONResponse(['success' => false, 'error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        $body = file_get_contents('php://input');
        $data = $body !== false ? json_decode($body, true) : [];

        if (!is_array($data)) {
            $data = [];
        }

        $mediaUrl = trim((string) ($data['mediaUrl'] ?? ''));
        $filename = trim((string) ($data['filename'] ?? ''));

        if ($mediaUrl === '' || $filename === '' || !SynaplanClient::isSynaplanPath($mediaUrl)) {
            return new JSONResponse(
                ['success' => false, 'error' => 'mediaUrl and filename are required'],
                Http::STATUS_BAD_REQUEST,
            );
        }

        $filename = SynaplanFolders::fileName($filename);
        $uid = $user->getUID();

        try {
            $content = $this->synaplanClient->downloadFile($mediaUrl, CopyDelivery::MAX_BYTES);
        } catch (\Exception $e) {
            return $this->failed($e, 'Media save failed');
        }

        try {
            $userFolder = $this->rootFolder->getUserFolder($uid);
            $file = SynaplanFolders::write($this->folders->kindFolder($userFolder, $uid, $filename), $filename, $content);
        } catch (\Throwable $e) {
            return $this->failed(FailureException::fromWrite($e), 'Media save failed');
        }
        $this->ownWrites->note($file, time());

        $path = ltrim((string) ($userFolder->getRelativePath($file->getPath()) ?? $file->getName()), '/');
        $folder = dirname($path);

        return new JSONResponse(['success' => true, 'path' => $path, 'folder' => $folder === '.' ? '' : $folder]);
    }

    /**
     * Proxy a Synaplan media file through the ownCloud.online server.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function proxy(string $url = ''): Http\Response
    {
        if ($url === '' || !SynaplanClient::isSynaplanPath($url)) {
            return new JSONResponse(['error' => 'url parameter is required'], Http::STATUS_BAD_REQUEST);
        }

        try {
            $content = $this->synaplanClient->downloadFile($url);

            $ext = strtolower(pathinfo($url, PATHINFO_EXTENSION));
            $mimeType = match ($ext) {
                'png' => 'image/png',
                'jpg', 'jpeg' => 'image/jpeg',
                'gif' => 'image/gif',
                'webp' => 'image/webp',
                'mp4' => 'video/mp4',
                'webm' => 'video/webm',
                default => 'application/octet-stream',
            };

            $filename = basename($url);

            return new DataDownloadResponse($content, $filename, $mimeType);
        } catch (\Exception $e) {
            return $this->failed($e, 'Media proxy failed');
        }
    }
}
