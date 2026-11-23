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
use OCA\OcoSynaplan\Notification\CopyNotifier;
use OCA\OcoSynaplan\Service\Notices;
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

// The chat launcher is on every page a signed-in person opens, and so are
// the notices of copies saved while the person was away.
$signedIn = \OC::$CLI ? null : \OC::$server->getUserSession()->getUser();
if ($signedIn !== null) {
    Util::addTranslations(Application::APP_ID);
    Util::addScript(Application::APP_ID, 'chat-launcher');
    if ($container->query(Notices::class)->hasPending($signedIn->getUID())) {
        Util::addScript(Application::APP_ID, 'notices');
    }
}

// The sentences of this app's notifications, where the notifications app is installed.
\OC::$server->getNotificationManager()->registerNotifier(
    static fn () => $container->query(CopyNotifier::class),
    static fn () => ['id' => Application::APP_ID, 'name' => 'Synaplan'],
);

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
