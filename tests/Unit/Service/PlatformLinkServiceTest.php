<?php

declare(strict_types=1);

namespace OCA\OcoSynaplan\Tests\Unit\Service;

use OCA\OcoSynaplan\Exception\PlatformLinkException;
use OCA\OcoSynaplan\Service\PlatformLinkService;
use OCA\OcoSynaplan\Service\UserAccountService;
use OCA\OcoSynaplan\Tests\Unit\Support\BuildsServices;
use OCA\OcoSynaplan\Tests\Unit\Support\RecordingHttp;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;

final class PlatformLinkServiceTest extends TestCase
{
    use BuildsServices;

    protected function setUp(): void
    {
        $this->setUpServices(['mode' => 'link']);
    }

    public function testRegisteringNamesTheReturnPageTheServerAndOnlyWhatIsBuilt(): void
    {
        $this->http->answer(static fn (string $method, string $path): array => match ($path) {
            '/.well-known/synaplan-configuration' => ['issuer' => self::SYNAPLAN],
            default => ['client_id' => 'client-1', 'status' => 'pending'],
        });

        self::assertSame(['instance_id' => 'client-1', 'status' => 'pending'], $this->links()->register('owncloud-e2e-initial-access-token-0001'));

        $body = $this->http->to('/oauth/register')[0]['body'];
        self::assertSame([self::OWNCLOUD . '/apps/oco_synaplan/public/link-return.html'], $body['redirect_uris']);
        self::assertSame(self::OWNCLOUD . '/', $body['client_uri']);
        self::assertSame(PlatformLinkService::CAPABILITIES, $body['app_capabilities']);
        self::assertSame([], array_diff($body['app_capabilities'], ['deliveries', 'pickups', 'read_scope', 'source_events', 'watches', 'trials', 'file_sets']));
        self::assertSame('oc1234567890', $body['server_id']);
        self::assertSame('1.1.0', $body['software_version']);
    }

    public function testWhilePendingThePersonIsToldInsteadOfMeetingAnErrorInSynaplan(): void
    {
        $this->register();
        $this->config->setAppValue('oco_synaplan', 'link_registration_status', 'pending');
        $this->http->answer(static fn (): array => ['status' => 'pending', 'client_uri' => self::OWNCLOUD . '/']);
        self::assertTrue($this->links()->isWaitingForApproval());

        $this->http->answer(static fn (): array => ['status' => 'active', 'client_uri' => self::OWNCLOUD . '/', 'delivery_url' => '', 'reachable' => true]);
        self::assertFalse($this->links()->isWaitingForApproval());
        self::assertSame('active', $this->config->appValue('link_registration_status'));

        $calls = count($this->http->calls);
        self::assertFalse($this->links()->isWaitingForApproval());
        self::assertCount($calls, $this->http->calls, 'an active registration is not asked about again');
    }

    public function testTheRegistrationIsShownAsSynaplanHasIt(): void
    {
        self::assertNull($this->links()->registrationStatus());

        $this->register();
        $this->http->answer(static fn (): array => ['status' => 'active', 'client_uri' => 'http://localhost:8083/index.php', 'delivery_url' => '', 'reachable' => false]);
        self::assertSame(
            ['status' => 'active', 'host' => 'http://localhost:8083', 'delivery_url' => '', 'reachable' => false],
            $this->links()->registrationStatus(),
        );
    }

    public function testASynaplanThatNoLongerAcceptsThisServerIsShownAsRefused(): void
    {
        $this->register();
        $this->http->answer(static fn (): IResponse => RecordingHttp::response(401, '{"error":"invalid_client"}'));

        self::assertSame(PlatformLinkService::STATUS_REFUSED, $this->links()->registrationStatus()['status'] ?? null);
    }

    public function testSavingTellsSynaplanWhatThisAppDoesNow(): void
    {
        $this->links()->announce();
        self::assertSame([], $this->http->calls, 'nothing to tell without a registration');

        $this->register();
        $this->links()->announce();

        $call = $this->http->to('/oauth/register/client-1')[0];
        self::assertSame('PUT', $call['method']);
        self::assertSame(PlatformLinkService::CAPABILITIES, $call['body']['app_capabilities']);
        self::assertSame(['1.1.0', 'oc1234567890'], [$call['body']['software_version'], $call['body']['server_id']]);
    }

    public function testDisconnectingAConnectedPersonRevokesTheirGrant(): void
    {
        $this->register();
        $this->connect('ada');
        $this->config->setUserValue('ada', 'oco_synaplan', UserAccountService::LINK_KIND_PREF, 'linked');

        $this->links()->disconnect($this->person('ada'));

        self::assertCount(1, $this->http->to('/oauth/revoke'));
        self::assertFalse($this->oauth()->isConnected('ada'));
        self::assertNull($this->config->userValue('ada', UserAccountService::LINK_KIND_PREF));
    }

    public function testDisconnectingACreatedAccountDeletesItsKeyWithTheKeyItself(): void
    {
        $this->config->setAppValue('oco_synaplan', 'mode', 'provision');
        $this->keys()->setUserKey('ada', 'sk_ada');
        $this->config->setUserValue('ada', 'oco_synaplan', UserAccountService::USER_KEY_ID_PREF, '11');

        $this->links()->disconnect($this->person('ada'));

        $call = $this->http->to('/api/v1/apikeys/11')[0];
        self::assertSame(['DELETE', 'sk_ada'], [$call['method'], $call['options']['headers']['X-API-Key']]);
        self::assertSame('', $this->keys()->getUserKey('ada'));
    }

    public function testAFailedRevocationKeepsTheKeyForARetry(): void
    {
        $this->config->setAppValue('oco_synaplan', 'mode', 'provision');
        $this->keys()->setUserKey('ada', 'sk_ada');
        $this->config->setUserValue('ada', 'oco_synaplan', UserAccountService::USER_KEY_ID_PREF, '11');
        $this->http->answer(static fn (): \Throwable => new \RuntimeException('cURL error 7'));

        try {
            $this->links()->disconnect($this->person('ada'));
            self::fail('A failed revocation must be reported');
        } catch (PlatformLinkException) {
        }
        self::assertSame('sk_ada', $this->keys()->getUserKey('ada'));
    }

    public function testWhoThePersonIsIsAskedWithTheirOwnCredential(): void
    {
        $this->register();
        $this->connect('ada', 'access-ada');
        $this->http->answer(static fn (): array => ['user' => ['id' => 5, 'email' => 'ada@synaplan.example.org']]);

        self::assertSame(['id' => 5, 'email' => 'ada@synaplan.example.org'], $this->links()->whoAmI('ada'));
        self::assertSame('Bearer access-ada', $this->http->to('/api/v1/auth/me')[0]['options']['headers']['Authorization']);
    }

    public function testLeavingSynaplanEndsTheRegistrationThereAndForgetsTheKeyHere(): void
    {
        $this->register();

        $this->links()->forgetRegistration();

        self::assertSame('DELETE', $this->http->to('/oauth/register/client-1')[0]['method']);
        self::assertFalse($this->oauth()->isRegistered());
        self::assertNull($this->config->appValue('oauth_private_key'));
        self::assertNull($this->config->appValue('notify_issuer'));
    }
}
