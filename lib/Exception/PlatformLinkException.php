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
 * A failed exchange with Synaplan's OAuth2 server, with a machine-readable
 * code; the connect callback puts it into its redirect.
 */
class PlatformLinkException extends \RuntimeException
{
    public const CODE_STATE = 'state';
    public const CODE_EXCHANGE = 'exchange';
    public const CODE_INSTANCE_PENDING = 'instance_pending';
    /** Synaplan ended the grant (revoked, or the link was disconnected there). */
    public const CODE_GRANT_ENDED = 'grant_ended';
    /**
     * Synaplan does not accept this ownCloud.online as its client: the
     * registration was revoked or removed there. It ends for everybody until
     * an administrator registers again.
     */
    public const CODE_CLIENT_REFUSED = 'client_refused';
    public const CODE_UNREACHABLE = 'unreachable';
    public const CODE_NOT_REGISTERED = 'not_registered';
    /** The person did not allow the connection on Synaplan's consent page. */
    public const CODE_DENIED = 'denied';
    /** Synaplan refused what this server said about itself (RFC 7591 invalid_client_metadata). */
    public const CODE_METADATA_REFUSED = 'metadata_refused';
    /**
     * Synaplan does not call the delivery address: it is not on this
     * server's registered host, and its administrator has not approved it.
     */
    public const CODE_DELIVERY_REFUSED = 'delivery_refused';

    public function __construct(
        string $message,
        private string $errorCode = self::CODE_EXCHANGE,
    ) {
        parent::__construct($message);
    }

    public function getErrorCode(): string
    {
        return $this->errorCode;
    }
}
