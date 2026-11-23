<?php

declare(strict_types=1);

/**
 * Synaplan Integration for owncloud.online
 *
 * @copyright Copyright (c) 2026, Synaplan
 * @license Apache-2.0
 */

namespace OCA\OcoSynaplan\Service;

use OCA\OcoSynaplan\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\IConfig;
use OCP\ILogger;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\Notification\IManager;

/**
 * Tells a person what Synaplan did in their files. ownCloud.online ships no
 * notifications app (docs/CF-S4.md §5): where it is enabled for the person
 * the notice goes there, otherwise the app keeps a short list and shows it
 * on the person's next page.
 */
class Notices
{
    public const SUBJECT_COPIED = 'copied';

    private const KEY = 'notices';
    private const MAX = 20;

    public function __construct(
        private IConfig $config,
        private IManager $notifications,
        private IAppManager $apps,
        private IUserManager $users,
        private IURLGenerator $urls,
        private ILogger $logger,
    ) {
    }

    /**
     * The notice keeps the file's id, not a link: Synaplan may reach this
     * ownCloud.online at another address than the person, so the link is
     * made when the person's page asks.
     */
    public function copied(string $uid, string $name, string $folder, int $fileId): void
    {
        $parameters = ['name' => $name, 'folder' => $folder];
        $user = $this->users->get($uid);
        if ($user !== null && $this->apps->isEnabledForUser('notifications', $user)) {
            try {
                $notification = $this->notifications->createNotification();
                $notification->setApp(Application::APP_ID)
                    ->setUser($uid)
                    ->setDateTime(new \DateTime())
                    ->setObject('file', (string) $fileId)
                    ->setSubject(self::SUBJECT_COPIED, $parameters);
                $this->notifications->notify($notification);

                return;
            } catch (\Throwable $e) {
                $this->logger->info('Could not notify {uid} about a Synaplan copy: {message}', [
                    'app' => Application::APP_ID,
                    'uid' => $uid,
                    'message' => $e->getMessage(),
                ]);
            }
        }
        $this->remember($uid, ['subject' => self::SUBJECT_COPIED] + $parameters + ['file_id' => $fileId]);
    }

    public function hasPending(string $uid): bool
    {
        return $this->config->getUserValue($uid, Application::APP_ID, self::KEY, '') !== '';
    }

    /**
     * @return list<array{subject: string, name: string, folder: string, file_id: int, link: string}>
     */
    public function pending(string $uid): array
    {
        return array_map(
            fn (array $notice): array => $notice + [
                'link' => $notice['file_id'] > 0
                    ? $this->urls->linkToRoute('files.viewcontroller.showFile', ['fileId' => $notice['file_id']])
                    : '',
            ],
            $this->stored($uid),
        );
    }

    /**
     * Forget the first $count notices, the ones a page showed. A notice
     * that arrived since stays for the next page.
     */
    public function forget(string $uid, int $count): void
    {
        $left = array_slice($this->stored($uid), max(0, $count));
        if ($left === []) {
            $this->config->deleteUserValue($uid, Application::APP_ID, self::KEY);

            return;
        }
        $this->config->setUserValue($uid, Application::APP_ID, self::KEY, (string) json_encode($left));
    }

    /**
     * @param array{subject: string, name: string, folder: string, file_id: int} $notice
     */
    private function remember(string $uid, array $notice): void
    {
        $notices = $this->stored($uid);
        $notices[] = $notice;
        $this->config->setUserValue(
            $uid,
            Application::APP_ID,
            self::KEY,
            (string) json_encode(array_slice($notices, -self::MAX)),
        );
    }

    /**
     * @return list<array{subject: string, name: string, folder: string, file_id: int}>
     */
    private function stored(string $uid): array
    {
        $decoded = json_decode((string) $this->config->getUserValue($uid, Application::APP_ID, self::KEY, ''), true);
        if (!is_array($decoded)) {
            return [];
        }
        $notices = [];
        foreach ($decoded as $notice) {
            if (is_array($notice) && is_string($notice['subject'] ?? null)) {
                $notices[] = [
                    'subject' => $notice['subject'],
                    'name' => (string) ($notice['name'] ?? ''),
                    'folder' => (string) ($notice['folder'] ?? ''),
                    'file_id' => (int) ($notice['file_id'] ?? 0),
                ];
            }
        }

        return $notices;
    }
}
