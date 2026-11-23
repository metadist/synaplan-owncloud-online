<?php

declare(strict_types=1);

namespace OCA\OcoSynaplan\Tests\Unit\Controller;

use OCA\OcoSynaplan\Controller\ConsentController;
use OCA\OcoSynaplan\Service\UserAccountService;
use OCA\OcoSynaplan\Tests\Unit\Support\BuildsServices;
use OCA\OcoSynaplan\Tests\Unit\Support\FakeCrypto;
use OCA\OcoSynaplan\Tests\Unit\Support\RecordingHttp;
use OCP\Http\Client\IResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

final class ConsentControllerTest extends TestCase
{
    use BuildsServices;

    protected function setUp(): void
    {
        $this->setUpServices(['mode' => 'provision', 'api_key' => FakeCrypto::stored('sk_admin')]);
        $this->signIn($this->person('ada'));
    }

    public function testTheCardNamesTheHostFilesGoTo(): void
    {
        $data = $this->controller()->getConsent()->getData();

        self::assertSame([true, false, 'synaplan.example.org', self::SYNAPLAN], [$data['required'], $data['granted'], $data['host'], $data['url']]);
        self::assertSame(['provision', false, null], [$data['mode'], $data['link_available'], $data['kind']]);

        $this->config->setAppValue('oco_synaplan', 'synaplan_public_url', 'http://localhost:8001');
        self::assertSame('localhost', $this->controller()->getConsent()->getData()['host']);
    }

    public function testSharedModeAsksNobody(): void
    {
        $this->config->setAppValue('oco_synaplan', 'mode', 'shared');

        self::assertFalse($this->controller()->getConsent()->getData()['required']);
    }

    public function testAgreeingAndWithdrawing(): void
    {
        self::assertTrue($this->controller()->setConsent(true)->getData()['granted']);
        self::assertSame('1', $this->config->userValue('ada', UserAccountService::CONSENT_PREF));
        self::assertSame([], $this->http->calls, 'the account is created with the first request, not here');

        $this->keys()->setUserKey('ada', 'sk_ada');
        self::assertFalse($this->controller()->setConsent(false)->getData()['granted']);
        self::assertSame('', $this->keys()->getUserKey('ada'));
    }

    public function testWithdrawingEndsAConnectedPersonsGrantAtSynaplanAndHere(): void
    {
        $this->connectedInLinkMode();
        $this->http->answer(static fn (): IResponse => RecordingHttp::response(200, ''));

        self::assertFalse($this->controller()->setConsent(false)->getData()['granted']);

        self::assertSame('refresh-1', $this->http->to('/oauth/revoke')[0]['body']['token']);
        self::assertFalse($this->oauth()->isConnected('ada'));
        self::assertNull($this->accounts()->resolveKeyForUser($this->person('ada')), 'nothing is sent for the person any more');
    }

    public function testWithdrawingForgetsTheGrantHereWhenSynaplanCannotBeTold(): void
    {
        $this->connectedInLinkMode();
        $this->http->answer(static fn (): IResponse => RecordingHttp::response(503, ''));

        self::assertFalse($this->controller()->setConsent(false)->getData()['granted']);

        self::assertCount(1, $this->http->to('/oauth/revoke'));
        self::assertFalse($this->oauth()->isConnected('ada'));
        self::assertNull($this->accounts()->resolveKeyForUser($this->person('ada')));
    }

    private function connectedInLinkMode(): void
    {
        $this->config->setAppValue('oco_synaplan', 'mode', 'link');
        $this->register();
        $this->connect('ada');
        self::assertTrue($this->controller()->setConsent(true)->getData()['granted']);
        self::assertSame('access-1', $this->accounts()->resolveKeyForUser($this->person('ada')));
    }

    public function testInLinkModeAnAccountCanBeCreatedForThePerson(): void
    {
        $this->linkModeWithAccounts();
        $this->http->answer(static fn (string $method, string $path): array => match ($path) {
            '/api/v1/admin/users' => ['user' => ['id' => 7]],
            default => ['api_key' => ['id' => 11, 'key' => 'sk_ada']],
        });

        $data = $this->controller()->setConsent(true, true)->getData();

        self::assertTrue($data['success']);
        self::assertSame('sk_ada', $this->keys()->getUserKey('ada'));
        self::assertSame('provisioned', $this->config->userValue('ada', UserAccountService::LINK_KIND_PREF));
    }

    public function testAnEmailSynaplanAlreadyKnowsOffersConnectingInstead(): void
    {
        $this->linkModeWithAccounts();
        $this->http->answer(static fn (): IResponse => RecordingHttp::response(409, '{"error":"Email already in use"}'));

        $data = $this->controller()->setConsent(true, true)->getData();

        self::assertSame(['success' => false, 'conflict' => true, 'link_url' => '/index.php/route/oco_synaplan.link.start'], $data);
        self::assertNull($this->config->userValue('ada', UserAccountService::CONSENT_PREF));
    }

    public function testAnEmailConflictWithoutConnectingAvailableIsAnswered409(): void
    {
        $this->config->setAppValue('oco_synaplan', 'mode', 'link');
        $this->config->setAppValue('oco_synaplan', 'link_auto_provision', '1');
        $this->http->answer(static fn (): IResponse => RecordingHttp::response(409, '{"error":"Email already in use"}'));

        $response = $this->controller()->setConsent(true, true);

        self::assertSame(409, $response->getStatus());
        self::assertSame('email_already_used', $response->getData()['error']);
    }

    public function testAPausedPersonSynaplanWillNotResumeIsToldWhy(): void
    {
        $this->register();
        $this->keys()->setUserKey('ada', 'sk_ada');
        $this->config->setUserValue('ada', 'oco_synaplan', UserAccountService::PAUSED_PREF, '1');
        $this->http->answer(static fn (): IResponse => RecordingHttp::response(409, '{"error":"identity_revoked"}'));

        $response = $this->controller()->setConsent(true);

        self::assertSame([409, 'account_unavailable'], [$response->getStatus(), $response->getData()['error']]);
        self::assertNull($this->config->userValue('ada', UserAccountService::CONSENT_PREF));
    }

    private function linkModeWithAccounts(): void
    {
        $this->config->setAppValue('oco_synaplan', 'mode', 'link');
        $this->config->setAppValue('oco_synaplan', 'link_auto_provision', '1');
        $this->register();
    }

    private function controller(): ConsentController
    {
        return new ConsentController($this->createStub(IRequest::class), $this->accounts(), $this->synaplanConfig(), $this->urls(), $this->session(), $this->logger());
    }
}
