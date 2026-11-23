<?php

declare(strict_types=1);

/**
 * Stub of ownCloud's OC_Util: the person's filesystem, set up with the
 * server's storage rules and torn down again.
 */
class OC_Util
{
    /**
     * @param string $user
     * @param bool $fullInit
     * @return bool
     */
    public static function setupFS($user = '', $fullInit = true)
    {
        return true;
    }

    /**
     * @return void
     */
    public static function tearDownFS()
    {
    }
}
