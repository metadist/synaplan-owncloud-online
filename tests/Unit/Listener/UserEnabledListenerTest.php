<?php

declare(strict_types=1);

namespace OCA\OcoSynaplan\Tests\Unit\Listener;

use OCA\OcoSynaplan\Listener\UserEnabledListener;
use OCA\OcoSynaplan\Service\UserAccountService;
use OCA\OcoSynaplan\Tests\Unit\Support\BuildsServices;
use OCA\OcoSynaplan\Tests\Unit\Support\FakeCrypto;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

final class UserEnabledListenerTest extends TestCase
{
    use BuildsServices;

    private const LIFECYCLE = '/oauth/register/client-1/lifecycle';

    protected function setUp(): void
    {
        $this->setUpServices(['mode' => 'provision', 'api_key' => FakeCrypto::stored('sk_admin')]);
        $this->register();
        $this->keys()->setUserKey('carol', 'sk_carol');
    }

    public function testDisablingAPersonPausesThemInSynaplan(): void
    {
        $this->listener()->handle($this->account('carol', false));

        self::assertSame(['external_id' => 'carol', 'event' => 'disabled'], $this->http->to(self::LIFECYCLE)[0]['body']);
    }

    public function testEnablingAPersonResumesThem(): void
    {
        $this->listener()->handle($this->account('carol', true));

        self::assertSame(['external_id' => 'carol', 'event' => 'enabled'], $this->http->to(self::LIFECYCLE)[0]['body']);
    }

    public function testAPersonAnAdminDeactivatedStaysPausedWhenTheirAccountIsEnabled(): void
    {
        $this->config->setUserValue('carol', 'oco_synaplan', UserAccountService::PAUSED_PREF, '1');

        $this->listener()->handle($this->account('carol', true));
        self::assertSame([], $this->http->calls);

        $this->listener()->handle($this->account('carol', false));
        self::assertSame('disabled', $this->http->to(self::LIFECYCLE)[0]['body']['event']);
    }

    public function testAConnectedPersonIsReportedWithoutRenewingTheirToken(): void
    {
        $this->connect('ada', 'access-old', -60);

        $this->listener()->handle($this->account('ada', false));

        self::assertSame(['POST ' . self::LIFECYCLE], array_map(
            static fn (array $call): string => $call['method'] . ' ' . $call['path'],
            $this->http->calls,
        ));
    }

    public function testAPersonWhoNeverUsedSynaplanIsNotReported(): void
    {
        $this->listener()->handle($this->account('dave', false));

        self::assertSame([], $this->http->calls);
    }

    public function testNothingIsSentWithoutARegistration(): void
    {
        $this->setUpServices(['mode' => 'provision']);
        $this->keys()->setUserKey('carol', 'sk_carol');

        $this->listener()->handle($this->account('carol', false));

        self::assertSame([], $this->http->calls);
    }

    private function account(string $uid, bool $enabled): IUser
    {
        $user = $this->createStub(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $user->method('isEnabled')->willReturn($enabled);

        return $user;
    }

    private function listener(): UserEnabledListener
    {
        return new UserEnabledListener($this->accounts(), $this->links());
    }
}
