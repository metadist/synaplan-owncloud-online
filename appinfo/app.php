<?php

declare(strict_types=1);

/**
 * Synaplan Integration for owncloud.online
 *
 * @copyright Copyright (c) 2026, Synaplan
 * @license Apache-2.0
 *
 * ownCloud.online bootstrap: script injection, navigation and hooks are wired
 * up at request time in this file.
 */

use OCA\OcoSynaplan\AppInfo\Application;
use OCA\OcoSynaplan\Service\SynaplanConfig;
use OCA\OcoSynaplan\Service\UserAccountService;
use OCP\Util;

$app = new Application();
$container = $app->getContainer();

// Inject the file-action + floating chat-launcher scripts into the Files app.
// ownCloud.online's Files ViewController dispatches this event when it renders,
// which scopes our scripts to the Files UI instead of loading them on every page.
$dispatcher = \OC::$server->getEventDispatcher();
$dispatcher->addListener('OCA\Files::loadAdditionalScripts', function () {
    Util::addScript(Application::APP_ID, 'files-init');
    Util::addScript(Application::APP_ID, 'chat-launcher');
});

// Per-user mode: when a user is removed, delete their provisioned Synaplan
// account too so no orphaned external data lingers. Best-effort — the service
// never throws, so it cannot block ownCloud.online's own user deletion. Uses
// preDelete so the uid -> Synaplan-account mapping is still readable.
\OC::$server->getUserManager()->listen('\OC\User', 'preDelete', function ($user) use ($container) {
    /** @var SynaplanConfig $config */
    $config = $container->query(SynaplanConfig::class);
    if (!$config->isPerUserAccountsEnabled()) {
        return;
    }
    /** @var UserAccountService $accounts */
    $accounts = $container->query(UserAccountService::class);
    $accounts->deleteRemoteAccount($user->getUID());
});
