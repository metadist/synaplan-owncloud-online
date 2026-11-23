<?php

declare(strict_types=1);

namespace OCA\OcoSynaplan\Tests\Unit\BackgroundJob;

use OCA\OcoSynaplan\BackgroundJob\WatchFeedJob;
use OCA\OcoSynaplan\BackgroundJob\WatchReconcileJob;
use OCA\OcoSynaplan\Service\SynaplanConfig;
use OCA\OcoSynaplan\Service\WatchFeed;
use OCA\OcoSynaplan\Service\WatchReconcile;
use OCA\OcoSynaplan\Service\WatchSync;
use PHPUnit\Framework\TestCase;

final class WatchJobsTest extends TestCase
{
    public function testEveryMinuteTheIndexIsSyncedNewWatchesGetTheirFirstPassAndQuietFilesGo(): void
    {
        $order = [];
        $sync = $this->createMock(WatchSync::class);
        $sync->expects($this->once())->method('run')->with($this->isInt())
            ->willReturnCallback(function () use (&$order): void {
                $order[] = 'sync';
            });
        $reconcile = $this->createMock(WatchReconcile::class);
        $reconcile->expects($this->once())->method('run')->with($this->isInt(), $this->isFloat(), true)
            ->willReturnCallback(function () use (&$order): void {
                $order[] = 'first pass';
            });
        $feed = $this->createMock(WatchFeed::class);
        $feed->expects($this->once())->method('run')->with($this->isInt(), $this->isFloat())
            ->willReturnCallback(function () use (&$order): void {
                $order[] = 'feed';
            });

        $this->invoke(new WatchFeedJob($this->registered(true), $sync, $reconcile, $feed));

        self::assertSame(['sync', 'first pass', 'feed'], $order);
    }

    public function testTheReconcileJobTakesEveryWatchThatIsDue(): void
    {
        $reconcile = $this->createMock(WatchReconcile::class);
        $reconcile->expects($this->once())->method('run')->with($this->isInt(), $this->isFloat(), false);

        $this->invoke(new WatchReconcileJob($this->registered(true), $reconcile));
    }

    public function testNothingRunsBeforeTheRegistration(): void
    {
        $sync = $this->createMock(WatchSync::class);
        $sync->expects($this->never())->method('run');
        $reconcile = $this->createMock(WatchReconcile::class);
        $reconcile->expects($this->never())->method('run');

        $this->invoke(new WatchFeedJob($this->registered(false), $sync, $reconcile, $this->createStub(WatchFeed::class)));
        $this->invoke(new WatchReconcileJob($this->registered(false), $reconcile));
    }

    private function invoke(object $job): void
    {
        (new \ReflectionMethod($job, 'run'))->invoke($job, null);
    }

    private function registered(bool $registered): SynaplanConfig
    {
        $config = $this->createStub(SynaplanConfig::class);
        $config->method('isRegistered')->willReturn($registered);

        return $config;
    }
}
