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
use OCA\OcoSynaplan\Service\CloudStateService;
use OCA\OcoSynaplan\Service\SynaplanClient;
use OCA\OcoSynaplan\Service\SynaplanConfig;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;

/**
 * What Synaplan made of the person's files, for the bird on each row of a
 * folder, the Synaplan tab of one file, and the list of searchable files on
 * the app page. Nothing is shown where Synaplan does not tell it.
 */
class CloudStatusController extends Controller
{
    private const MAX_ITEMS = 500;
    private const MAX_FIELD = 255;

    public function __construct(
        IRequest $request,
        private CloudStateService $cloudState,
        private SynaplanClient $synaplanClient,
        private SynaplanConfig $synaplanConfig,
        private IRootFolder $rootFolder,
        private IUserSession $userSession,
        private IURLGenerator $urlGenerator,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * The state of up to 500 files of a folder view, each with the version it shows.
     *
     * @NoAdminRequired
     */
    public function status(): JSONResponse
    {
        $items = $this->readItems();
        if ($items === [] || $this->userSession->getUser() === null || !$this->cloudState->available()) {
            return new JSONResponse(['success' => true, 'enabled' => false, 'items' => []]);
        }
        $answer = $this->cloudState->status($items);
        if (!$answer['enabled']) {
            return new JSONResponse(['success' => true, 'enabled' => false, 'items' => []]);
        }

        return new JSONResponse([
            'success' => true,
            'enabled' => true,
            'unreachable' => (bool) ($answer['unreachable'] ?? false),
            'items' => $answer['items'],
            'glyphs' => $this->cloudState->glyphOverrides(),
            'openSynaplanUrl' => $this->synaplanConfig->getPublicBaseUrl() . '/files',
        ]);
    }

    /**
     * One file of the person for the Synaplan tab: whether it is searchable,
     * in which knowledge folder, and whether it stays up to date.
     *
     * @NoAdminRequired
     */
    public function detail(int $fileId): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null || !$this->cloudState->available()) {
            return new JSONResponse(['success' => true, 'enabled' => false]);
        }
        $node = $this->rootFolder->getUserFolder($user->getUID())->getById($fileId)[0] ?? null;
        if (!$node instanceof File && !$node instanceof Folder) {
            return new JSONResponse(['success' => false, 'error' => 'not_found'], 404);
        }
        $answer = $this->cloudState->status([['id' => (string) $fileId, 'version' => (string) $node->getEtag()]]);
        if (!$answer['enabled']) {
            return new JSONResponse(['success' => true, 'enabled' => false]);
        }
        if ((bool) ($answer['unreachable'] ?? false)) {
            return new JSONResponse(['success' => true, 'enabled' => true, 'unreachable' => true]);
        }

        $item = $answer['items'][0] ?? [];
        $state = is_string($item['state'] ?? null) ? $item['state'] : 'none';
        $synaplanFileId = is_int($item['file_id'] ?? null) ? $item['file_id'] : null;
        if ($synaplanFileId !== null) {
            $remote = $this->cloudState->detail($synaplanFileId);
            if (is_array($remote)) {
                $item = array_merge($remote, $item);
            }
        }
        $folder = is_array($item['knowledge_folder'] ?? null) ? $item['knowledge_folder'] : null;

        return new JSONResponse([
            'success' => true,
            'enabled' => true,
            'unreachable' => false,
            'kind' => $node instanceof Folder ? 'folder' : 'file',
            'fileId' => $fileId,
            'name' => $node->getName(),
            'modified' => $node->getMTime(),
            'state' => $state,
            'knowledgeFolder' => $folder !== null ? (string) ($folder['name'] ?? $folder['key'] ?? '') : null,
            'keptUpToDate' => (bool) ($item['kept_up_to_date'] ?? false),
            'synaplanFileId' => $synaplanFileId,
            'glyphs' => $this->cloudState->glyphOverrides(),
            'openSynaplanUrl' => $this->synaplanConfig->getPublicBaseUrl() . '/files',
        ]);
    }

    /**
     * The person's files from here that Synaplan holds as searchable, among
     * their 100 newest in Synaplan.
     *
     * @NoAdminRequired
     */
    public function searchable(): JSONResponse
    {
        $user = $this->userSession->getUser();
        if ($user === null || !$this->cloudState->available()) {
            return new JSONResponse(['success' => true, 'files' => []]);
        }
        try {
            $recent = $this->synaplanClient->listRecentFiles(1, SynaplanClient::SOURCE, '', 100);
        } catch (\Throwable) {
            return new JSONResponse(['success' => true, 'files' => []]);
        }

        $userFolder = $this->rootFolder->getUserFolder($user->getUID());
        $candidates = [];
        $items = [];
        foreach (is_array($recent['files'] ?? null) ? $recent['files'] : [] as $row) {
            if (!is_array($row) || ($row['source'] ?? '') !== SynaplanClient::SOURCE) {
                continue;
            }
            $sourceId = is_scalar($row['source_id'] ?? null) ? (string) $row['source_id'] : '';
            if (!ctype_digit($sourceId) || (int) $sourceId <= 0 || isset($candidates[$sourceId])) {
                continue;
            }
            $node = $userFolder->getById((int) $sourceId)[0] ?? null;
            if (!$node instanceof File) {
                continue;
            }
            $path = $userFolder->getRelativePath($node->getPath()) ?? $node->getName();
            $candidates[$sourceId] = [
                'id' => (int) ($row['id'] ?? 0),
                'fileId' => (int) $sourceId,
                'name' => $node->getName(),
                'path' => ltrim($path, '/'),
                'knowledgeFolder' => is_string($row['group_key'] ?? null) ? $row['group_key'] : '',
                'openUrl' => $this->urlGenerator->linkToRoute('files.viewcontroller.showFile', ['fileId' => (int) $sourceId]),
            ];
            $items[] = ['id' => $sourceId, 'version' => (string) $node->getEtag()];
        }

        $files = [];
        if ($items !== []) {
            foreach ($this->cloudState->status($items)['items'] as $row) {
                $id = is_string($row['id'] ?? null) ? $row['id'] : '';
                if ($id !== '' && ($row['state'] ?? 'none') !== 'none' && isset($candidates[$id])) {
                    $files[] = $candidates[$id];
                }
            }
        }

        return new JSONResponse([
            'success' => true,
            'files' => $files,
            'openSynaplanUrl' => $this->synaplanConfig->getPublicBaseUrl() . '/files',
        ]);
    }

    /**
     * @return list<array{id: string, version: string}>
     */
    private function readItems(): array
    {
        $raw = $this->request->getParam('items');
        if (!is_array($raw)) {
            $body = file_get_contents('php://input');
            $decoded = $body !== false && $body !== '' ? json_decode($body, true) : null;
            $raw = is_array($decoded) && is_array($decoded['items'] ?? null) ? $decoded['items'] : [];
        }
        $items = [];
        foreach ($raw as $item) {
            if (!is_array($item) || !is_scalar($item['id'] ?? null)) {
                continue;
            }
            $id = trim((string) $item['id']);
            $version = is_scalar($item['version'] ?? null) ? trim((string) $item['version']) : '';
            if ($id === '' || strlen($id) > self::MAX_FIELD || strlen($version) > self::MAX_FIELD) {
                continue;
            }
            $items[] = ['id' => $id, 'version' => $version];
            if (count($items) >= self::MAX_ITEMS) {
                break;
            }
        }

        return $items;
    }
}
