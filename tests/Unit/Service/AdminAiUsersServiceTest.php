<?php

declare(strict_types=1);

namespace OCA\OcoSynaplan\Tests\Unit\Service;

use OCA\OcoSynaplan\Service\AdminAiUsersService;
use OCA\OcoSynaplan\Service\UserAccountService;
use OCA\OcoSynaplan\Tests\Unit\Support\BuildsServices;
use OCA\OcoSynaplan\Tests\Unit\Support\FakeCrypto;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

final class AdminAiUsersServiceTest extends TestCase
{
    use BuildsServices;

    protected function setUp(): void
    {
        $this->setUpServices(['mode' => 'provision', 'api_key' => FakeCrypto::stored('sk_admin')]);
    }

    public function testTheListShowsWhoActivatedAiAndHowTheyAreConnected(): void
    {
        foreach (['carol', 'ada'] as $uid) {
            $this->config->setUserValue($uid, 'oco_synaplan', UserAccountService::CONSENT_PREF, '1');
            $this->config->setUserValue($uid, 'oco_synaplan', UserAccountService::CONSENT_AT_PREF, '2026-11-01T10:00:00+00:00');
        }
        $this->keys()->setUserKey('ada', 'sk_ada');
        $this->config->setUserValue('ada', 'oco_synaplan', UserAccountService::USER_ACCOUNT_ID_PREF, '7');
        $this->config->setUserValue('bob', 'oco_synaplan', UserAccountService::USER_ACCOUNT_ID_PREF, '8');

        $users = $this->service()->listActivatedUsers();

        self::assertSame(['ada', 'carol'], array_column($users, 'uid'));
        self::assertSame(['Ada', 'ada@example.org', 7, true, 'provisioned'], [
            $users[0]['displayName'],
            $users[0]['email'],
            $users[0]['synaplanUserId'],
            $users[0]['hasKey'],
            $users[0]['kind'],
        ]);
        self::assertSame([null, false, null], [$users[1]['synaplanUserId'], $users[1]['hasKey'], $users[1]['kind']]);
        self::assertStringNotContainsString('sk_ada', (string) json_encode($users), 'keys never reach the browser');
    }

    public function testTheListReadsAConnectionWithoutRenewingItsToken(): void
    {
        $this->config->setAppValue('oco_synaplan', 'mode', 'link');
        $this->register();
        $this->connect('ada', 'access-old', -60);
        $this->config->setUserValue('ada', 'oco_synaplan', UserAccountService::CONSENT_PREF, '1');
        $this->config->setUserValue('ada', 'oco_synaplan', UserAccountService::LINK_KIND_PREF, 'linked');

        $users = $this->service()->listActivatedUsers();

        self::assertSame([true, 'linked'], [$users[0]['hasKey'], $users[0]['kind']]);
        self::assertSame([], $this->http->calls, 'opening the list costs nobody a token renewal');
    }

    public function testDeactivatingRevokesTheKeyAndForgetsTheConsent(): void
    {
        $this->config->setUserValue('ada', 'oco_synaplan', UserAccountService::CONSENT_PREF, '1');
        $this->keys()->setUserKey('ada', 'sk_ada');
        $this->config->setUserValue('ada', 'oco_synaplan', UserAccountService::USER_KEY_ID_PREF, '11');

        $this->service()->deactivate('ada');

        self::assertSame('DELETE', $this->http->to('/api/v1/apikeys/11')[0]['method']);
        self::assertNull($this->config->userValue('ada', UserAccountService::CONSENT_PREF));
        self::assertSame('', $this->keys()->getUserKey('ada'));
    }

    public function testDeactivatingStillForgetsLocallyWhenSynaplanCannotBeReached(): void
    {
        $this->config->setUserValue('ada', 'oco_synaplan', UserAccountService::CONSENT_PREF, '1');
        $this->keys()->setUserKey('ada', 'sk_ada');
        $this->config->setUserValue('ada', 'oco_synaplan', UserAccountService::USER_KEY_ID_PREF, '11');
        $this->http->answer(static fn (): \Throwable => new \RuntimeException('cURL error 7'));

        $this->service()->deactivate('ada');

        self::assertSame('', $this->keys()->getUserKey('ada'));
    }

    public function testUsageIsAskedWithTheInstallKeyForAnAccountTheAppKnows(): void
    {
        self::assertFalse($this->service()->usage('ada')['success']);
        self::assertSame([], $this->http->calls);

        $this->config->setUserValue('ada', 'oco_synaplan', UserAccountService::USER_ACCOUNT_ID_PREF, '7');
        $this->http->answer(static fn (): array => ['success' => true, 'usage' => ['messages' => 3]]);

        self::assertSame(['success' => true, 'usage' => ['messages' => 3]], $this->service()->usage('ada'));
        self::assertSame('sk_admin', $this->http->to('/api/v1/admin/users/7/usage')[0]['options']['headers']['X-API-Key']);
    }

    private function service(): AdminAiUsersService
    {
        $users = $this->createStub(IUserManager::class);
        $users->method('get')->willReturnCallback(fn (string $uid): ?IUser => $uid === 'ghost' ? null : $this->person($uid));

        return new AdminAiUsersService($this->config, $users, $this->accounts(), $this->links());
    }
}
