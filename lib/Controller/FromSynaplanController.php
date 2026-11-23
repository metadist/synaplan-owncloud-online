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
use OCA\OcoSynaplan\Http\RespondsToFailures;
use OCA\OcoSynaplan\Service\LibraryImport;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\ILogger;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;

/**
 * New → From Synaplan… in the Files app.
 */
class FromSynaplanController extends Controller
{
    use RespondsToFailures;

    public function __construct(
        IRequest $request,
        private LibraryImport $imports,
        private IUserSession $userSession,
        private ILogger $logger,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * @NoAdminRequired
     */
    public function list(int $page = 1, string $filter = 'generated', string $search = ''): JSONResponse
    {
        if ($this->uid() === null) {
            return new JSONResponse(['success' => false, 'error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        try {
            return new JSONResponse($this->imports->list($page, $filter, $search));
        } catch (\Throwable $e) {
            return $this->failed($e, 'Could not list Synaplan files');
        }
    }

    /**
     * @NoAdminRequired
     */
    public function copy(int $fileId = 0, string $folder = '', string $name = ''): JSONResponse
    {
        $uid = $this->uid();
        if ($uid === null) {
            return new JSONResponse(['success' => false, 'error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        try {
            $saved = $this->imports->copy($uid, $fileId, $folder, $name);
        } catch (\InvalidArgumentException) {
            return new JSONResponse(
                ['success' => false, 'error' => 'Could not copy this file.'],
                Http::STATUS_BAD_REQUEST,
            );
        } catch (\Throwable $e) {
            return $this->failed($e, 'Could not copy a Synaplan file');
        }

        return new JSONResponse(['success' => true] + $saved);
    }

    private function uid(): ?string
    {
        $user = $this->userSession->getUser();

        return $user instanceof IUser ? $user->getUID() : null;
    }
}
