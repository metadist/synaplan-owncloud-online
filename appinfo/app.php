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
use OCA\OcoSynaplan\Listener\ReadScopeListener;
use OCA\OcoSynaplan\Listener\SourceEventListener;
use OCA\OcoSynaplan\Listener\UserDeletedListener;
use OCA\OcoSynaplan\Listener\UserEnabledListener;
use OCA\OcoSynaplan\Listener\WatchEventListener;
use OCA\OcoSynaplan\Notification\CopyNotifier;
use OCA\OcoSynaplan\Service\Notices;
use OCP\Files\Node;
use OCP\IUser;
use OCP\Share\IShare;
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
// the notices of copies saved while the person was away. WebDAV requests
// load this file too and render no page.
$page = !\OC::$CLI && \basename(\OC::$server->getRequest()->getScriptName()) === 'index.php';
$signedIn = $page ? \OC::$server->getUserSession()->getUser() : null;
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
// A folder Synaplan may read keeps that permission when it moves; Synaplan
// hears its new path.
$root = \OC::$server->getRootFolder();
$root->listen('\OC\Files', 'postRename', function ($source, $target) use ($container) {
    if ($target instanceof Node) {
        /** @var ReadScopeListener $listener */
        $listener = $container->query(ReadScopeListener::class);
        $listener->handle($target);
    }
});
// Synaplan hears when a file it keeps a copy of changes, moves, goes to the
// trash or comes back. One listener sees a delete before and after.
$sourceEvents = static fn (): SourceEventListener => $container->query(SourceEventListener::class);
$root->listen('\OC\Files', 'postWrite', function ($node) use ($sourceEvents) {
    if ($node instanceof Node) {
        $sourceEvents()->written($node);
    }
});
$root->listen('\OC\Files', 'postRename', function ($source, $target) use ($sourceEvents) {
    if ($target instanceof Node) {
        $sourceEvents()->renamed($target);
    }
});
$root->listen('\OC\Files', 'preDelete', function ($node) use ($sourceEvents) {
    if ($node instanceof Node) {
        $sourceEvents()->deleting($node);
    }
});
$root->listen('\OC\Files', 'postDelete', function ($node) use ($sourceEvents) {
    if ($node instanceof Node) {
        $sourceEvents()->deleted($node);
    }
});
Util::connectHook('\OCA\Files_Trashbin\Trashbin', 'post_restore', SourceEventListener::class, 'onRestore');
// Files arriving in a watched folder, and new versions of a watched file,
// wait in the watch queue; a watched folder or file that goes to the trash
// or comes back is reported to its watch.
$watchEvents = static fn (): WatchEventListener => $container->query(WatchEventListener::class);
$root->listen('\OC\Files', 'postWrite', function ($node) use ($watchEvents) {
    if ($node instanceof Node) {
        $watchEvents()->written($node);
    }
});
$root->listen('\OC\Files', 'postRename', function ($source, $target) use ($watchEvents) {
    if ($target instanceof Node) {
        $watchEvents()->renamed($target);
    }
});
$root->listen('\OC\Files', 'preDelete', function ($node) use ($watchEvents) {
    if ($node instanceof Node) {
        $watchEvents()->deleting($node);
    }
});
$root->listen('\OC\Files', 'postDelete', function ($node) use ($watchEvents) {
    if ($node instanceof Node) {
        $watchEvents()->deleted($node);
    }
});
Util::connectHook('\OCA\Files_Trashbin\Trashbin', 'post_restore', WatchEventListener::class, 'onRestore');
// A share its recipient removes from their files, or declines, is unmounted:
// nothing is deleted, but their copies of what it holds are gone for them.
Util::connectHook('OC_Filesystem', 'umount', SourceEventListener::class, 'onUnmounting');
Util::connectHook('OC_Filesystem', 'post_umount', SourceEventListener::class, 'onUnmounted');
$dispatcher->addListener('share.afterreject', function ($event) use ($sourceEvents) {
    $share = $event instanceof GenericEvent && $event->hasArgument('share') ? $event->getArgument('share') : null;
    if ($share instanceof IShare) {
        $sourceEvents()->rejected($share);
    }
});
$dispatcher->addListener('OC\User\User::postSetEnabled', function ($event) use ($container) {
    $user = $event instanceof GenericEvent ? $event->getSubject() : null;
    if ($user instanceof IUser) {
        /** @var UserEnabledListener $listener */
        $listener = $container->query(UserEnabledListener::class);
        $listener->handle($user);
    }
});
