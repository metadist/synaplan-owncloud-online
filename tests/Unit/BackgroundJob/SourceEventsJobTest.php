<?php

declare(strict_types=1);

namespace OCA\OcoSynaplan\Tests\Unit\BackgroundJob;

use OCA\OcoSynaplan\BackgroundJob\ReuploadJob;
use OCA\OcoSynaplan\BackgroundJob\SourceEventsJob;
use OCA\OcoSynaplan\Service\PlatformLinkService;
use OCA\OcoSynaplan\Tests\Unit\Support\ArraySourceEventQueue;
use OCA\OcoSynaplan\Tests\Unit\Support\Locked;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\ILogger;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class SourceEventsJobTest extends TestCase
{
    private PlatformLinkService&MockObject $links;
    private IJobList&MockObject $jobs;
    private ILockingProvider&MockObject $locks;
    private ILogger&MockObject $logger;
    private ArraySourceEventQueue $queue;
    private int $now = 1_000;
    /** @var list<array{string, list<array<string, string>>}> */
    private array $sent = [];
    /** @var list<array{string, mixed}> what the job list was asked, as `add`, `remove` or `removeById` and the job */
    private array $list = [];
    /** Runs when the job list removes a job, as a request queueing an event meanwhile would. */
    private ?\Closure $onRemove = null;

    protected function setUp(): void
    {
        $this->links = $this->createMock(PlatformLinkService::class);
        $this->jobs = $this->createMock(IJobList::class);
        $this->locks = $this->createMock(ILockingProvider::class);
        $this->logger = $this->createMock(ILogger::class);
        $this->queue = new ArraySourceEventQueue();
        $this->jobs->method('add')->willReturnCallback(function (mixed $job, mixed $argument = null): void {
            $this->list[] = ['add', [$job, $argument]];
        });
        $this->jobs->method('remove')->willReturnCallback(function (mixed $job, mixed $argument = null): void {
            $this->list[] = ['remove', [$job, $argument]];
            if ($this->onRemove !== null) {
                ($this->onRemove)();
            }
        });
        $this->jobs->method('removeById')->willReturnCallback(function (mixed $id): void {
            $this->list[] = ['removeById', $id];
        });
    }

    /**
     * @param callable(list<array<string, string>>, string): array<string, mixed> $answer
     */
    private function synaplanAnswers(callable $answer): void
    {
        $this->links->method('reportSourceEvents')->willReturnCallback(function (array $events, string $uid) use ($answer): array {
            $this->sent[] = [$uid, $events];

            return $answer($events, $uid) + ['applied' => 0, 'ignored' => 0, 'reupload' => []];
        });
    }

    private function queue(string $uid, string $id, string $event, int $ord, string $path = ''): void
    {
        $this->queue->add($uid, ['id' => $id, 'event' => $event, 'version' => 'e1', 'path' => $path], $ord);
    }

    private function runJob(mixed $argument, int $id = 7): void
    {
        $time = $this->createStub(ITimeFactory::class);
        $time->method('getTime')->willReturnCallback(fn (): int => $this->now);
        $job = new SourceEventsJob($this->links, $this->jobs, $this->queue, $this->locks, $this->logger, $time);
        $job->setId($id);
        $job->setArgument($argument);
        $job->execute($this->jobs);
    }

    /**
     * @return list<string>
     */
    private function sentEvents(): array
    {
        $out = [];
        foreach ($this->sent as [$uid, $events]) {
            foreach ($events as $event) {
                $out[] = $uid . ' ' . $event['event'] . ' ' . $event['id'];
            }
        }

        return $out;
    }

    public function testAPersonsEventsGoOutInOrderInOneCallAndKeptCopiesAreQueued(): void
    {
        $this->queue('ada', '42', 'moved', 20, 'Specs/spec.pdf');
        $this->queue('ada', '42', 'changed', 10, 'Old/spec.pdf');
        $this->synaplanAnswers(static fn (): array => ['applied' => 2, 'reupload' => [['external_id' => 'ada', 'id' => '42']]]);

        $this->runJob(['uid' => 'ada']);

        self::assertSame([['ada', [
            ['id' => '42', 'event' => 'changed', 'version' => 'e1', 'path' => 'Old/spec.pdf'],
            ['id' => '42', 'event' => 'moved', 'version' => 'e1', 'path' => 'Specs/spec.pdf'],
        ]]], $this->sent);
        self::assertSame([], $this->queue->rows);
        self::assertSame([
            ['add', [ReuploadJob::class, ['uid' => 'ada', 'id' => '42']]],
            ['remove', [SourceEventsJob::class, ['uid' => 'ada']]],
        ], $this->list, 'with the queue empty, the person’s job leaves the list');
    }

    public function testARestoreNeverOvertakesTheDeletionSynaplanCouldNotTakeYet(): void
    {
        $this->queue('ada', '42', 'deleted', 10);
        $this->synaplanAnswers(fn (): array => $this->now < 1_060 ? ['retry' => true] : ['applied' => 1]);

        $this->runJob(['uid' => 'ada']);
        $this->queue('ada', '42', 'restored', 20);
        $this->now += 30;
        $this->runJob(['uid' => 'ada']);

        self::assertSame(['ada deleted 42'], $this->sentEvents(), 'the restore waits behind the deletion');
        self::assertSame([], $this->list, 'the person’s job stays in the list while the deletion waits');

        $this->now = 1_060;
        $this->runJob(['uid' => 'ada']);

        self::assertSame(['ada deleted 42', 'ada deleted 42', 'ada restored 42'], $this->sentEvents());
        self::assertSame([], $this->queue->rows);
        self::assertSame([['remove', [SourceEventsJob::class, ['uid' => 'ada']]]], $this->list);
    }

    public function testEachRetryWaitsLongerAndTheLastGivesTheEventsUp(): void
    {
        $this->queue('ada', '42', 'changed', 10);
        $this->synaplanAnswers(static fn (): array => ['retry' => true]);
        $this->logger->expects($this->once())->method('warning')
            ->with('Gave up telling Synaplan of {count} file events of {uid}', ['app' => 'oco_synaplan', 'count' => 1, 'uid' => 'ada']);

        $waits = [];
        foreach (SourceEventsJob::RETRY_DELAYS as $ignored) {
            $this->runJob(['uid' => 'ada']);
            $row = array_values($this->queue->rows)[0];
            $waits[] = $row['due'] - $this->now;
            $this->now = $row['due'];
        }
        self::assertSame(SourceEventsJob::RETRY_DELAYS, $waits);

        $this->runJob(['uid' => 'ada']);

        self::assertSame([], $this->queue->rows);
        self::assertCount(count(SourceEventsJob::RETRY_DELAYS) + 1, $this->sent);
    }

    public function testAnotherPersonsEventsDoNotWaitForARetry(): void
    {
        $this->queue('ada', '42', 'deleted', 10);
        $this->queue('bob', '42', 'deleted', 10);
        $this->synaplanAnswers(static fn (array $events, string $uid): array => $uid === 'ada' ? ['retry' => true] : ['applied' => 1]);

        $this->runJob(['uid' => 'ada']);
        $this->runJob(['uid' => 'bob']);

        self::assertSame(['ada deleted 42', 'bob deleted 42'], $this->sentEvents());
        self::assertSame(['ada'], array_values(array_map(static fn (array $row): string => $row['uid'], $this->queue->rows)));
    }

    public function testAnEventQueuedAsThePersonsJobLeavesBringsTheJobBack(): void
    {
        $this->queue('ada', '42', 'changed', 10);
        $this->synaplanAnswers(static fn (): array => ['applied' => 1]);
        $this->onRemove = function (): void {
            $this->onRemove = null;
            $this->queue('ada', '43', 'changed', 20);
        };

        $this->runJob(['uid' => 'ada']);

        self::assertSame([
            ['remove', [SourceEventsJob::class, ['uid' => 'ada']]],
            ['add', [SourceEventsJob::class, ['uid' => 'ada']]],
        ], $this->list);
        self::assertSame(['ada send 43'], $this->queue->steps());
    }

    public function testAJobQueuedBeforeTheQueueSendsItsEventAndMakesWayForThePersonsJob(): void
    {
        $this->synaplanAnswers(static fn (): array => ['applied' => 1]);

        $this->runJob(['uid' => 'ada', 'id' => '42', 'event' => 'changed', 'version' => 'e2', 'path' => 'Specs/spec.pdf', 'attempts' => 1], 31);

        self::assertSame([['ada', [['id' => '42', 'event' => 'changed', 'version' => 'e2', 'path' => 'Specs/spec.pdf']]]], $this->sent);
        self::assertSame([
            ['removeById', 31],
            ['add', [SourceEventsJob::class, ['uid' => 'ada']]],
            ['remove', [SourceEventsJob::class, ['uid' => 'ada']]],
        ], $this->list);
    }

    public function testWhileAnotherRunSendsThePersonsEventsThisOneLeavesThemToIt(): void
    {
        $this->queue('ada', '42', 'changed', 10);
        $this->locks->method('acquireLock')->willThrowException(new Locked('oco_synaplan/source-events/' . md5('ada')));
        $this->links->expects($this->never())->method('reportSourceEvents');

        $this->runJob(['uid' => 'ada']);

        self::assertSame([], $this->list, 'the job stays for its next run');
        self::assertCount(1, $this->queue->rows);
    }

    public function testThePersonsLockFitsTheLockTable(): void
    {
        $this->synaplanAnswers(static fn (): array => ['applied' => 1]);
        $uid = str_repeat('a', 64);
        $this->locks->expects($this->once())->method('acquireLock')
            ->with($this->callback(static fn (string $key): bool => strlen($key) <= 64), ILockingProvider::LOCK_EXCLUSIVE);
        $this->locks->expects($this->once())->method('releaseLock');

        $this->runJob(['uid' => $uid]);
    }

    public function testAJobWithoutAPersonSendsNothingAndLeavesTheListByItsId(): void
    {
        $this->links->expects($this->never())->method('reportSourceEvents');

        $this->runJob(['id' => '42', 'event' => 'changed', 'version' => 'e2', 'path' => 'Specs/spec.pdf'], 31);
        $this->runJob(null, 32);

        self::assertSame([['removeById', 31], ['removeById', 32]], $this->list);
    }
}
