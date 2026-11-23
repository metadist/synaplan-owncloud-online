<?php

declare(strict_types=1);

namespace OCA\OcoSynaplan\Tests\Unit\Service;

use OCA\OcoSynaplan\Service\NotifyKeyCache;
use OCA\OcoSynaplan\Service\PlatformLinkService;
use OCA\OcoSynaplan\Tests\Unit\Support\MemoryConfig;
use PHPUnit\Framework\TestCase;

final class NotifyKeyCacheTest extends TestCase
{
    private MemoryConfig $config;

    /** @var array{issuer: string, keys: list<array{publicKey: string, kid: string}>}|\Throwable */
    private array|\Throwable $trust;

    private int $asked = 0;

    protected function setUp(): void
    {
        $this->config = new MemoryConfig(['link_instance_id' => 'pi_oc']);
    }

    public function testARefreshKeepsTheIssuerAndKeys(): void
    {
        $this->trust = ['issuer' => 'https://synaplan.example/', 'keys' => [['publicKey' => 'ed25519:abc', 'kid' => 'k1']]];

        $this->cache()->refresh();

        self::assertSame('https://synaplan.example', $this->cache()->issuer());
        self::assertSame([['publicKey' => 'ed25519:abc', 'kid' => 'k1']], $this->cache()->keys());
    }

    public function testAnAnswerWithoutKeysKeepsTheLastGoodOnes(): void
    {
        $this->config->setAppValue('oco_synaplan', 'notify_issuer', 'https://synaplan.example');
        $this->config->setAppValue('oco_synaplan', 'notify_keys', '[{"publicKey":"ed25519:abc","kid":"k1"}]');

        foreach ([['issuer' => 'https://synaplan.example', 'keys' => []], ['issuer' => '', 'keys' => [['publicKey' => 'ed25519:x', 'kid' => 'k9']]], new \RuntimeException('down')] as $trust) {
            $this->trust = $trust;
            $this->cache()->refresh();

            self::assertSame([['publicKey' => 'ed25519:abc', 'kid' => 'k1']], $this->cache()->keys());
            self::assertSame('https://synaplan.example', $this->cache()->issuer());
        }
    }

    public function testAnUnregisteredServerAsksNobody(): void
    {
        $this->config->deleteAppValue('oco_synaplan', 'link_instance_id');
        $this->trust = ['issuer' => 'https://synaplan.example', 'keys' => [['publicKey' => 'ed25519:abc', 'kid' => 'k1']]];

        $this->cache()->refresh();

        self::assertSame(0, $this->asked);
        self::assertSame([], $this->cache()->keys());
    }

    public function testAnUnknownKeyRefreshesAtMostOnceAMinute(): void
    {
        $this->config->setAppValue('oco_synaplan', 'notify_issuer', 'https://synaplan.example');
        $this->config->setAppValue('oco_synaplan', 'notify_keys', '[{"publicKey":"ed25519:old","kid":"k1"}]');
        $this->trust = ['issuer' => 'https://synaplan.example', 'keys' => [['publicKey' => 'ed25519:new', 'kid' => 'k2']]];

        self::assertTrue($this->cache()->refreshForUnknownKey(1000));
        self::assertSame([['publicKey' => 'ed25519:new', 'kid' => 'k2']], $this->cache()->keys());

        $this->config->setAppValue('oco_synaplan', 'notify_keys', '[{"publicKey":"ed25519:old","kid":"k1"}]');
        self::assertFalse($this->cache()->refreshForUnknownKey(1059));
        self::assertSame(1, $this->asked);

        self::assertTrue($this->cache()->refreshForUnknownKey(1060));
        self::assertSame(2, $this->asked);
    }

    public function testUnchangedKeysAreReported(): void
    {
        $this->config->setAppValue('oco_synaplan', 'notify_keys', '[{"publicKey":"ed25519:abc","kid":"k1"}]');
        $this->trust = ['issuer' => 'https://synaplan.example', 'keys' => [['publicKey' => 'ed25519:abc', 'kid' => 'k1']]];

        self::assertFalse($this->cache()->refreshForUnknownKey(1000));
    }

    private function cache(): NotifyKeyCache
    {
        $links = $this->createStub(PlatformLinkService::class);
        $links->method('notificationTrust')->willReturnCallback(function (): array {
            $this->asked++;
            if ($this->trust instanceof \Throwable) {
                throw $this->trust;
            }

            return $this->trust;
        });

        return new NotifyKeyCache($this->config, $links);
    }
}
