<?php

declare(strict_types=1);

/**
 * Synaplan Integration for owncloud.online
 *
 * @copyright Copyright (c) 2026, Synaplan
 * @license Apache-2.0
 */

namespace OCA\OcoSynaplan\Exception;

use OCA\OcoSynaplan\Http\Failure;
use OCP\AppFramework\Http;
use OCP\Files\AlreadyExistsException;
use OCP\Files\ForbiddenException;
use OCP\Files\NotEnoughSpaceException;
use OCP\Files\NotPermittedException;

/**
 * A state the app recognises itself, with the failure the browser gets. The
 * exception message is for the log.
 */
final class FailureException extends \RuntimeException
{
    /**
     * @param bool $passing whether trying again later may succeed, as for
     *                      an answer Synaplan sent that could not be read
     */
    public function __construct(
        public readonly Failure $failure,
        string $logMessage,
        public readonly bool $passing = false,
    ) {
        parent::__construct($logMessage);
    }

    public static function notConnected(): self
    {
        return new self(new Failure(Http::STATUS_CONFLICT, Failure::NOT_CONNECTED), 'No Synaplan account connected');
    }

    /**
     * 409, not 401: ownCloud.online's pages treat a 401 as their own session
     * ending.
     */
    public static function connectionEnded(): self
    {
        return new self(new Failure(Http::STATUS_CONFLICT, Failure::CONNECTION_ENDED), 'Synaplan refused the connected account');
    }

    public static function consentRequired(): self
    {
        return new self(new Failure(Http::STATUS_CONFLICT, Failure::CONSENT_REQUIRED), 'AI not activated by the user');
    }

    public static function accountUnavailable(): self
    {
        return new self(
            new Failure(Http::STATUS_BAD_GATEWAY, Failure::ACCOUNT_UNAVAILABLE),
            'No Synaplan key for a user who activated AI: the account could not be created or the key was refused',
        );
    }

    public static function fileTooLarge(int $maxBytes): self
    {
        return new self(
            new Failure(Http::STATUS_REQUEST_ENTITY_TOO_LARGE, Failure::FILE_TOO_LARGE),
            'File larger than ' . $maxBytes . ' bytes',
        );
    }

    public static function copyTooLarge(int $maxBytes): self
    {
        return new self(
            new Failure(Http::STATUS_REQUEST_ENTITY_TOO_LARGE, Failure::COPY_TOO_LARGE),
            'File from Synaplan larger than ' . $maxBytes . ' bytes',
        );
    }

    /**
     * What writing a file from Synaplan into the person's files ran into, as
     * the person's failure; anything else is returned unchanged. Reading
     * throws the same exceptions with another meaning, so only writes ask.
     */
    public static function fromWrite(\Throwable $e): \Throwable
    {
        $failure = match (true) {
            $e instanceof NotEnoughSpaceException => new Failure(Http::STATUS_INSUFFICIENT_STORAGE, Failure::QUOTA_EXCEEDED),
            $e instanceof AlreadyExistsException => new Failure(Http::STATUS_CONFLICT, Failure::NAME_TAKEN),
            $e instanceof NotPermittedException, $e instanceof ForbiddenException => new Failure(Http::STATUS_FORBIDDEN, Failure::FOLDER_READ_ONLY),
            default => null,
        };

        return $failure === null ? $e : new self($failure, 'Could not write the file: ' . $e->getMessage());
    }

    public static function unsupportedType(string $mimeType): self
    {
        return new self(
            new Failure(Http::STATUS_UNSUPPORTED_MEDIA_TYPE, Failure::UNSUPPORTED_TYPE),
            'Unsupported file type ' . $mimeType,
        );
    }

    public static function fileEmpty(): self
    {
        return new self(new Failure(Http::STATUS_UNPROCESSABLE_ENTITY, Failure::FILE_EMPTY), 'File is empty');
    }

    public static function noText(string $fileName): self
    {
        return new self(
            new Failure(Http::STATUS_UNPROCESSABLE_ENTITY, Failure::NO_TEXT),
            'No text extracted from ' . $fileName,
        );
    }

    /**
     * Synaplan accepted the request but refused the file and said why (storage
     * quota, rate limit, unsupported type) in a sentence of its own.
     */
    public static function refusedBySynaplan(string $sentence): self
    {
        return new self(
            Failure::fromSynaplanResponse(Http::STATUS_UNPROCESSABLE_ENTITY, (string) json_encode(['error' => $sentence])),
            'Synaplan refused the file: ' . $sentence,
        );
    }

    public static function translationUnavailable(): self
    {
        return new self(
            new Failure(Http::STATUS_UNPROCESSABLE_ENTITY, Failure::TRANSLATION_UNAVAILABLE),
            'Connected Synaplan does not list translation',
        );
    }

    public static function invalidSynaplanAnswer(): self
    {
        return new self(new Failure(Http::STATUS_BAD_GATEWAY, Failure::SYNAPLAN_ERROR), 'Invalid JSON response from Synaplan API', true);
    }
}
