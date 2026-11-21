<?php

declare(strict_types=1);

namespace OCA\OcoSynaplan\Tests\Unit\Controller;

use OCA\OcoSynaplan\Controller\SettingsController;
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

        self::assertSame(['success' => true, 'announce_error' => true], $data);
        self::assertSame('link', $this->config->appValue('mode'));
        self::assertSame('PUT', $this->http->to('/oauth/register/client-1')[0]['method']);
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

        return new SettingsController($request, $this->config, $this->client(), $this->synaplanConfig(), $this->keys(), $this->links(), $this->logger());
    }
}
