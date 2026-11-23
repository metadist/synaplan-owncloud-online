<?php

declare(strict_types=1);

namespace OCA\OcoSynaplan\Tests\Unit\BackgroundJob;

use OCA\OcoSynaplan\BackgroundJob\AdoptIdentitiesJob;
use OCA\OcoSynaplan\Service\UserAccountService;
use OCA\OcoSynaplan\Tests\Unit\Support\BuildsServices;
use OCA\OcoSynaplan\Tests\Unit\Support\FakeCrypto;
use OCP\BackgroundJob\IJobList;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class AdoptIdentitiesJobTest extends TestCase
{
    use BuildsServices;

    private UserAccountService&MockObject $adopter;

    /** @var list<string> */
    private array $adopted = [];

    protected function setUp(): void
    {
        $this->setUpServices(['mode' => 'provision', 'api_key' => FakeCrypto::stored('sk_admin')]);
        $this->adopter = $this->createMock(UserAccountService::class);
    }

    public function testEveryPersonIsAdoptedOnceTheServerIsRegistered(): void
    {
        $this->register();
        $this->adoptionSucceedsExceptFor();

        $this->runJob();
        $this->runJob();

        self::assertSame(['alice', 'bob'], $this->adopted);
        self::assertSame('client-1', $this->config->appValue(AdoptIdentitiesJob::DONE));
    }

    public function testAPassWithAFailureRunsAgain(): void
    {
        $this->register();
        $this->adoptionSucceedsExceptFor('bob');

        $this->runJob();
        self::assertNull($this->config->appValue(AdoptIdentitiesJob::DONE));

        $this->runJob();
        self::assertSame(['alice', 'bob', 'alice', 'bob'], $this->adopted);
    }

    public function testNothingRunsBeforeTheRegistrationOrWithoutTheInstallKey(): void
    {
        $this->adopter->expects(self::never())->method('adoptLegacyAccount');

        $this->runJob();
        $this->register();
        unset($this->config->app['oco_synaplan']['api_key']);
        $this->runJob();

        self::assertNull($this->config->appValue(AdoptIdentitiesJob::DONE));
    }

    public function testANewRegistrationAdoptsTheAccountsProvisionedWithoutOne(): void
    {
        $this->register('client-1');
        $this->adoptionSucceedsExceptFor();
        $this->runJob();

        $this->register('client-2');
        $this->runJob();

        self::assertSame(['alice', 'bob', 'alice', 'bob'], $this->adopted);
        self::assertSame('client-2', $this->config->appValue(AdoptIdentitiesJob::DONE));
    }

    private function adoptionSucceedsExceptFor(string ...$failing): void
    {
        $this->adopter->method('adoptLegacyAccount')->willReturnCallback(function (string $uid) use ($failing): bool {
            $this->adopted[] = $uid;

            return !in_array($uid, $failing, true);
        });
    }

    /** One cron run: the server builds the job anew each time. */
    private function runJob(): void
    {
        $users = $this->createStub(IUserManager::class);
        $users->method('callForAllUsers')->willReturnCallback(function (\Closure $callback): void {
            foreach (['alice', 'bob'] as $uid) {
                $callback($this->person($uid));
            }
        });
        $job = new AdoptIdentitiesJob($this->config, $this->synaplanConfig(), $this->adopter, $users);

        $job->execute($this->createStub(IJobList::class));
    }
}
