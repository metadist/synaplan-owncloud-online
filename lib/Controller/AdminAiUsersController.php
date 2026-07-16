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
use OCA\OcoSynaplan\Service\AdminAiUsersService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;

/**
 * Admin control panel: which ownCloud.online users have activated AI, their Synaplan
 * account + usage, and the ability to deactivate a user.
 *
 * All methods are admin-only (no @NoAdminRequired), matching SettingsController.
 */
class AdminAiUsersController extends Controller
{
    public function __construct(
        IRequest $request,
        private AdminAiUsersService $aiUsers,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    public function list(): JSONResponse
    {
        return new JSONResponse([
            'success' => true,
            'users' => $this->aiUsers->listActivatedUsers(),
        ]);
    }

    public function usage(string $uid): JSONResponse
    {
        return new JSONResponse($this->aiUsers->usage($uid));
    }

    public function deactivate(string $uid): JSONResponse
    {
        $this->aiUsers->deactivate($uid);

        return new JSONResponse(['success' => true]);
    }
}
