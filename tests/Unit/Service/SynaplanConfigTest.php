<?php

declare(strict_types=1);

namespace OCA\OcoSynaplan\Tests\Unit\Service;

use OCA\OcoSynaplan\Service\SynaplanConfig;
use OCA\OcoSynaplan\Tests\Unit\Support\BuildsServices;
use PHPUnit\Framework\TestCase;

final class SynaplanConfigTest extends TestCase
{
    use BuildsServices;

    protected function setUp(): void
    {
        $this->setUpServices();
    }

    public function testAnInstallFrom100WithoutPerUserAccountsBecomesShared(): void
    {
        unset($this->config->app['oco_synaplan']['mode']);

        self::assertSame(SynaplanConfig::MODE_SHARED, $this->synaplanConfig()->getMode());
        self::assertSame('shared', $this->config->appValue('mode'));
        self::assertFalse($this->synaplanConfig()->isPerUserAccountsEnabled());
    }

    public function testPerUserAccountsOf100BecomesProvisionAndTheBooleanGoes(): void
    {
        $this->config->setAppValue('oco_synaplan', 'per_user_accounts', '1');

        self::assertSame(SynaplanConfig::MODE_PROVISION, $this->synaplanConfig()->migrateMode());
        self::assertSame('provision', $this->config->appValue('mode'));
        self::assertNull($this->config->appValue('per_user_accounts'));
        self::assertTrue($this->synaplanConfig()->isPerUserAccountsEnabled());
    }

    public function testAStoredModeWinsOverTheOldBoolean(): void
    {
        $this->config->setAppValue('oco_synaplan', 'per_user_accounts', '1');
        $this->config->setAppValue('oco_synaplan', 'mode', 'link');

        self::assertSame(SynaplanConfig::MODE_LINK, $this->synaplanConfig()->getMode());
        self::assertTrue($this->synaplanConfig()->isLinkMode());
    }

    public function testOnlyTheThreeModesOfOwnCloudOnlineCanBeChosen(): void
    {
        self::assertTrue($this->synaplanConfig()->setMode('provision'));
        self::assertFalse($this->synaplanConfig()->setMode('oidc'));
        self::assertFalse($this->synaplanConfig()->setMode(''));
        self::assertSame('provision', $this->synaplanConfig()->getMode());
    }

    public function testConnectingNeedsLinkModeAndARegistration(): void
    {
        $this->synaplanConfig()->setMode('link');
        self::assertFalse($this->synaplanConfig()->isLinkAvailable());

        $this->config->setAppValue('oco_synaplan', 'link_instance_id', 'client-1');
        self::assertFalse($this->synaplanConfig()->isLinkAvailable(), 'a client id without its key is no registration');

        $this->config->setAppValue('oco_synaplan', 'oauth_kid', 'kid-1');
        self::assertFalse($this->synaplanConfig()->isLinkAvailable(), 'a key id without the key is no registration');

        $this->keys()->setAppKey('oauth_private_key', self::clientKey());
        self::assertTrue($this->synaplanConfig()->isLinkAvailable());

        $this->synaplanConfig()->setMode('provision');
        self::assertFalse($this->synaplanConfig()->isLinkAvailable());
        self::assertTrue($this->synaplanConfig()->isRegistered());
    }

    public function testAKeyThatNoLongerDecryptsIsNoRegistration(): void
    {
        $this->register();
        $this->synaplanConfig()->setMode('link');
        $this->config->setAppValue('oco_synaplan', 'oauth_private_key', 'enc:sealed-with-another-secret');

        self::assertFalse($this->synaplanConfig()->isLinkAvailable());
        self::assertFalse($this->synaplanConfig()->isRegistered());
        self::assertFalse($this->oauth()->isRegistered(), 'the settings page and the handshake agree');
    }

    public function testCreatingAccountsInLinkModeNeedsTheSwitchAndTheAdministratorKey(): void
    {
        $this->synaplanConfig()->setMode('link');
        $this->config->setAppValue('oco_synaplan', 'link_auto_provision', '1');
        self::assertFalse($this->synaplanConfig()->isAutoProvisionEnabled());

        $this->keys()->setAppKey('api_key', 'sk_admin');
        self::assertTrue($this->synaplanConfig()->isAutoProvisionEnabled());

        $this->synaplanConfig()->setMode('provision');
        self::assertFalse($this->synaplanConfig()->isAutoProvisionEnabled());
    }

    public function testTheLocalEnvironmentHasItsOwnAddressAndKey(): void
    {
        $this->keys()->setAppKey('api_key', 'sk_live');
        $this->keys()->setAppKey('api_key_local', 'sk_local');
        $this->config->setAppValue('oco_synaplan', 'synaplan_url_local', 'http://host.docker.internal:8001/');
        self::assertSame(self::SYNAPLAN, $this->synaplanConfig()->getBaseUrl());
        self::assertSame('sk_live', $this->synaplanConfig()->getAdminApiKey());

        $this->config->setAppValue('oco_synaplan', 'active_env', 'local');
        self::assertSame('http://host.docker.internal:8001', $this->synaplanConfig()->getBaseUrl());
        self::assertSame('sk_local', $this->synaplanConfig()->getAdminApiKey());
    }

    public function testThePublicAddressFallsBackToTheApiAddress(): void
    {
        self::assertSame(self::SYNAPLAN, $this->synaplanConfig()->getPublicBaseUrl());

        $this->config->setAppValue('oco_synaplan', 'synaplan_public_url', 'http://localhost:8001/');
        self::assertSame('http://localhost:8001', $this->synaplanConfig()->getPublicBaseUrl());
    }

    public function testTheDevelopmentSwitchesAreOffUntilSetAndNoOtherNameCounts(): void
    {
        foreach ([SynaplanConfig::DEV_HOLD_INBOUND, SynaplanConfig::DEV_MUTE_EVENTS, SynaplanConfig::DEV_ALLOW_SELF_WRITE] as $name) {
            self::assertFalse($this->synaplanConfig()->isDevSwitchOn($name), $name);
            $this->config->setAppValue('oco_synaplan', $name, '1');
            self::assertTrue($this->synaplanConfig()->isDevSwitchOn($name), $name);
        }

        $this->config->setAppValue('oco_synaplan', 'dev_anything', '1');
        self::assertFalse($this->synaplanConfig()->isDevSwitchOn('dev_anything'));
    }
}
