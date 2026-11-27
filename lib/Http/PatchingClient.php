<?php

declare(strict_types=1);

/**
 * Synaplan Integration for owncloud.online
 *
 * @copyright Copyright (c) 2026, Synaplan
 * @license Apache-2.0
 */

namespace OCA\OcoSynaplan\Http;

use OCP\Http\Client\IClient;
use OCP\Http\Client\IResponse;

/**
 * An HTTP client that can also send PATCH, which ownCloud.online's IClient
 * lacks and Synaplan asks for to pause or resume a watch.
 */
interface PatchingClient extends IClient
{
    /**
     * @param array<string, mixed> $options `headers`, `body` and `timeout`, as for put()
     * @throws \Exception on a transport error or an error status, with the status as its code
     */
    public function patch(string $uri, array $options = []): IResponse;
}
