<?php

declare(strict_types=1);

namespace OCA\OcoSynaplan\Tests\Unit\Service;

use OCA\OcoSynaplan\Service\SynaplanCredential;
use PHPUnit\Framework\TestCase;

final class SynaplanCredentialTest extends TestCase
{
    public function testAnApiKeyTravelsAsXApiKey(): void
    {
        self::assertSame(['X-API-Key' => 'sk_abc123'], SynaplanCredential::header('sk_abc123'));
    }

    public function testAnAccessTokenTravelsAsBearer(): void
    {
        self::assertSame(['Authorization' => 'Bearer eyJhbGciOi.x.y'], SynaplanCredential::header('eyJhbGciOi.x.y'));
    }

    public function testOnlyTheSkPrefixMakesAnApiKey(): void
    {
        self::assertSame(['Authorization' => 'Bearer ask_abc'], SynaplanCredential::header('ask_abc'));
        self::assertSame(['Authorization' => 'Bearer SK_abc'], SynaplanCredential::header('SK_abc'));
    }

    public function testNoCredentialSendsOnlyAccept(): void
    {
        self::assertSame(['Accept' => 'application/json'], SynaplanCredential::headers(''));
        self::assertSame(['Accept: text/event-stream', 'X-API-Key: sk_abc'], SynaplanCredential::curlHeaders('sk_abc', 'text/event-stream'));
    }
}
