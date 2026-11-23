<?php

declare(strict_types=1);

/**
 * Synaplan Integration for owncloud.online
 *
 * @copyright Copyright (c) 2026, Synaplan
 * @license Apache-2.0
 */

namespace OCA\OcoSynaplan\Exception;

/**
 * Synaplan answered with an error status. The body stays with it so the
 * person can be shown Synaplan's own sentence (see Failure).
 */
final class SynaplanHttpException extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        string $message = '',
    ) {
        parent::__construct($message !== '' ? $message : 'Synaplan returned HTTP ' . $status, $status);
    }
}
