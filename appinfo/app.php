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
use OCA\OcoSynaplan\Service\PlatformLinkService;
use OCA\OcoSynaplan\Service\SynaplanConfig;
use OCA\OcoSynaplan\Service\UserAccountService;
use OCP\Util;

$app = new Application();
$container = $app->getContainer();

// The file actions belong to the Files app: its ViewController dispatches this
// event when it renders.
$dispatcher = \OC::$server->getEventDispatcher();
$dispatcher->addListener('OCA\Files::loadAdditionalScripts', function () {
    Util::addScript(Application::APP_ID, 'files-init');
});

// The chat launcher is on every page a signed-in person opens.
if (!\OC::$CLI && \OC::$server->getUserSession()->getUser() !== null) {
    Util::addScript(Application::APP_ID, 'chat-launcher');
}

// A removed person takes their Synaplan connection with them. An account they
// connected is theirs: only this server's grant or key ends. An account this
// server created for them is deleted. Best-effort — neither call throws, so
// neither can block ownCloud.online's own user deletion. preDelete keeps the
// person's settings readable.
\OC::$server->getUserManager()->listen('\OC\User', 'preDelete', function ($user) use ($container) {
    $uid = $user->getUID();
    /** @var UserAccountService $accounts */
    $accounts = $container->query(UserAccountService::class);
    if ($accounts->wasLinked($uid) || $accounts->getLinkKind($uid) === UserAccountService::KIND_LINKED) {
        /** @var PlatformLinkService $platformLinks */
        $platformLinks = $container->query(PlatformLinkService::class);
        $platformLinks->disconnectUid($uid);

        return;
    }
    /** @var SynaplanConfig $config */
    $config = $container->query(SynaplanConfig::class);
    if ($config->isPerUserAccountsEnabled()) {
        $accounts->deleteRemoteAccount($uid);
    }
});
