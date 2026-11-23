<?php

declare(strict_types=1);

/**
 * Synaplan Integration for owncloud.online
 *
 * @copyright Copyright (c) 2026, Synaplan
 * @license Apache-2.0
 */

namespace OCA\OcoSynaplan\Listener;

use OCA\OcoSynaplan\AppInfo\Application;
use OCA\OcoSynaplan\Service\OwnWrites;
use OCA\OcoSynaplan\Service\SynaplanConfig;
use OCA\OcoSynaplan\Service\WatchIndex;
use OCA\OcoSynaplan\Service\WatchSender;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IConfig;
use OCP\IUserSession;

/**
 * Files that arrive in a watched folder, and new versions of a watched file,
 * go into the watch queue; the job sends them once they have been quiet.
 * Nothing is sent from the request that wrote the file. A watched folder or
 * file that is deleted or restored is reported to its watch. The app's own
 * writes are ignored.
 */
class WatchEventListener
{
    /**
     * The id of what is being deleted, by its path: the node is gone once
     * the deletion went through.
     *
     * @var array<string, int>
     */
    private array $deleting = [];

    public function __construct(
        private SynaplanConfig $synaplanConfig,
        private IConfig $config,
        private WatchIndex $index,
        private OwnWrites $ownWrites,
        private ITimeFactory $time,
        private IRootFolder $rootFolder,
        private IUserSession $userSession,
    ) {
    }

    /**
     * Written, uploaded or copied.
     */
    public function written(Node $node): void
    {
        $this->guarded(fn () => $this->arrived($node));
    }

    /**
     * Moved or renamed: it arrives where it went, and a watched target that
     * was gone is back.
     */
    public function renamed(Node $target): void
    {
        $this->guarded(function () use ($target): void {
            $this->arrived($target);
            $this->targetEvent((int) $target->getId(), 'restored');
        });
    }

    public function deleting(Node $node): void
    {
        $this->guarded(function () use ($node): void {
            $this->deleting[$node->getPath()] = (int) $node->getId();
        });
    }

    public function deleted(Node $node): void
    {
        $path = $node->getPath();
        $id = $this->deleting[$path] ?? 0;
        unset($this->deleting[$path]);
        if ($id > 0) {
            $this->guarded(fn () => $this->targetEvent($id, 'deleted'));
        }
    }

    /**
     * The trash bin restores outside the person's files, which emits no
     * rename, and tells its own hook, which calls a static slot.
     *
     * @param array<string, mixed> $params the trash bin's `filePath`, in the files of the person restoring
     */
    public static function onRestore(array $params): void
    {
        try {
            /** @var self $listener */
            $listener = (new Application())->getContainer()->query(self::class);
            $listener->restored($params);
        } catch (\Throwable) {
        }
    }

    /**
     * @param array<string, mixed> $params
     */
    public function restored(array $params): void
    {
        $user = $this->userSession->getUser();
        $filePath = is_string($params['filePath'] ?? null) ? trim($params['filePath'], '/') : '';
        if ($user === null || $filePath === '') {
            return;
        }
        $this->guarded(function () use ($user, $filePath): void {
            $this->renamed($this->rootFolder->getUserFolder($user->getUID())->get($filePath));
        });
    }

    private function guarded(\Closure $handle): void
    {
        if ($this->config->getAppValue(Application::APP_ID, SourceEventListener::DEV_MUTE, '0') === '1'
            || !$this->synaplanConfig->isRegistered()) {
            return;
        }
        try {
            $handle();
        } catch (\Throwable) {
            // A file write never fails because of a watch; the hourly pass catches up.
        }
    }

    private function arrived(Node $node): void
    {
        if (!$node instanceof File) {
            return;
        }
        $id = (int) $node->getId();
        $now = $this->time->getTime();
        if ($id <= 0 || $this->ownWrites->isOwnVersion($id, WatchSender::version($node))) {
            return;
        }
        try {
            $parent = (int) $node->getParent()->getId();
        } catch (\Throwable) {
            $parent = 0;
        }
        foreach ($this->index->activeOn([$id, $parent]) as $watch) {
            if (WatchSender::belongs($watch, $node)) {
                $this->index->enqueue($watch['id'], $id, '', $now);
            }
        }
    }

    /**
     * A deleted target is reported while its watch runs; a restored one only
     * while its watch says the target is gone. A target restored before its
     * deletion went out takes the deletion back: Synaplan never knew.
     */
    private function targetEvent(int $id, string $event): void
    {
        foreach ($this->index->on([$id]) as $watch) {
            if ($watch['target'] !== $id) {
                continue;
            }
            $running = $watch['state'] === WatchIndex::ACTIVE;
            if ($event === 'deleted' ? $running : !$running) {
                $this->index->enqueue($watch['id'], $id, $event, $this->time->getTime());
            } elseif ($event === 'restored') {
                $this->index->cancelEvent($watch['id'], $id, 'deleted');
            }
        }
    }
}
