<?php

declare(strict_types=1);

namespace OCA\OcoSynaplan\Tests\Unit\Controller;

use OCA\OcoSynaplan\Controller\AdminAiUsersController;
use OCA\OcoSynaplan\Http\Failure;
use OCA\OcoSynaplan\Service\AdminAiUsersService;
use OCA\OcoSynaplan\Service\UserAccountService;
use OCA\OcoSynaplan\Tests\Unit\Support\BuildsServices;
use OCA\OcoSynaplan\Tests\Unit\Support\FakeCrypto;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

final class AdminAiUsersControllerTest extends TestCase
{
    use BuildsServices;

    protected function setUp(): void
    {
        $this->setUpServices(['mode' => 'provision', 'api_key' => FakeCrypto::stored('sk_admin')]);
        $this->register();
        $this->keys()->setUserKey('carol', 'sk_carol');
        $this->config->setUserValue('carol', 'oco_synaplan', UserAccountService::CONSENT_PREF, '1');
    }

    public function testDeactivateSaysWhenSynaplanCouldNotPauseThePerson(): void
    {
        $this->http->answer(static fn (): \Throwable => new \RuntimeException('cURL error 7'));

        $response = $this->controller()->deactivate('carol');

        self::assertSame(502, $response->getStatus());
        self::assertSame(Failure::SYNAPLAN_UNREACHABLE, $response->getData()['error']);
        self::assertSame('1', $this->config->userValue('carol', UserAccountService::CONSENT_PREF), 'AI stays on');
    }

    public function testDeactivatePausesThePerson(): void
    {
        $this->http->answer(static fn (): array => ['success' => true, 'account' => 'kept', 'status' => 'suspended', 'message' => '']);

        $response = $this->controller()->deactivate('carol');

        self::assertSame(['success' => true], $response->getData());
        self::assertTrue($this->accounts()->isPaused('carol'));
    }

    private function controller(): AdminAiUsersController
    {
        $users = $this->createStub(IUserManager::class);
        $users->method('get')->willReturnCallback(fn (string $uid): IUser => $this->person($uid));

        return new AdminAiUsersController(
            $this->createStub(IRequest::class),
            new AdminAiUsersService($this->config, $users, $this->accounts(), $this->links()),
            $this->logger(),
        );
    }
}
