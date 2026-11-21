<?php

declare(strict_types=1);

/**
 * Synaplan Integration for owncloud.online
 *
 * @copyright Copyright (c) 2026, Synaplan
 * @license Apache-2.0
 */

namespace OCA\OcoSynaplan\Settings;

use OCA\OcoSynaplan\AppInfo\Application;
use OCP\Settings\ISettings;
use OCP\Template;
use OCP\Util;

/**
 * Personal settings panel: the person's Synaplan connection.
 *
 * Renders inside the "Synaplan" personal section (see {@see Section}). The
 * settings manager builds panels with the server container, so this class
 * takes no app services; the Vue app reads its state from the API.
 */
class Personal implements ISettings
{
    public function getPanel(): Template
    {
        Util::addScript(Application::APP_ID, 'personal-settings');

        return new Template(Application::APP_ID, 'settings/personal');
    }

    public function getSectionID(): string
    {
        return Application::APP_ID;
    }

    public function getPriority(): int
    {
        return 10;
    }
}
