<?php

declare(strict_types=1);

namespace OCA\OcoSynaplan\Tests\Unit\BackgroundJob;

use OCA\OcoSynaplan\BackgroundJob\DeliveryResultJob;
use OCA\OcoSynaplan\BackgroundJob\DeliveryRetryJob;
use OCA\OcoSynaplan\BackgroundJob\RefreshNotifyKeysJob;
use OCA\OcoSynaplan\Exception\PlatformLinkException;
use OCA\OcoSynaplan\Service\CopyDelivery;
use OCA\OcoSynaplan\Service\NotifyKeyCache;
use OCA\OcoSynaplan\Service\PlatformLinkService;
use OCA\OcoSynaplan\Service\SynaplanConfig;
use OCP\BackgroundJob\IJobList;
use PHPUnit\Framework\TestCase;

final class DeliveryJobsTest extends TestCase
{
    private const RESULT = ['status' => 'delivered', 'remote_path' => '/Synaplan/Documents/plan.docx', 'remote_id' => '42', 'open_url' => 'https://cloud.example/index.php/f/42'];

    private bool $held = true;

    public function testAHeldResultWaitsWhileTheSwitchIsOnThenIsSentOnce(): void
    {
        $config = $this->createStub(SynaplanConfig::class);
        $config->method('isDevSwitchOn')->willReturnCallback(
            fn (string $name): bool => $this->held && $name === SynaplanConfig::DEV_HOLD_INBOUND,
        );
        $copies = $this->createMock(CopyDelivery::class);
        $copies->expects($this->once())->method('sendResult')->with('9', 'ada', self::RESULT);
        $jobs = $this->createMock(IJobList::class);
        $job = new DeliveryResultJob($copies, $config);
        $job->setArgument(['id' => '9', 'uid' => 'ada', 'result' => self::RESULT]);

        $jobs->expects($this->once())->method('remove');
        $job->execute($jobs);

        $this->held = false;
        $job->execute($jobs);
    }

    public function testABrokenHeldResultIsDropped(): void
    {
        $copies = $this->createMock(CopyDelivery::class);
        $copies->expects($this->never())->method('sendResult');
        $job = new DeliveryResultJob($copies, $this->createStub(SynaplanConfig::class));

        foreach ([null, ['id' => '9', 'uid' => 'ada'], ['id' => '', 'uid' => 'ada', 'result' => []]] as $argument) {
            $job->setArgument($argument);
            $job->execute($this->createStub(IJobList::class));
        }
    }

    public function testAFailedClaimIsTriedAgainWithWhenItFirstFailed(): void
    {
        $copies = $this->createMock(CopyDelivery::class);
        $copies->expects($this->once())->method('retry')->with('9', 'ada', 1_800_000_000);
        $job = new DeliveryRetryJob($copies);
        $job->setArgument(['id' => '9', 'uid' => 'ada', 'since' => 1_800_000_000]);

        $job->execute($this->createStub(IJobList::class));
    }

    public function testEveryHourTheKeysAreReadAndSynaplanToldWhereToCall(): void
    {
        $keys = $this->createMock(NotifyKeyCache::class);
        $keys->expects($this->once())->method('refresh');
        $links = $this->createMock(PlatformLinkService::class);
        $links->expects($this->once())->method('announce')
            ->willThrowException(new PlatformLinkException('refused', PlatformLinkException::CODE_DELIVERY_REFUSED));
        $job = new RefreshNotifyKeysJob($keys, $links);

        $job->execute($this->createStub(IJobList::class));
    }
}
