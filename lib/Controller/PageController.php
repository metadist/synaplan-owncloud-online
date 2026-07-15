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
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\Util;

/**
 * Controller for full-page views.
 */
class PageController extends Controller
{
    public function __construct(IRequest $request)
    {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * Research chat page.
     *
     * @NoAdminRequired
     * @NoCSRFRequired
     */
    public function research(): TemplateResponse
    {
        Util::addScript(Application::APP_ID, 'research');

        return new TemplateResponse(Application::APP_ID, 'research');
    }
}
