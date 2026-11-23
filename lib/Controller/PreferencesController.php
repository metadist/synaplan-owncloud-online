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
use OCA\OcoSynaplan\Service\PlatformLinkService;
use OCA\OcoSynaplan\Service\ReadScope;
use OCA\OcoSynaplan\Service\SynaplanFolders;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;

/**
 * What a person set for files from Synaplan: the folder they land in, and
 * what Synaplan may read.
 */
class PreferencesController extends Controller
{
    public function __construct(
        IRequest $request,
        private IConfig $config,
        private IUserSession $userSession,
        private ReadScope $scope,
        private PlatformLinkService $links,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * @NoAdminRequired
     */
    public function get(): JSONResponse
    {
        $uid = $this->uid();
        if ($uid === null) {
            return new JSONResponse(['success' => false, 'error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        return new JSONResponse([
            'copy_folder' => (string) $this->config->getUserValue($uid, Application::APP_ID, SynaplanFolders::COPY_FOLDER, ''),
            'read_scope' => $this->scope->get($uid),
            'read_folder_cap' => $this->scope->cap(),
            'read_scope_ceiling' => $this->scope->ceiling(),
            'reads_folders' => $this->links->readsFolders(),
        ]);
    }

    /**
     * Add one folder the person lets Synaplan read. The folder is the one at
     * the path now; the id the page sends is not taken.
     *
     * @NoAdminRequired
     */
    public function allowFolder(string $fileId = '', string $path = ''): JSONResponse
    {
        $uid = $this->uid();
        if ($uid === null) {
            return new JSONResponse(['success' => false, 'error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        if (!$this->links->readsFolders()) {
            return new JSONResponse(['success' => false, 'error' => 'unavailable'], Http::STATUS_CONFLICT);
        }
        $reason = $this->scope->allow($uid, $path);
        if ($reason === 'ceiling') {
            return new JSONResponse(['success' => false, 'error' => 'ceiling'], Http::STATUS_FORBIDDEN);
        }
        if ($reason === 'cap') {
            return new JSONResponse([
                'success' => false,
                'error' => $reason,
                'read_scope' => $this->scope->get($uid),
            ], Http::STATUS_CONFLICT);
        }
        if ($reason !== null) {
            return new JSONResponse(['success' => false, 'error' => 'folder'], Http::STATUS_BAD_REQUEST);
        }

        return new JSONResponse(['success' => true, 'read_scope' => $this->scope->get($uid)]);
    }

    /**
     * Changes what the request carries, the copy folder, the read setting or
     * both; what it leaves out stays as it is. A folder that cannot be used
     * stops the whole save, so it never leaves a wider read setting behind,
     * and so does a read setting for a Synaplan that reads no folders.
     *
     * @NoAdminRequired
     */
    public function save(?string $copy_folder = null): JSONResponse
    {
        $uid = $this->uid();
        if ($uid === null) {
            return new JSONResponse(['success' => false, 'error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        $readScope = $this->request->getParam('read_scope');
        if (is_array($readScope) && !$this->links->readsFolders()) {
            return new JSONResponse(['success' => false, 'error' => 'unavailable'], Http::STATUS_CONFLICT);
        }
        $folder = null;
        if ($copy_folder !== null) {
            try {
                $folder = SynaplanFolders::cleanFolder($copy_folder);
            } catch (\InvalidArgumentException) {
                return new JSONResponse(
                    ['success' => false, 'error' => 'Could not save that folder.'],
                    Http::STATUS_BAD_REQUEST,
                );
            }
        }
        if (is_array($readScope)) {
            $folders = [];
            foreach (is_array($readScope['folders'] ?? null) ? $readScope['folders'] : [] as $named) {
                if (!is_array($named)) {
                    continue;
                }
                $folders[] = [
                    'id' => (string) ($named['id'] ?? ''),
                    'path' => (string) ($named['path'] ?? ''),
                ];
            }
            $this->scope->save($uid, (string) ($readScope['mode'] ?? ''), $folders);
        }
        if ($folder !== null) {
            $this->config->setUserValue($uid, Application::APP_ID, SynaplanFolders::COPY_FOLDER, $folder);
        }

        return new JSONResponse([
            'success' => true,
            'copy_folder' => (string) $this->config->getUserValue($uid, Application::APP_ID, SynaplanFolders::COPY_FOLDER, ''),
            'read_scope' => $this->scope->get($uid),
        ]);
    }

    private function uid(): ?string
    {
        $user = $this->userSession->getUser();

        return $user instanceof IUser ? $user->getUID() : null;
    }
}
