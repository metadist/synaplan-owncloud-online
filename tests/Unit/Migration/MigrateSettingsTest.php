<?php

declare(strict_types=1);

namespace OCA\OcoSynaplan\Tests\Unit\Migration;

use OCA\OcoSynaplan\Migration\MigrateSettings;
use OCA\OcoSynaplan\Service\KeyStorage;
use OCA\OcoSynaplan\Tests\Unit\Support\BuildsServices;
use OCA\OcoSynaplan\Tests\Unit\Support\FakeCrypto;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

final class MigrateSettingsTest extends TestCase
{
    use BuildsServices;

    public function testRelease100SettingsAreCarriedOverAndEveryPlainKeyIsEncrypted(): void
    {
        $this->setUpServices([
            'per_user_accounts' => '1',
            'api_key' => 'sk_admin_plain',
            'api_key_local' => 'sk_local_plain',
        ]);
        $this->config->setUserValue('ada', 'oco_synaplan', KeyStorage::USER_KEY, 'sk_ada_plain');
        $this->config->setUserValue('bob', 'oco_synaplan', KeyStorage::USER_KEY, FakeCrypto::stored('sk_bob'));

        $output = $this->createMock(IOutput::class);
        $output->expects($this->exactly(2))->method('info');
        (new MigrateSettings($this->synaplanConfig(), $this->keys(), $this->db()))->run($output);

        self::assertSame('provision', $this->config->appValue('mode'));
        self::assertNull($this->config->appValue('per_user_accounts'));
        self::assertSame(FakeCrypto::stored('sk_admin_plain'), $this->config->appValue('api_key'));
        self::assertSame(FakeCrypto::stored('sk_local_plain'), $this->config->appValue('api_key_local'));
        self::assertSame(FakeCrypto::stored('sk_ada_plain'), $this->config->userValue('ada', KeyStorage::USER_KEY));
        self::assertSame(FakeCrypto::stored('sk_bob'), $this->config->userValue('bob', KeyStorage::USER_KEY));
    }

    public function testRunningAgainChangesNothing(): void
    {
        $this->setUpServices(['mode' => 'link', 'api_key' => FakeCrypto::stored('sk_admin')]);
        $before = $this->config->app;

        (new MigrateSettings($this->synaplanConfig(), $this->keys(), $this->db()))->run($this->createStub(IOutput::class));

        self::assertSame($before, $this->config->app);
    }
}
