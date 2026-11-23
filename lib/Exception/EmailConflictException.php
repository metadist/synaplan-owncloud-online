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
 * Synaplan already has an account for this email (HTTP 409 when creating one).
 *
 * In link mode the UI offers to connect that account; installs that only
 * create accounts keep this as a hard failure.
 */
class EmailConflictException extends \RuntimeException
{
    public function __construct(string $message = 'An account with this email already exists.')
    {
        parent::__construct($message);
    }
}
