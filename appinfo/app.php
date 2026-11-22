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
use OCA\OcoSynaplan\Listener\UserDeletedListener;
use OCA\OcoSynaplan\Listener\UserEnabledListener;
use OCP\IUser;
use OCP\Util;
use Symfony\Component\EventDispatcher\GenericEvent;

$app = new Application();
$container = $app->getContainer();

// The file actions belong to the Files app: its ViewController dispatches this
// event when it renders. Each script's translations are added before it,
// because scripts run in the order they are added.
$dispatcher = \OC::$server->getEventDispatcher();
$dispatcher->addListener('OCA\Files::loadAdditionalScripts', function () {
    Util::addTranslations(Application::APP_ID);
    Util::addScript(Application::APP_ID, 'files-init');
});

// The chat launcher is on every page a signed-in person opens.
if (!\OC::$CLI && \OC::$server->getUserSession()->getUser() !== null) {
    Util::addTranslations(Application::APP_ID);
    Util::addScript(Application::APP_ID, 'chat-launcher');
}

// Synaplan hears when a person here is deleted, disabled or enabled again.
\OC::$server->getUserManager()->listen('\OC\User', 'preDelete', function (IUser $user) use ($container) {
    /** @var UserDeletedListener $listener */
    $listener = $container->query(UserDeletedListener::class);
    $listener->handle($user);
});
$dispatcher->addListener('OC\User\User::postSetEnabled', function ($event) use ($container) {
    $user = $event instanceof GenericEvent ? $event->getSubject() : null;
    if ($user instanceof IUser) {
        /** @var UserEnabledListener $listener */
        $listener = $container->query(UserEnabledListener::class);
        $listener->handle($user);
    }
});
