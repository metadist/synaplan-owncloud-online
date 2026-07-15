<?php

declare(strict_types=1);

/**
 * Synaplan Integration for owncloud.online
 *
 * @copyright Copyright (c) 2026, Synaplan
 * @license Apache-2.0
 */

namespace OCA\OcoSynaplan\AppInfo;

use OCP\AppFramework\App;

/**
 * App container.
 *
 * ownCloud 10's dependency-injection container resolves controller and service
 * constructor dependencies by type-hint via reflection, so we do not register
 * services explicitly here — every dependency is either an OCP interface bound
 * in the server container or an app class the container can build itself.
 */
class Application extends App
{
    public const APP_ID = 'oco_synaplan';

    public function __construct(array $urlParams = [])
    {
        parent::__construct(self::APP_ID, $urlParams);
    }
}
