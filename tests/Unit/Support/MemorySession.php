<?php

declare(strict_types=1);

namespace OCA\OcoSynaplan\Tests\Unit\Support;

use OCP\ISession;

final class MemorySession implements ISession
{
    /** @var array<string, mixed> */
    public array $values = [];

    public function set($key, $value): void
    {
        $this->values[$key] = $value;
    }

    public function get($key)
    {
        return $this->values[$key] ?? null;
    }

    public function exists($key)
    {
        return array_key_exists($key, $this->values);
    }

    public function remove($key): void
    {
        unset($this->values[$key]);
    }

    public function clear(): void
    {
        $this->values = [];
    }

    public function close(): void
    {
    }

    public function regenerateId($deleteOldSession = true)
    {
    }

    public function getId()
    {
        return 'session-1';
    }
}
