<?php

declare(strict_types=1);

/**
 * Minimal OC stub for unit tests and PHPStan.
 *
 * Application reads the locking provider from \OC::$server, because the app
 * container of ownCloud 11 does not register ILockingProvider.
 */
class OC
{
    /** @var \OCP\IServerContainer */
    public static $server;
}
