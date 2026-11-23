<?php

declare(strict_types=1);

namespace OCA\OcoSynaplan\Tests\Unit\Support;

use OCP\Lock\LockedException;

/**
 * A lock another request holds. ownCloud's LockedException asks the server
 * for a translation of its message, and unit tests run without a server.
 */
final class Locked extends LockedException
{
    public function __construct(string $path)
    {
        \Exception::__construct('"' . $path . '" is locked');
    }
}
