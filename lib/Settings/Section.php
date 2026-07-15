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
use OCP\Settings\ISection;

/**
 * Admin settings section for Synaplan Integration.
 *
 * Creates a "Synaplan" entry in the admin settings sidebar. Registered via
 * info.xml's <settings-sections> so ownCloud can discover it. getIconName()
 * returns "app", which resolves to img/app.svg in this app.
 */
class Section implements ISection
{
    public function getID(): string
    {
        return Application::APP_ID;
    }

    public function getName(): string
    {
        return 'Synaplan';
    }

    public function getPriority(): int
    {
        return 90;
    }

    public function getIconName(): string
    {
        return 'app';
    }
}
