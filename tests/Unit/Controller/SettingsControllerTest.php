<?php

declare(strict_types=1);

namespace OCA\OcoSynaplan\Tests\Unit\Controller;

use OCA\OcoSynaplan\Controller\SettingsController;
use OCA\OcoSynaplan\Service\ReadScope;
use OCA\OcoSynaplan\Tests\Unit\Support\BuildsServices;
use OCA\OcoSynaplan\Tests\Unit\Support\FakeCrypto;
use OCA\OcoSynaplan\Tests\Unit\Support\RecordingHttp;
use OCP\Http\Client\IResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

final class SettingsControllerTest extends TestCase
{
    use BuildsServices;

    protected function setUp(): void
    {
        $this->setUpServices(['mode' => 'provision']);
    }

    public function testAKeyIsStoredEncryptedAndNeverSentBack(): void
    {
        $this->controller()->saveSettings(api_key: 'sk_live_0123456789abcdef', api_key_local: 'sk_local');

        self::assertSame(FakeCrypto::stored('sk_live_0123456789abcdef'), $this->config->appValue('api_key'));
        self::assertSame(FakeCrypto::stored('sk_local'), $this->config->appValue('api_key_local'));
        $settings = $this->controller()->getSettings()->getData();
        self::assertTrue($settings['api_key_set']);
        self::assertSame('sk_liv' . str_repeat('•', 14) . 'cdef', $settings['api_key_masked']);
        self::assertSame('••••••••', $settings['api_key_local_masked']);
        self::assertStringNotContainsString('sk_live_0123456789abcdef', (string) json_encode($settings));
        self::assertStringNotContainsString('sk_local', (string) json_encode($settings));
    }

    public function testAnEmptyKeyKeepsTheStoredOne(): void
    {
        $this->keys()->setAppKey('api_key', 'sk_admin');

        $this->controller()->saveSettings(synaplan_url: 'http://host.docker.internal:8001/');

        self::assertSame('sk_admin', $this->keys()->getAppKey('api_key'));
        self::assertSame('http://host.docker.internal:8001', $this->config->appValue('synaplan_url'));
    }

    public function testOrganisationLoginCannotBeChosenHere(): void
    {
        $this->controller()->saveSettings(mode: 'oidc');

        self::assertSame('provision', $this->controller()->getSettings()->getData()['mode']);
    }

    public function testTheBooleanOfRelease100StillChoosesTheMode(): void
    {
        $this->controller()->saveSettings(per_user_accounts: false);
        self::assertSame('shared', $this->config->appValue('mode'));

        $this->controller()->saveSettings(per_user_accounts: true, mode: 'link');
        self::assertSame('link', $this->config->appValue('mode'), 'the mode wins');
    }

    public function testSavingARegisteredServerUpdatesTheRegistrationAndAFailureThereKeepsTheSave(): void
    {
        $this->register();
        $this->http->answer(static fn (): IResponse => RecordingHttp::response(500, ''));

        $data = $this->controller()->saveSettings(mode: 'link')->getData();

        self::assertSame(['success' => true, 'announce_error' => true, 'delivery_error' => ''], $data);
        self::assertSame('link', $this->config->appValue('mode'));
        self::assertSame('PUT', $this->http->to('/oauth/register/client-1')[0]['method']);
    }

    public function testTheDeliveryAddressIsSavedAndToldToSynaplan(): void
    {
        $this->register();
        $this->http->answer(static fn (): array => []);

        $data = $this->controller()->saveSettings(delivery_url: 'https://cloud-internal.example.org/index.php/apps/oco_synaplan/api/v1/inbound')->getData();

        self::assertSame(['success' => true, 'announce_error' => false, 'delivery_error' => ''], $data);
        $put = $this->http->to('/oauth/register/client-1');
        self::assertCount(1, $put);
        self::assertSame('https://cloud-internal.example.org/index.php/apps/oco_synaplan/api/v1/inbound', $put[0]['body']['delivery_url']);
        self::assertSame(['deliveries', 'pickups', 'read_scope', 'source_events', 'watches'], $put[0]['body']['app_capabilities']);
        self::assertSame(
            'https://cloud-internal.example.org/index.php/apps/oco_synaplan/api/v1/inbound',
            $this->controller()->getSettings()->getData()['delivery_url'],
        );
    }

    public function testAnAddressThatIsNotAWebAddressIsNotSaved(): void
    {
        $this->config->setAppValue('oco_synaplan', 'delivery_url', 'https://cloud.example.org/inbound');

        $data = $this->controller()->saveSettings(delivery_url: 'cloud.example.org/inbound')->getData();

        self::assertSame('invalid', $data['delivery_error']);
        self::assertSame('https://cloud.example.org/inbound', $this->config->appValue('delivery_url'));
    }

    public function testAnAddressSynaplanRefusesStillLetsTheRestThrough(): void
    {
        $this->register();
        $this->http->answer(static fn (string $method, string $path, array $options): IResponse|array => str_contains((string) ($options['body'] ?? ''), 'delivery_url')
            ? RecordingHttp::response(400, '{"error":"invalid_client_metadata","error_description":"That address is not on this cloud."}')
            : []);

        $data = $this->controller()->saveSettings(delivery_url: 'http://elsewhere.example.org/inbound')->getData();

        self::assertSame(['success' => true, 'announce_error' => false, 'delivery_error' => 'refused'], $data);
        $put = $this->http->to('/oauth/register/client-1');
        self::assertCount(2, $put);
        self::assertArrayNotHasKey('delivery_url', $put[1]['body']);
        self::assertSame(['deliveries', 'pickups', 'read_scope', 'source_events', 'watches'], $put[1]['body']['app_capabilities']);
    }

    public function testTurningWatchingOffStopsAnnouncingItAndIsReadBack(): void
    {
        $this->register();
        $this->http->answer(static fn (): array => []);
        $this->controller()->saveSettings(watch_folders: false, watch_shared_folders: false);

        self::assertSame(['deliveries', 'pickups', 'read_scope', 'source_events'], $this->http->to('/oauth/register/client-1')[0]['body']['app_capabilities']);
        $settings = $this->controller()->getSettings()->getData();
        self::assertFalse($settings['watch_folders']);
        self::assertFalse($settings['watch_shared_folders']);
        self::assertSame('ajax', $settings['background_jobs_mode']);
    }

    public function testRegisteringReadsTheNotificationKeys(): void
    {
        $this->http->answer(static fn (string $method, string $path): array => match ($path) {
            '/.well-known/synaplan-configuration' => ['issuer' => self::SYNAPLAN],
            '/.well-known/jwks.json' => ['keys' => [['kty' => 'OKP', 'crv' => 'Ed25519', 'x' => 'AAAA', 'kid' => 'n-1']]],
            default => ['client_id' => 'client-1', 'status' => 'active'],
        });

        $this->controller(['registration_token' => 'token-1'])->registerInstance();

        self::assertSame(self::SYNAPLAN, $this->config->appValue('notify_issuer'));
        self::assertSame([['publicKey' => 'ed25519:AAAA', 'kid' => 'n-1']], json_decode((string) $this->config->appValue('notify_keys'), true));
    }

    public function testWithoutAnAddressTheDefaultIsThisServersOwnRoute(): void
    {
        $settings = $this->controller()->getSettings()->getData();

        self::assertSame('', $settings['delivery_url']);
        self::assertSame('http://localhost:8083/index.php/route/oco_synaplan.inbound.receive', $settings['delivery_url_default']);
    }

    public function testRegisteringPassesTheTokenTheAdministratorPasted(): void
    {
        $this->http->answer(static fn (string $method, string $path): array => match ($path) {
            '/.well-known/synaplan-configuration' => ['issuer' => self::SYNAPLAN],
            default => ['client_id' => 'client-1', 'status' => 'active'],
        });

        $data = $this->controller(['registration_token' => ' token-1 '])->registerInstance()->getData();

        self::assertSame(['success' => true, 'instance_id' => 'client-1', 'status' => 'active'], $data);
        self::assertSame('Bearer token-1', $this->http->to('/oauth/register')[0]['options']['headers']['Authorization']);
        self::assertTrue($this->synaplanConfig()->isRegistered());
    }

    public function testARefusedRegistrationSaysWhy(): void
    {
        $this->http->answer(static fn (): IResponse => RecordingHttp::response(401, '{"error":"invalid_token","error_description":"The registration token is not valid."}'));

        $data = $this->controller(['registration_token' => 'wrong'])->registerInstance()->getData();

        self::assertSame(['success' => false, 'error' => 'The registration token is not valid.', 'code' => 'exchange'], $data);
        self::assertFalse($this->synaplanConfig()->isRegistered());
    }

    public function testLoweringTheReadCeilingNarrowsEveryoneAboveItAndRaisingItWidensNobody(): void
    {
        $this->config->setUserValue('ada', 'oco_synaplan', ReadScope::PREF, '{"mode":"all","folders":[]}');
        $this->config->setUserValue('bob', 'oco_synaplan', ReadScope::PREF, '{"mode":"allowed","folders":[{"id":"","path":"Invoices"}]}');
        $this->config->setUserValue('eve', 'oco_synaplan', ReadScope::PREF, '{"mode":"watched","folders":[]}');

        $this->controller()->saveSettings(read_scope_ceiling: ReadScope::MODE_WATCHED);

        self::assertSame(ReadScope::MODE_WATCHED, $this->controller()->getSettings()->getData()['read_scope_ceiling']);
        foreach (['ada', 'bob'] as $uid) {
            self::assertSame(ReadScope::MODE_WATCHED, $this->saved($uid)['mode'], $uid);
            self::assertSame('1', $this->config->userValue($uid, ReadScope::PENDING), $uid . ' is told Synaplan within the hour');
        }
        self::assertSame([['id' => '', 'path' => 'Invoices']], $this->saved('bob')['folders'], 'the named folders stay');
        self::assertNull($this->config->userValue('eve', ReadScope::PENDING));
        self::assertSame([], $this->http->calls, 'the hourly job tells Synaplan, not the save');

        $this->controller()->saveSettings(read_scope_ceiling: ReadScope::MODE_ALL);

        self::assertSame(ReadScope::MODE_WATCHED, $this->readScope()->get('ada')['mode']);
        self::assertSame(ReadScope::MODE_WATCHED, $this->readScope()->get('bob')['mode']);
    }

    public function testAnUnknownReadCeilingIsNotSaved(): void
    {
        $this->controller()->saveSettings(read_scope_ceiling: 'everything');

        self::assertSame(ReadScope::MODE_ALLOWED, $this->controller()->getSettings()->getData()['read_scope_ceiling']);
    }

    public function testTheFolderCountOnePersonMayAllowStaysBetweenOneAndFiveHundred(): void
    {
        self::assertSame(20, $this->controller()->getSettings()->getData()['read_folder_cap']);

        foreach ([0 => 1, 9000 => 500, 5 => 5] as $asked => $kept) {
            $this->controller()->saveSettings(read_folder_cap: $asked);

            self::assertSame($kept, $this->controller()->getSettings()->getData()['read_folder_cap'], (string) $asked);
        }
    }

    public function testLeavingSynaplan(): void
    {
        $this->register();

        self::assertSame(['success' => true], $this->controller()->forgetInstance()->getData());
        self::assertFalse($this->synaplanConfig()->isRegistered());
        self::assertNull($this->controller()->getSettings()->getData()['link_instance']);
    }

    /**
     * @param array<string, string> $params
     */
    private function controller(array $params = []): SettingsController
    {
        $request = $this->createStub(IRequest::class);
        $request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => $params[$key] ?? $default);

        return new SettingsController($request, $this->config, $this->client(), $this->synaplanConfig(), $this->keys(), $this->links(), $this->notifyKeys(), $this->logger(), $this->readScope());
    }

    /**
     * @return array<string, mixed>
     */
    private function saved(string $uid): array
    {
        $saved = json_decode((string) $this->config->userValue($uid, ReadScope::PREF), true);
        self::assertIsArray($saved);

        return $saved;
    }
}
