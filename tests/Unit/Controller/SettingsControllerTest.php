<?php

declare(strict_types=1);

namespace OCA\OcoSynaplan\Tests\Unit\Controller;

use OCA\OcoSynaplan\Controller\SettingsController;
use OCA\OcoSynaplan\Service\NotifyKeyCache;
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
        self::assertSame(['deliveries'], $put[0]['body']['app_capabilities']);
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
        self::assertSame(['deliveries'], $put[1]['body']['app_capabilities']);
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

        return new SettingsController($request, $this->config, $this->client(), $this->synaplanConfig(), $this->keys(), $this->links(), new NotifyKeyCache($this->config, $this->links()), $this->logger());
    }
}
