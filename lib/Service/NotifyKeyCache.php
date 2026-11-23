<?php

declare(strict_types=1);

/**
 * Synaplan Integration for owncloud.online
 *
 * @copyright Copyright (c) 2026, Synaplan
 * @license Apache-2.0
 */

namespace OCA\OcoSynaplan\Service;

use Doctrine\DBAL\Result;
use OCA\OcoSynaplan\AppInfo\Application;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\Lock\ILockingProvider;
use OCP\Lock\LockedException;

/**
 * Synaplan's notification issuer and keys, read from its two public
 * documents through the address this ownCloud.online reaches Synaplan by.
 * Refreshed when this server registers, when an administrator saves the
 * settings, hourly, and after a notification no known key verifies, at most
 * once a minute, so forged notifications cannot make this server call
 * Synaplan more often than that.
 */
class NotifyKeyCache
{
    private const KEYS = 'notify_keys';
    private const UNKNOWN_KEY_CHECKED_AT = 'notify_keys_unknown_checked_at';
    private const UNKNOWN_KEY_INTERVAL = 60;
    private const UNKNOWN_KEY_LOCK = Application::APP_ID . '/notify-keys-unknown';
    private const LOCK_WAIT_SECONDS = 10;

    /**
     * Values read from the database after waiting for another request:
     * ownCloud keeps the app's settings as this request first read them.
     *
     * @var array<string, string>
     */
    private array $stored = [];

    public function __construct(
        private IConfig $config,
        private IDBConnection $db,
        private ILockingProvider $locks,
        private PlatformLinkService $links,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function keys(): array
    {
        $raw = $this->value(self::KEYS);
        $decoded = $raw === '' ? [] : json_decode($raw, true);

        return is_array($decoded) ? array_values(array_filter($decoded, 'is_array')) : [];
    }

    public function issuer(): string
    {
        return $this->value(OAuthClient::ISSUER);
    }

    public function refresh(): void
    {
        if ($this->config->getAppValue(Application::APP_ID, 'link_instance_id', '') === '') {
            return;
        }
        $this->refreshTrust();
    }

    /**
     * A notification no known key verifies may be signed with a key Synaplan
     * started using since the last refresh. Returns whether the keys changed
     * since this request read them, also when another request refreshed them.
     */
    public function refreshForUnknownKey(int $now): bool
    {
        $before = $this->keys();
        if (!$this->acquire()) {
            return false;
        }
        try {
            // Concurrent requests claim the interval one at a time, each
            // reading the claim the one before it wrote.
            foreach ([self::UNKNOWN_KEY_CHECKED_AT, self::KEYS, OAuthClient::ISSUER] as $key) {
                $this->stored[$key] = $this->storedValue($key);
            }
            $checkedAt = (int) $this->value(self::UNKNOWN_KEY_CHECKED_AT);
            if ($now - $checkedAt >= self::UNKNOWN_KEY_INTERVAL) {
                $this->setValue(self::UNKNOWN_KEY_CHECKED_AT, (string) $now);
                $this->refresh();
            }

            return $this->keys() !== $before;
        } finally {
            $this->locks->releaseLock(self::UNKNOWN_KEY_LOCK, ILockingProvider::LOCK_EXCLUSIVE);
        }
    }

    /**
     * Read Synaplan's issuer and keys now, registered or not.
     */
    public function refreshTrust(): void
    {
        try {
            $trust = $this->links->notificationTrust();
        } catch (\Throwable) {
            return;
        }
        // A half answer keeps the last good trust: without keys every
        // notification would be refused until the next refresh.
        if ($trust['issuer'] === '' || $trust['keys'] === []) {
            return;
        }
        $this->setValue(OAuthClient::ISSUER, rtrim($trust['issuer'], '/'));
        $this->setValue(self::KEYS, json_encode($trust['keys'], JSON_THROW_ON_ERROR));
    }

    private function value(string $key): string
    {
        return $this->stored[$key] ?? (string) $this->config->getAppValue(Application::APP_ID, $key, '');
    }

    private function setValue(string $key, string $value): void
    {
        $this->config->setAppValue(Application::APP_ID, $key, $value);
        unset($this->stored[$key]);
    }

    /** The value in the database now, also when another request just wrote it. */
    private function storedValue(string $key): string
    {
        $query = $this->db->getQueryBuilder();
        $query->select('configvalue')
            ->from('appconfig')
            ->where($query->expr()->eq('appid', $query->createNamedParameter(Application::APP_ID)))
            ->andWhere($query->expr()->eq('configkey', $query->createNamedParameter($key)));
        $result = $query->execute();
        if (!$result instanceof Result) {
            return '';
        }
        $value = $result->fetchOne();
        $result->free();

        return is_string($value) ? $value : '';
    }

    private function acquire(): bool
    {
        $deadline = microtime(true) + self::LOCK_WAIT_SECONDS;
        while (true) {
            try {
                $this->locks->acquireLock(self::UNKNOWN_KEY_LOCK, ILockingProvider::LOCK_EXCLUSIVE);

                return true;
            } catch (LockedException) {
                if (microtime(true) >= $deadline) {
                    return false;
                }
                usleep(100_000);
            }
        }
    }
}
