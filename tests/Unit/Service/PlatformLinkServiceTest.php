<?php

declare(strict_types=1);

namespace OCA\OcoSynaplan\Tests\Unit\Service;

use OCA\OcoSynaplan\Exception\PlatformLinkException;
use OCA\OcoSynaplan\Service\PlatformLinkService;
use OCA\OcoSynaplan\Service\UserAccountService;
use OCA\OcoSynaplan\Tests\Unit\Support\BuildsServices;
use OCA\OcoSynaplan\Tests\Unit\Support\RecordingHttp;
use OCP\Http\Client\IResponse;
use OCP\IURLGenerator;
use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testAFolderReadsCallsKeepTheTimeTheyAreGiven(): void
    {
        $this->register();
        $this->connect('ada', 'access-ada');
        $this->http->answer(static fn (): array => ['status' => 'ok']);

        $this->links()->claimPickup('15', 'ada', 12);
        $this->links()->submitPickupListing('15', 'ada', [], 3);
        $this->links()->reportPickupResult('15', 'ada', ['status' => 'done'], 4);

        self::assertSame([12, 3, 4], array_map(
            static fn (array $call): int => $call['options']['timeout'],
            array_merge(
                $this->http->to('/api/v1/platform-links/pickups/15/claim'),
                $this->http->to('/api/v1/platform-links/pickups/15/listing'),
                $this->http->to('/api/v1/platform-links/pickups/15/result'),
            ),
        ));
    }

    public function testACopyIsClaimedFetchedAndReportedWithThePersonsOwnCredential(): void
    {
        $this->register();
        $this->connect('ada', 'access-ada');
        $this->http->answer(static fn (string $method, string $path): array|IResponse => match ($path) {
            '/api/v1/platform-links/deliveries/9/claim' => ['id' => '9', 'external_id' => 'ada', 'download_path' => '/api/v1/platform-links/deliveries/9/file?ticket=t_1'],
            '/api/v1/platform-links/deliveries/9/file' => RecordingHttp::response(200, 'bytes'),
            default => ['status' => 'delivered'],
        });

        $claim = $this->links()->claimDelivery('9', 'ada');
        $bytes = $this->links()->downloadDelivery('9', $claim['download_path'], 'ada');
        $this->links()->reportDelivery('9', 'ada', ['status' => 'delivered', 'remote_path' => '/Synaplan/plan.docx']);

        self::assertSame('bytes', $bytes);
        foreach ($this->http->calls as $call) {
            self::assertSame('Bearer access-ada', $call['options']['headers']['Authorization'], $call['path']);
        }
        self::assertSame(['POST', 'GET', 'POST'], array_column($this->http->calls, 'method'));
        self::assertSame(self::SYNAPLAN . '/api/v1/platform-links/deliveries/9/file?ticket=t_1', $this->http->calls[1]['url']);
        self::assertSame(['status' => 'delivered', 'remote_path' => '/Synaplan/plan.docx'], $this->http->calls[2]['body']);
    }

    public function testAClaimSynaplanRefusesComesBackWithItsStatus(): void
    {
        $this->register();
        $this->connect('ada');
        $this->http->answer(static fn (): IResponse => RecordingHttp::response(409, '{"error":"already_claimed"}'));

        self::assertSame(['status_code' => 409, 'error' => 'already_claimed'], $this->links()->claimDelivery('9', 'ada'));
    }

    public function testTheCredentialOnlyGoesToTheCopysOwnFile(): void
    {
        $this->register();
        $this->connect('ada');

        foreach ([
            '/api/v1/files/7/download',
            '/api/v1/platform-links/deliveries/10/file?ticket=t_1',
            '/api/v1/platform-links/deliveries/9/file?ticket=t_1&next=https://elsewhere.example',
            '/api/v1/platform-links/deliveries/9/file?ticket=',
            'https://elsewhere.example/api/v1/platform-links/deliveries/9/file?ticket=t_1',
        ] as $path) {
            self::assertNull($this->links()->downloadDelivery('9', $path, 'ada'), $path);
        }
        self::assertSame([], $this->http->calls);
    }

    public function testSynaplanCallsThisServerAtItsOwnRouteUnlessAnAddressIsSet(): void
    {
        self::assertSame($this->links()->defaultDeliveryUrl(), $this->links()->deliveryUrl());
        self::assertStringEndsWith('/index.php/route/oco_synaplan.inbound.receive', $this->links()->deliveryUrl());

        $this->config->setAppValue('oco_synaplan', 'delivery_url', 'https://cloud.example/index.php/apps/oco_synaplan/api/v1/inbound');
        self::assertSame('https://cloud.example/index.php/apps/oco_synaplan/api/v1/inbound', $this->links()->deliveryUrl());
    }

    public function testTheDefaultAddressIsThisServersOwnRouteAlsoOnLocalhost(): void
    {
        $urls = $this->createStub(IURLGenerator::class);
        $urls->method('linkToRouteAbsolute')->willReturn('http://localhost:8083/index.php/apps/oco_synaplan/api/v1/inbound');
        $links = new PlatformLinkService($this->http, $this->config, $urls, $this->oauth(), $this->appManager(), $this->synaplanConfig(), $this->accounts(), $this->logger(), $this->cache);

        self::assertSame('http://localhost:8083/index.php/apps/oco_synaplan/api/v1/inbound', $links->defaultDeliveryUrl());
        self::assertSame('http://localhost:8083/index.php/apps/oco_synaplan/api/v1/inbound', $links->deliveryUrl());
    }

    public function testACopyOpensAtTheAddressPeopleUseNotTheOneSynaplanCalled(): void
    {
        $urls = $this->createStub(IURLGenerator::class);
        $urls->method('linkToRoute')->willReturnCallback(
            static fn (string $route, array $parameters): string => $route === 'files.viewcontroller.showFile' ? '/cloud/index.php/f/' . $parameters['fileId'] : '',
        );
        $urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'http://host.docker.internal:8083' . $path);
        $links = new PlatformLinkService($this->http, $this->config, $urls, $this->oauth(), $this->appManager(), $this->synaplanConfig(), $this->accounts(), $this->logger(), $this->cache);

        self::assertSame('http://host.docker.internal:8083/cloud/index.php/f/42', $links->openUrl(42), 'without overwrite.cli.url only the request tells');

        $this->config->setSystemValue('overwrite.cli.url', 'https://cloud.example/cloud/');
        self::assertSame('https://cloud.example/cloud/index.php/f/43', $links->openUrl(43));

        $this->config->setSystemValue('overwrite.cli.url', 'https://cloud.example');
        self::assertSame('https://cloud.example/cloud/index.php/f/44', $links->openUrl(44));
    }

    public function testAnAddressSynaplanRefusesIsLeftOutAndTheRestIsStillSent(): void
    {
        $this->register();
        $this->http->answer(static fn (string $method, string $path, array $options): array|IResponse => str_contains((string) ($options['body'] ?? ''), 'delivery_url')
            ? RecordingHttp::response(400, '{"error":"invalid_client_metadata","error_description":"That address is not on this cloud."}')
            : ['client_id' => 'client-1']);

        try {
            $this->links()->announce();
            self::fail('A refused address must be reported');
        } catch (PlatformLinkException $e) {
            self::assertSame(PlatformLinkException::CODE_DELIVERY_REFUSED, $e->getErrorCode());
        }

        [$first, $second] = $this->http->to('/oauth/register/client-1');
        self::assertArrayHasKey('delivery_url', $first['body']);
        self::assertArrayNotHasKey('delivery_url', $second['body']);
        self::assertSame(PlatformLinkService::CAPABILITIES, $second['body']['app_capabilities']);
    }

    public function testNotificationKeysAreTheEd25519KeysOfSynaplansPublicDocuments(): void
    {
        $this->http->answer(static fn (string $method, string $path): array => match ($path) {
            '/.well-known/synaplan-configuration' => ['issuer' => self::SYNAPLAN],
            default => ['keys' => [
                ['kty' => 'OKP', 'crv' => 'Ed25519', 'x' => 'abc', 'kid' => 'k1'],
                ['kty' => 'RSA', 'n' => 'xyz', 'e' => 'AQAB', 'kid' => 'k2'],
                ['kty' => 'OKP', 'crv' => 'X25519', 'x' => 'def', 'kid' => 'k3'],
                ['kty' => 'OKP', 'crv' => 'Ed25519', 'x' => ''],
            ]],
        });

        self::assertSame(
            ['issuer' => self::SYNAPLAN, 'keys' => [['publicKey' => 'ed25519:abc', 'kid' => 'k1']]],
            $this->links()->notificationTrust(),
        );
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: bool}>
     */
    public static function configurations(): array
    {
        return [
            'folder reads and the read setting' => [['capabilities' => ['deliveries', 'pickups', 'read_scope']], true],
            'no read setting' => [['capabilities' => ['deliveries', 'pickups']], false],
            'no folder reads' => [['capabilities' => ['deliveries', 'read_scope']], false],
            'a configuration without capabilities' => [[], false],
        ];
    }

    /**
     * @param array<string, mixed> $offers
     */
    #[DataProvider('configurations')]
    public function testSynaplanReadsFoldersOnlyWhenItOffersFolderReadsAndTheReadSetting(array $offers, bool $reads): void
    {
        $this->http->answer(static fn (): array => ['issuer' => self::SYNAPLAN] + $offers);

        self::assertSame([$reads, $reads], [$this->links()->readsFolders(), $this->links()->readsFolders()]);
        self::assertCount(1, $this->http->to('/.well-known/synaplan-configuration'), 'kept as long as Synaplan lets it be kept');
        self::assertSame(10, $this->http->calls[0]['options']['timeout']);
        self::assertSame([300], $this->keptFor());
    }

    public function testASynaplanWithPlatformLinksOffReadsNoFolders(): void
    {
        $this->http->answer(static fn (): IResponse => RecordingHttp::response(404, '{"error":"not_found"}'));

        self::assertFalse($this->links()->readsFolders());
        self::assertSame([300], $this->keptFor());
    }

    public function testASynaplanThatDoesNotAnswerReadsNoFoldersAndIsAskedAgainSoon(): void
    {
        foreach ([
            'down' => static fn (): IResponse => RecordingHttp::response(503, ''),
            'unreachable' => static fn (): \Throwable => new \RuntimeException('cURL error 28: Operation timed out'),
        ] as $case => $responder) {
            $this->cache->values = [];
            $this->cache->ttls = [];
            $this->http->answer($responder);

            self::assertFalse($this->links()->readsFolders(), $case);
            self::assertSame([60], $this->keptFor(), $case);
        }

        $this->cache->values = [];
        $this->http->answer(static fn (): array => ['issuer' => self::SYNAPLAN, 'capabilities' => ['pickups', 'read_scope']]);
        self::assertTrue($this->links()->readsFolders(), 'asked again once the answer is gone');
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

    /**
     * @return list<int> how long each cached answer is kept
     */
    private function keptFor(): array
    {
        return array_values($this->cache->ttls);
    }
}
