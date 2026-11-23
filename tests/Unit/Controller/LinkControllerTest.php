<?php

declare(strict_types=1);

namespace OCA\OcoSynaplan\Tests\Unit\Controller;

use OCA\OcoSynaplan\Controller\LinkController;
use OCA\OcoSynaplan\Service\UserAccountService;
use OCA\OcoSynaplan\Tests\Unit\Support\BuildsServices;
use OCA\OcoSynaplan\Tests\Unit\Support\MemorySession;
use OCA\OcoSynaplan\Tests\Unit\Support\RecordingHttp;
use OCP\Http\Client\IResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

final class LinkControllerTest extends TestCase
{
    use BuildsServices;

    private const SETTINGS = self::OWNCLOUD . '/index.php/route/settings.SettingsPage.getPersonal?sectionid=oco_synaplan';

    private MemorySession $session;

    protected function setUp(): void
    {
        $this->setUpServices(['mode' => 'link']);
        $this->register();
        $this->session = new MemorySession();
        $this->signIn($this->person('ada'));
    }

    public function testStartingSendsThePersonToSynaplanAndKeepsStateAndVerifierInTheSession(): void
    {
        $this->http->answer(static fn (): array => ['request_uri' => 'urn:ietf:params:oauth:request_uri:abc']);

        $response = $this->controller()->start();

        self::assertStringStartsWith(self::SYNAPLAN . '/oauth/authorize?client_id=client-1&request_uri=', $response->getRedirectURL());
        self::assertSame('ada', $this->session->get('synaplan_link_uid'));
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $this->session->get('synaplan_link_state'));
        self::assertNotSame('', (string) $this->session->get('synaplan_link_verifier'));
        self::assertSame($this->session->get('synaplan_link_state'), $this->http->to('/oauth/par')[0]['body']['state']);
    }

    public function testStartingBeforeSynaplanApprovedTheServerSaysSo(): void
    {
        $this->config->setAppValue('oco_synaplan', 'link_registration_status', 'pending');
        $this->http->answer(static fn (): array => ['status' => 'pending']);

        self::assertSame(self::SETTINGS . '&link_error=instance_pending', $this->controller()->start()->getRedirectURL());
        self::assertSame([], $this->http->to('/oauth/par'));
    }

    public function testTheCallbackConnectsThePersonOnce(): void
    {
        $this->session->values = $this->handshake('ada', 'state-1');
        $this->http->answer(static fn (string $method, string $path): array => match ($path) {
            '/oauth/token' => ['access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'expires_in' => 900],
            default => ['user' => ['id' => 5, 'email' => 'ada@synaplan.example.org']],
        });

        $response = $this->controller(['code' => 'code-1', 'state' => 'state-1'])->callback();

        self::assertSame(self::SETTINGS . '&linked=1', $response->getRedirectURL());
        self::assertTrue($this->oauth()->isConnected('ada'));
        self::assertSame('ada@synaplan.example.org', $this->config->userValue('ada', UserAccountService::LINK_EMAIL_PREF));
        self::assertSame('verifier-1', $this->http->to('/oauth/token')[0]['body']['code_verifier']);
        self::assertSame([], $this->session->values, 'state and verifier are used once');
    }

    public function testAnotherStateIsRefused(): void
    {
        $this->session->values = $this->handshake('ada', 'state-1');

        $response = $this->controller(['code' => 'code-1', 'state' => 'state-2'])->callback();

        self::assertSame(self::SETTINGS . '&link_error=state', $response->getRedirectURL());
        self::assertSame([], $this->http->calls);
    }

    public function testAnotherPersonCannotFinishTheHandshake(): void
    {
        $this->session->values = $this->handshake('ada', 'state-1');
        $this->signIn($this->person('bob'));

        $response = $this->controller(['code' => 'code-1', 'state' => 'state-1'])->callback();

        self::assertSame(self::SETTINGS . '&link_error=state', $response->getRedirectURL());
        self::assertFalse($this->oauth()->isConnected('bob'));
    }

    public function testAnOldStateIsRefused(): void
    {
        $this->session->values = ['synaplan_link_state_at' => time() - 601] + $this->handshake('ada', 'state-1');

        self::assertSame(self::SETTINGS . '&link_error=state', $this->controller(['code' => 'code-1', 'state' => 'state-1'])->callback()->getRedirectURL());
    }

    public function testDecliningInSynaplanIsNamed(): void
    {
        $this->session->values = $this->handshake('ada', 'state-1');

        $response = $this->controller(['error' => 'access_denied', 'state' => 'state-1'])->callback();

        self::assertSame(self::SETTINGS . '&link_error=denied', $response->getRedirectURL());
    }

    public function testARefusedCodeConnectsNobody(): void
    {
        $this->session->values = $this->handshake('ada', 'state-1');
        $this->http->answer(static fn (): IResponse => RecordingHttp::response(400, '{"error":"invalid_grant"}'));

        $response = $this->controller(['code' => 'code-1', 'state' => 'state-1'])->callback();

        self::assertSame(self::SETTINGS . '&link_error=grant_ended', $response->getRedirectURL());
        self::assertFalse($this->oauth()->isConnected('ada'));
    }

    public function testDisconnectingWhenSynaplanCannotBeToldKeepsTheConnection(): void
    {
        $this->connect('ada');
        $this->http->answer(static fn (): \Throwable => new \RuntimeException('cURL error 7'));

        $response = $this->controller()->disconnect();

        self::assertSame(502, $response->getStatus());
        self::assertTrue($this->oauth()->isConnected('ada'));
    }

    /**
     * @return array<string, mixed>
     */
    private function handshake(string $uid, string $state): array
    {
        return [
            'synaplan_link_state' => $state,
            'synaplan_link_state_at' => time(),
            'synaplan_link_uid' => $uid,
            'synaplan_link_verifier' => 'verifier-1',
        ];
    }

    /**
     * @param array<string, string> $params
     */
    private function controller(array $params = []): LinkController
    {
        $request = $this->createStub(IRequest::class);
        $request->method('getParam')->willReturnCallback(static fn (string $key, mixed $default = null): mixed => $params[$key] ?? $default);

        return new LinkController($request, $this->session, $this->session(), $this->synaplanConfig(), $this->accounts(), $this->links(), $this->oauth());
    }
}
