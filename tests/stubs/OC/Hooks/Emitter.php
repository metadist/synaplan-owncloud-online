<?php

declare(strict_types=1);

namespace OC\Hooks;

/**
 * Stub of ownCloud's private OC\Hooks\Emitter, which OCP\Files\IRootFolder
 * extends; the signatures are the server's.
 */
interface Emitter
{
    /**
     * @param string $scope
     * @param string $method
     * @return void
     */
    public function listen($scope, $method, callable $callback);

    /**
     * @param string|null $scope
     * @param string|null $method
     * @return void
     */
    public function removeListener($scope = null, $method = null, ?callable $callback = null);
}
