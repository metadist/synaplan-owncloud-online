<?php

declare(strict_types=1);

namespace OCA\OcoSynaplan\Tests\Unit\Support;

/**
 * Shaped like the Guzzle exception the ownCloud.online HTTP client throws for
 * an error status: the status as the code, the answer in getResponse().
 */
final class HttpErrorException extends \RuntimeException
{
    public function __construct(
        int $status,
        private string $body = '',
        string $method = 'POST',
        string $path = '/api/v1/summary/generate',
    ) {
        parent::__construct('Client error: `' . $method . ' http://synaplan.example.org' . $path . '` resulted in a `' . $status . '` response', $status);
    }

    public function getResponse(): object
    {
        return new class ($this->body) {
            public function __construct(
                private string $body,
            ) {
            }

            public function getBody(): string
            {
                return $this->body;
            }
        };
    }
}
