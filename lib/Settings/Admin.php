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
 * Admin settings panel for Synaplan Integration.
 *
 * Renders inside the "Synaplan" admin section (see {@see Section}).
 * ownCloud.online's ISettings exposes getPanel() and getSectionID(). The
 * settings page fetches the panel via Template::fetchPage(), so we return a raw
 * Template (as core apps do) to embed only the partial rather than a full page.
 */
class Admin implements ISettings
{
    public function getPanel(): Template
    {
        // Added here (before the settings page response is rendered) so the
        // Vue mount script is emitted into the page head.
        Util::addTranslations(Application::APP_ID);
        Util::addScript(Application::APP_ID, 'settings');

        return new Template(Application::APP_ID, 'settings/admin');
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
