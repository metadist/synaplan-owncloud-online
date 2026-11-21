<?php

declare(strict_types=1);

namespace OCA\OcoSynaplan\Tests\Unit\Service;

use OCA\OcoSynaplan\Service\KeyStorage;
use OCA\OcoSynaplan\Tests\Unit\Support\FakeCrypto;
use OCA\OcoSynaplan\Tests\Unit\Support\MemoryConfig;
use OCP\ILogger;
use PHPUnit\Framework\TestCase;

final class KeyStorageTest extends TestCase
{
    private MemoryConfig $config;

    protected function setUp(): void
    {
        $this->config = new MemoryConfig();
    }

    public function testAPersonsKeyIsStoredEncryptedWithThePrefix(): void
    {
        $this->storage()->setUserKey('ada', 'sk_ada');

        self::assertSame(FakeCrypto::stored('sk_ada'), $this->config->userValue('ada', KeyStorage::USER_KEY));
        self::assertStringNotContainsString('sk_ada', (string) $this->config->userValue('ada', KeyStorage::USER_KEY));
        self::assertSame('sk_ada', $this->storage()->getUserKey('ada'));
    }

    public function testAPlainTextKeyFromRelease100IsReadOnceAndSavedEncrypted(): void
    {
        $this->config->setUserValue('ada', 'oco_synaplan', KeyStorage::USER_KEY, 'sk_from_1_0');

        self::assertSame('sk_from_1_0', $this->storage()->getUserKey('ada'));
        self::assertSame(FakeCrypto::stored('sk_from_1_0'), $this->config->userValue('ada', KeyStorage::USER_KEY));
        self::assertSame('sk_from_1_0', $this->storage()->getUserKey('ada'));
    }

    public function testAKeyThatNoLongerDecryptsIsRemovedAndLoggedWithoutItsValue(): void
    {
        $this->config->setUserValue('ada', 'oco_synaplan', KeyStorage::USER_KEY, 'enc:written-with-another-secret');
        $logger = $this->createMock(ILogger::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with(
                $this->stringContains('could not be decrypted'),
                $this->callback(static fn (array $context): bool => !str_contains((string) json_encode($context), 'another-secret')),
            );

        self::assertNull($this->storage($logger)->getUserKey('ada'));
        self::assertNull($this->config->userValue('ada', KeyStorage::USER_KEY));
    }

    public function testNoKeyStaysEmpty(): void
    {
        self::assertSame('', $this->storage()->getUserKey('bob'));
        self::assertSame([], $this->config->user);
    }

    public function testAnEmptyValueDeletesTheSecret(): void
    {
        $this->storage()->setUserSecret('ada', 'synaplan_oauth_refresh', 'refresh-1');
        $this->storage()->setUserSecret('ada', 'synaplan_oauth_refresh', '');

        self::assertNull($this->config->userValue('ada', 'synaplan_oauth_refresh'));
    }

    public function testTheAdministratorKeyIsStoredEncryptedAndAPlainOneIsEncryptedOnFirstRead(): void
    {
        $this->storage()->setAppKey('api_key', 'sk_admin');
        self::assertSame(FakeCrypto::stored('sk_admin'), $this->config->appValue('api_key'));

        $this->config->setAppValue('oco_synaplan', 'api_key_local', 'sk_local_plain');
        self::assertSame('sk_local_plain', $this->storage()->getAppKey('api_key_local'));
        self::assertSame(FakeCrypto::stored('sk_local_plain'), $this->config->appValue('api_key_local'));
    }

    public function testAnAdministratorKeyThatNoLongerDecryptsIsRemoved(): void
    {
        $this->config->setAppValue('oco_synaplan', 'api_key', 'enc:written-with-another-secret');

        self::assertSame('', $this->storage()->getAppKey('api_key'));
        self::assertNull($this->config->appValue('api_key'));
    }

    public function testOpenReadsAStoredValueWithoutWritingIt(): void
    {
        self::assertSame('', $this->storage()->open(''));
        self::assertSame('plain', $this->storage()->open('plain'));
        self::assertSame('secret', $this->storage()->open(FakeCrypto::stored('secret')));
        self::assertNull($this->storage()->open('enc:written-with-another-secret'));
        self::assertSame([], $this->config->user);
    }

    private function storage(?ILogger $logger = null): KeyStorage
    {
        return new KeyStorage($this->config, new FakeCrypto(), $logger ?? $this->createStub(ILogger::class));
    }
}
