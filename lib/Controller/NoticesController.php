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
use OCA\OcoSynaplan\Service\Notices;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;

/**
 * The notices waiting for a person's next page, where the server has no
 * notifications app.
 */
class NoticesController extends Controller
{
    public function __construct(
        IRequest $request,
        private Notices $notices,
        private IUserSession $userSession,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * @NoAdminRequired
     */
    public function list(): JSONResponse
    {
        $uid = $this->uid();
        if ($uid === null) {
            return new JSONResponse(['success' => false, 'error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }

        return new JSONResponse(['notices' => $this->notices->pending($uid)]);
    }

    /**
     * @NoAdminRequired
     *
     * @param int $count how many of the listed notices the page showed
     */
    public function clear(int $count = 0): JSONResponse
    {
        $uid = $this->uid();
        if ($uid === null) {
            return new JSONResponse(['success' => false, 'error' => 'Not authenticated'], Http::STATUS_UNAUTHORIZED);
        }
        $this->notices->forget($uid, $count);

        return new JSONResponse(['success' => true]);
    }

    private function uid(): ?string
    {
        $user = $this->userSession->getUser();

        return $user instanceof IUser ? $user->getUID() : null;
    }
}
