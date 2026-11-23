<?php

declare(strict_types=1);

namespace OCA\OcoSynaplan\Tests\Unit\Service;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Result;
use OCA\OcoSynaplan\Service\WatchIndex;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IParameter;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * The statements the queue writes, as text: `kind table`, then each value,
 * `set` and condition.
 */
final class WatchIndexQueueTest extends TestCase
{
    /** @var list<list<string>> */
    private array $statements = [];
    /** @var list<string> */
    private array $generations = [];
    private bool $rowExists = false;

    public function testEachQueuedChangeGetsANewGeneration(): void
    {
        $index = $this->index();
        $index->enqueue(5, 7, '', 100);
        $this->rowExists = true;
        $index->enqueue(5, 7, '', 100);

        $replaced = $this->generations[array_key_last($this->generations)];
        self::assertNotSame($this->generations[0], $replaced);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $replaced);
        self::assertContains('set generation=' . json_encode($replaced), $this->last());
    }

    public function testAChangeAlreadyQueuedStaysWhenReconciliationQueuesAnOlderOne(): void
    {
        $this->rowExists = true;

        self::assertFalse($this->index()->enqueueIfAbsent(5, 7, '', 100, 160));
        self::assertSame('insert synaplan_watch_queue', $this->last()[0]);
    }

    public function testAFileNotQueuedYetIsQueued(): void
    {
        self::assertTrue($this->index()->enqueueIfAbsent(5, 7, '', 100, 160));
        self::assertContains('value due=160', $this->last());
    }

    public function testSettlingARowMatchesItsGenerationOnly(): void
    {
        $this->index()->done(['watch' => 5, 'file' => 7, 'event' => 'deleted', 'changed' => 100, 'generation' => 'g1', 'attempts' => 0]);

        self::assertSame(['delete synaplan_watch_queue', 'where watch_id=5', 'where file_id=7', 'where generation="g1"'], $this->last());
    }

    public function testResumingWithOnlyNewFilesLetsTheFilesQueuedBeforeGoButKeepsEvents(): void
    {
        $this->index()->restartFrom(5, 1700000000);

        self::assertSame(['update synaplan_watches', 'set since=1700000000', 'where watch_id=5'], $this->statements[0]);
        // A file queued in the resume's own second goes too.
        self::assertSame(['delete synaplan_watch_queue', 'where watch_id=5', 'where event IS NULL'], $this->last());
    }

    public function testASyncThatReadSynaplansListBeforeTheResumeLeavesTheNewStart(): void
    {
        $this->rowExists = true;
        $this->index()->replace('ada', [['id' => 5, 'target_kind' => 'folder', 'target_id' => '30', 'state' => 'paused']], 100);

        $updates = array_values(array_filter($this->statements, static fn (array $statement): bool => ($statement[0] ?? '') === 'update synaplan_watches'));
        self::assertCount(1, $updates);
        self::assertSame([], array_values(array_filter($updates[0], static fn (string $line): bool => str_starts_with($line, 'set since'))));
    }

    public function testAPassCutShortIsContinuedAndAFinishedOneStartsOver(): void
    {
        $index = $this->index();
        $index->continueAfter(5, 500);
        self::assertSame(['update synaplan_watches', 'set reconcile_after=500', 'where watch_id=5'], $this->last());

        $index->markReconciled(5, 200);
        self::assertSame(['update synaplan_watches', 'set reconciled=200', 'set reconcile_after=0', 'where watch_id=5'], $this->last());
    }

    public function testAQueuedDeletionBecomesARestoreAsANewChange(): void
    {
        self::assertTrue($this->index()->replaceEvent(5, 30, 'deleted', 'restored', 200));

        $statement = $this->last();
        self::assertSame('update synaplan_watch_queue', $statement[0]);
        self::assertContains('set event="restored"', $statement);
        self::assertContains('set changed=200', $statement);
        self::assertContains('set generation=' . json_encode($this->generations[0]), $statement);
        self::assertContains('where event="deleted"', $statement);
    }

    public function testAWatchIsNotedAsHavingLostItsTargetAndAsRunningAgain(): void
    {
        $index = $this->index();
        $index->markTarget(5, true);
        self::assertSame(['update synaplan_watches', 'set state="attention"', 'set reason="target_gone"', 'where watch_id=5'], $this->last());

        $index->markTarget(5, false);
        self::assertSame(['update synaplan_watches', 'set state="active"', 'set reason=""', 'where watch_id=5'], $this->last());
    }

    public function testARetryOfAReplacedRowLeavesTheNewerChange(): void
    {
        $this->index()->retryLater(['watch' => 5, 'file' => 7, 'event' => '', 'changed' => 100, 'generation' => 'g1', 'attempts' => 0], 160, 1);

        self::assertContains('where generation="g1"', $this->last());
    }

    private function index(): WatchIndex
    {
        $db = $this->createStub(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->builder());
        $db->method('tableExists')->willReturn(true);

        return new WatchIndex($db);
    }

    private function builder(): IQueryBuilder
    {
        $at = count($this->statements);
        $this->statements[$at] = [];
        $record = function (string $line) use ($at): void {
            $this->statements[$at][] = $line;
        };
        $qb = $this->createStub(IQueryBuilder::class);
        $expr = $this->createStub(IExpressionBuilder::class);
        $expr->method('eq')->willReturnCallback(static fn (mixed $x, mixed $y): string => $x . '=' . $y);
        $expr->method('isNull')->willReturnCallback(static fn (mixed $x): string => $x . ' IS NULL');
        $qb->method('expr')->willReturn($expr);
        $qb->method('createNamedParameter')->willReturnCallback(function (mixed $value): IParameter {
            $parameter = $this->createStub(IParameter::class);
            $parameter->method('__toString')->willReturn((string) json_encode($value));

            return $parameter;
        });
        $qb->method('select')->willReturnCallback(static function () use ($qb, $record): IQueryBuilder {
            $record('select');

            return $qb;
        });
        $qb->method('from')->willReturn($qb);
        foreach (['insert', 'update', 'delete'] as $kind) {
            $qb->method($kind)->willReturnCallback(static function (string $table) use ($qb, $record, $kind): IQueryBuilder {
                $record($kind . ' ' . $table);

                return $qb;
            });
        }
        $qb->method('values')->willReturnCallback(function (array $values) use ($qb, $record): IQueryBuilder {
            foreach ($values as $column => $value) {
                $record('value ' . $column . '=' . $value);
                if ($column === 'generation') {
                    $this->generations[] = (string) json_decode((string) $value);
                }
            }

            return $qb;
        });
        $qb->method('set')->willReturnCallback(function (string $column, mixed $value) use ($qb, $record): IQueryBuilder {
            $record('set ' . $column . '=' . $value);
            if ($column === 'generation') {
                $this->generations[] = (string) json_decode((string) $value);
            }

            return $qb;
        });
        foreach (['where', 'andWhere'] as $method) {
            $qb->method($method)->willReturnCallback(static function (string $condition) use ($qb, $record): IQueryBuilder {
                $record('where ' . $condition);

                return $qb;
            });
        }
        $qb->method('execute')->willReturnCallback(function () use ($at): int|Result {
            $kind = $this->statements[$at][0] ?? '';
            if ($kind === 'select') {
                // The known watch, as the sync finds it.
                $rows = $this->rowExists ? [['watch_id' => 5, 'uid' => 'ada', 'target_kind' => 'folder', 'target_id' => 30, 'state' => 'active', 'since' => 200]] : [];
                $result = $this->createStub(Result::class);
                $result->method('fetchAssociative')->willReturnOnConsecutiveCalls(...[...$rows, false]);

                return $result;
            }
            if ($this->rowExists && str_starts_with($kind, 'insert ')) {
                throw $this->createStub(UniqueConstraintViolationException::class);
            }

            return 1;
        });

        return $qb;
    }

    /**
     * @return list<string>
     */
    private function last(): array
    {
        return $this->statements[array_key_last($this->statements)] ?? [];
    }
}
