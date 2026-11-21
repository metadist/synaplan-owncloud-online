<?php

declare(strict_types=1);

namespace OCA\OcoSynaplan\Tests\Unit\Support;

use OCP\ICache;
use OCP\ICacheFactory;

/**
 * ownCloud's memory cache, one store for every prefix the app asks for.
 * Entries keep the time they were set for, so a test can see how long a
 * value would live; nothing expires by itself.
 */
final class MemoryCache implements ICacheFactory, ICache
{
    /** @var array<string, mixed> */
    public array $values = [];

    /** @var array<string, int> */
    public array $ttls = [];

    public function create($prefix = '')
    {
        return $this;
    }

    public function isAvailable()
    {
        return true;
    }

    public function get($key)
    {
        return $this->values[$key] ?? null;
    }

    public function set($key, $value, $ttl = 0)
    {
        $this->values[$key] = $value;
        $this->ttls[$key] = $ttl;

        return true;
    }

    public function hasKey($key)
    {
        return array_key_exists($key, $this->values);
    }

    public function remove($key)
    {
        unset($this->values[$key], $this->ttls[$key]);

        return true;
    }

    public function clear($prefix = '')
    {
        foreach (array_keys($this->values) as $key) {
            if (str_starts_with($key, $prefix)) {
                $this->remove($key);
            }
        }

        return true;
    }
}
