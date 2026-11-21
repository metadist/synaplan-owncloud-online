<?php

declare(strict_types=1);

namespace OCA\OcoSynaplan\Tests\Unit\Support;

use OCP\IConfig;

/**
 * ownCloud's settings in memory: app values, each person's values and the
 * system values of config.php. Values are strings, as the server stores them.
 */
final class MemoryConfig implements IConfig
{
    /** @var array<string, array<string, string>> */
    public array $app = [];

    /** @var array<string, array<string, array<string, string>>> */
    public array $user = [];

    /** @var array<string, mixed> */
    public array $system = ['instanceid' => 'oc1234567890'];

    /**
     * @param array<string, string> $app values of the Synaplan app
     */
    public function __construct(array $app = [])
    {
        $this->app['oco_synaplan'] = $app;
    }

    /**
     * @param array<string, mixed> $configs
     */
    public function setSystemValues(array $configs): void
    {
        foreach ($configs as $key => $value) {
            $this->system[$key] = $value;
        }
    }

    public function setSystemValue($key, $value): void
    {
        $this->system[$key] = $value;
    }

    public function getSystemValue($key, $default = '')
    {
        return $this->system[$key] ?? $default;
    }

    public function getFilteredSystemValue($key, $default = '')
    {
        return $this->getSystemValue($key, $default);
    }

    public function deleteSystemValue($key): void
    {
        unset($this->system[$key]);
    }

    public function getAppKeys($appName)
    {
        return array_keys($this->app[$appName] ?? []);
    }

    public function setAppValue($appName, $key, $value)
    {
        $this->app[$appName][$key] = (string) $value;
    }

    public function getAppValue($appName, $key, $default = '')
    {
        return $this->app[$appName][$key] ?? $default;
    }

    public function deleteAppValue($appName, $key): void
    {
        unset($this->app[$appName][$key]);
    }

    public function deleteAppValues($appName): void
    {
        unset($this->app[$appName]);
    }

    public function setUserValue($userId, $appName, $key, $value, $preCondition = null): void
    {
        $this->user[$userId][$appName][$key] = (string) $value;
    }

    public function getUserValue($userId, $appName, $key, $default = '')
    {
        return $this->user[$userId][$appName][$key] ?? $default;
    }

    /**
     * @param list<string> $userIds
     *
     * @return array<string, string>
     */
    public function getUserValueForUsers($appName, $key, $userIds)
    {
        $values = [];
        foreach ($userIds as $uid) {
            if (isset($this->user[$uid][$appName][$key])) {
                $values[$uid] = $this->user[$uid][$appName][$key];
            }
        }

        return $values;
    }

    public function getUserKeys($userId, $appName)
    {
        return array_keys($this->user[$userId][$appName] ?? []);
    }

    public function deleteUserValue($userId, $appName, $key): void
    {
        unset($this->user[$userId][$appName][$key]);
    }

    public function deleteAllUserValues($userId): void
    {
        unset($this->user[$userId]);
    }

    public function deleteAppFromAllUsers($appName): void
    {
        foreach (array_keys($this->user) as $uid) {
            unset($this->user[$uid][$appName]);
        }
    }

    /**
     * @return list<string>
     */
    public function getUsersForUserValue($appName, $key, $value)
    {
        $uids = [];
        foreach ($this->user as $uid => $apps) {
            if (($apps[$appName][$key] ?? null) === (string) $value) {
                $uids[] = (string) $uid;
            }
        }

        return $uids;
    }

    public function isSystemConfigReadOnly()
    {
        return false;
    }

    /** A value of the Synaplan app as stored. */
    public function appValue(string $key): ?string
    {
        return $this->app['oco_synaplan'][$key] ?? null;
    }

    /** A person's value of the Synaplan app as stored. */
    public function userValue(string $uid, string $key): ?string
    {
        return $this->user[$uid]['oco_synaplan'][$key] ?? null;
    }
}
