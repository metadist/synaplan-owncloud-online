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
use OCA\OcoSynaplan\Service\SynaplanFolders;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;

/**
 * What a person set for files from Synaplan: the folder they land in.
 */
class PreferencesController extends Controller
{
    public function __construct(
        IRequest $request,
        private IConfig $config,
        private IUserSession $userSession,
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
        ]);
    }

    /**
     * @NoAdminRequired
     */
    public function save(string $copy_folder = ''): JSONResponse
    {
        $uid = $this->uid();
        if ($uid === null) {
            return new JSONResponse(['success' => false, 'error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        try {
            $folder = SynaplanFolders::cleanFolder($copy_folder);
        } catch (\InvalidArgumentException) {
            return new JSONResponse(
                ['success' => false, 'error' => 'Could not save that folder.'],
                Http::STATUS_BAD_REQUEST,
            );
        }
        $this->config->setUserValue($uid, Application::APP_ID, SynaplanFolders::COPY_FOLDER, $folder);

        return new JSONResponse(['success' => true, 'copy_folder' => $folder]);
    }

    private function uid(): ?string
    {
        $user = $this->userSession->getUser();

        return $user instanceof IUser ? $user->getUID() : null;
    }
}
