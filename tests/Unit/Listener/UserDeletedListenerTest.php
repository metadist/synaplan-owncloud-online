<?php

declare(strict_types=1);

namespace OCA\OcoSynaplan\Tests\Unit\Listener;

use OCA\OcoSynaplan\Listener\UserDeletedListener;
use OCA\OcoSynaplan\Service\UserAccountService;
use OCA\OcoSynaplan\Tests\Unit\Support\BuildsServices;
use OCA\OcoSynaplan\Tests\Unit\Support\FakeCrypto;
use PHPUnit\Framework\TestCase;

final class UserDeletedListenerTest extends TestCase
{
    use BuildsServices;

    private const LIFECYCLE = '/oauth/register/client-1/lifecycle';

    protected function setUp(): void
    {
        $this->setUpServices(['mode' => 'provision', 'api_key' => FakeCrypto::stored('sk_admin')]);
    }

    public function testAProvisionedPersonIsReportedDeletedAndForgotten(): void
    {
        $this->register();
        $this->provisioned('carol');
        $this->http->answer(static fn (): array => ['success' => true, 'account' => 'deleted', 'status' => 'revoked', 'message' => '']);

        $this->listener()->handle($this->person('carol'));

        self::assertSame(['external_id' => 'carol', 'event' => 'deleted'], $this->http->to(self::LIFECYCLE)[0]['body']);
        self::assertCount(1, $this->http->calls, 'Synaplan revoked the key with the identity');
        self::assertSame('', $this->keys()->getUserKey('carol'));
    }

    public function testAConnectedPersonIsReportedDeletedWithoutRenewingTheirToken(): void
    {
        $this->config->setAppValue('oco_synaplan', 'mode', 'link');
        $this->register();
        $this->connect('ada', 'access-old', -60);
        $this->config->setUserValue('ada', 'oco_synaplan', UserAccountService::LINK_KIND_PREF, UserAccountService::KIND_LINKED);

        $this->listener()->handle($this->person('ada'));

        self::assertSame(['POST ' . self::LIFECYCLE], array_map(
            static fn (array $call): string => $call['method'] . ' ' . $call['path'],
            $this->http->calls,
        ), 'no token renewal and no revocation: Synaplan ended the grant');
        self::assertFalse($this->oauth()->isConnected('ada'));
    }

    public function testWhenSynaplanCannotBeToldTheKeyIsStillRevoked(): void
    {
        $this->register();
        $this->provisioned('carol');
        $this->http->answer(static fn (string $method, string $path): array|\Throwable => $path === self::LIFECYCLE
            ? new \RuntimeException('cURL error 7')
            : ['success' => true]);

        $this->listener()->handle($this->person('carol'));

        self::assertSame('sk_carol', $this->http->to('/api/v1/apikeys/9')[0]['options']['headers']['X-API-Key']);
        self::assertSame('', $this->keys()->getUserKey('carol'));
    }

    public function testWithoutARegistrationTheKeyIsRevokedWithItself(): void
    {
        $this->provisioned('carol');

        $this->listener()->handle($this->person('carol'));

        self::assertSame([], $this->http->to(self::LIFECYCLE));
        self::assertSame('DELETE', $this->http->to('/api/v1/apikeys/9')[0]['method']);
    }

    public function testAnAccountTheAppOnlyRemembersIsStillReported(): void
    {
        $this->register();
        $this->config->setUserValue('bob', 'oco_synaplan', UserAccountService::USER_ACCOUNT_ID_PREF, '5');

        $this->listener()->handle($this->person('bob'));

        self::assertSame(['external_id' => 'bob', 'event' => 'deleted'], $this->http->to(self::LIFECYCLE)[0]['body']);
    }

    public function testAPersonWhoNeverUsedSynaplanIsNotReported(): void
    {
        $this->register();

        $this->listener()->handle($this->person('dave'));

        self::assertSame([], $this->http->calls);
    }

    private function provisioned(string $uid): void
    {
        $this->keys()->setUserKey($uid, 'sk_' . $uid);
        $this->config->setUserValue($uid, 'oco_synaplan', UserAccountService::USER_KEY_ID_PREF, '9');
        $this->config->setUserValue($uid, 'oco_synaplan', UserAccountService::USER_ACCOUNT_ID_PREF, '4');
    }

    private function listener(): UserDeletedListener
    {
        return new UserDeletedListener($this->accounts(), $this->links());
    }
}
