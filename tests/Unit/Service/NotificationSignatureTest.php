<?php

declare(strict_types=1);

namespace OCA\OcoSynaplan\Tests\Unit\Service;

use OCA\OcoSynaplan\Service\NotificationSignature;
use PHPUnit\Framework\TestCase;

final class NotificationSignatureTest extends TestCase
{
    /** @var array{issuer: string, receiver_instance_id: string, public_key: string, now: int, vectors: list<array{name: string, expect: string, token: string}>} */
    private array $fixture;

    protected function setUp(): void
    {
        $fixture = json_decode((string) file_get_contents(__DIR__ . '/../../fixtures/synaplan-contract/notify-vectors.json'), true);
        self::assertIsArray($fixture);
        $this->fixture = $fixture;
    }

    public function testSynaplansOwnVectors(): void
    {
        foreach ($this->fixture['vectors'] as $vector) {
            self::assertSame($vector['expect'], $this->verify($vector['token']), $vector['name']);
        }
    }

    public function testAnUnsignedNoticeIsRefused(): void
    {
        [$header, $payload] = explode('.', $this->fixture['vectors'][0]['token']);

        self::assertSame(NotificationSignature::BAD_SIGNATURE, $this->verify($header . '.' . $payload . '.'));
        self::assertSame(NotificationSignature::BAD_SIGNATURE, $this->verify('{"type":"delivery","id":"9"}'));
    }

    public function testANoticeIsRefusedUntilThisServerKnowsWhoSignsThem(): void
    {
        $token = $this->fixture['vectors'][0]['token'];

        self::assertSame(NotificationSignature::WRONG_ISSUER, $this->verify($token, issuer: ''));
        self::assertSame(NotificationSignature::BAD_SIGNATURE, $this->verify($token, keys: []));
    }

    public function testANoticeExpiresAtExp(): void
    {
        $token = $this->fixture['vectors'][0]['token'];
        $exp = (int) NotificationSignature::claims($token)['exp'];

        self::assertSame(NotificationSignature::OK, $this->verify($token, now: $exp - 1));
        self::assertSame(NotificationSignature::STALE, $this->verify($token, now: $exp));
    }

    /**
     * @param list<array<string, mixed>>|null $keys
     */
    private function verify(string $token, ?string $issuer = null, ?array $keys = null, ?int $now = null): string
    {
        return NotificationSignature::verify(
            $token,
            $issuer ?? $this->fixture['issuer'],
            $this->fixture['receiver_instance_id'],
            $keys ?? [['publicKey' => $this->fixture['public_key']]],
            $now ?? $this->fixture['now'],
        );
    }
}
