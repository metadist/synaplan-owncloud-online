<?php

declare(strict_types=1);

namespace OCA\OcoSynaplan\Tests\Unit\Service;

use Doctrine\DBAL\Cache\ArrayResult;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use OCA\OcoSynaplan\Service\SourceEventQueue;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\ILiteral;
use OCP\DB\QueryBuilder\IParameter;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

/**
 * The statements the queue builds, as text: `kind table`, then each value,
 * `set`, condition and order.
 */
final class SourceEventQueueTest extends TestCase
{
    /** @var list<list<string>> */
    private array $statements = [];
    /** @var list<array<string, mixed>> the rows a select of the queue answers */
    private array $rows = [];
    /** @var list<string> */
    private array $sql = [];
    /** The file cache path of folder 50 on storage 3, null when it is not there. */
    private ?string $folderPath = null;
    /** @var list<array<string, mixed>> the page the file cache answers */
    private array $cacheRows = [];

    public function testAPersonsHeadIsReadInTheOrderTheEventsHappened(): void
    {
        $this->rows = [['seq' => '3', 'ord' => '1700', 'uid' => 'ada', 'file_id' => '42', 'event' => 'deleted', 'version' => null, 'path' => null, 'step' => 'send', 'walked' => '0', 'due' => '0', 'attempts' => '1']];

        $head = $this->queue()->head('ada', 100);

        self::assertSame(
            ['select synaplan_source_events', 'where uid="ada"', 'order ord ASC', 'order seq ASC', 'limit 100'],
            $this->statements[0],
        );
        self::assertSame([[
            'seq' => 3, 'ord' => 1_700, 'uid' => 'ada', 'file_id' => 42, 'event' => 'deleted', 'version' => '', 'path' => '',
            'step' => 'send', 'walked' => 0, 'due' => 0, 'attempts' => 1,
        ]], $head);
    }

    public function testAnEventIsQueuedWithItsOrderAndStep(): void
    {
        $this->queue()->add('ada', ['id' => '42', 'event' => 'deleted', 'version' => 'e1', 'path' => ''], 1_700);

        self::assertSame([
            'insert synaplan_source_events',
            'value ord=1700',
            'value uid="ada"',
            'value file_id=42',
            'value event="deleted"',
            'value version="e1"',
            'value path=""',
            'value step="send"',
            'value walked=0',
        ], $this->statements[0]);
    }

    public function testARetryHoldsTheRowsBackUntilTheyAreDue(): void
    {
        $this->queue()->retryLater([3, 4], 2, 1_300);

        self::assertSame(['update synaplan_source_events', 'set attempts=2', 'set due=1300', 'where seq IN [3,4]'], $this->statements[0]);
    }

    public function testRemovingManyRowsTakesChunksTheDatabaseAccepts(): void
    {
        $this->queue()->remove(range(1, 501));

        self::assertCount(2, $this->statements);
        self::assertSame('where seq IN [501]', $this->statements[1][1]);
    }

    public function testAFoldersFilesAreQueuedInOneStatementFromTheFileCache(): void
    {
        $this->folderPath = 'files/Specs_1';
        $this->queue()->addDescendants('ada', 50, 'deleted', 1_700, SourceEventQueue::STEP_HELD, 50);

        self::assertSame([
            'INSERT INTO `*PREFIX*synaplan_source_events` (`ord`, `uid`, `file_id`, `event`, `version`, `path`, `step`, `walked`) SELECT',
        ], $this->sql);
        self::assertSame([
            'select 1700,"ada",fileid,"deleted",etag,"","held",50',
            'select filecache',
            'where storage=3',
            'where path LIKE "files/Specs\\\\_1/%"',
        ], $this->statements[1]);
        self::assertSame(['select filecache', 'where fileid=50'], $this->statements[0]);
    }

    public function testAFolderAtTheRootOfItsStorageTakesEverythingOnItButTheRoot(): void
    {
        $this->folderPath = '';
        $this->queue()->addDescendants('ada', 50, 'restored', 1_700, SourceEventQueue::STEP_SEND);

        self::assertSame('where path_hash<>"' . md5('') . '"', $this->statements[1][3]);
    }

    public function testAMovedFolderIsReadAPageAtATimeByFileIdWithPathsBelowIt(): void
    {
        $this->folderPath = 'files/Specs';
        $this->cacheRows = [['fileid' => 121, 'path' => 'files/Specs/Old/b.pdf', 'etag' => 'eb']];

        self::assertSame([['id' => 121, 'path' => 'Old/b.pdf', 'etag' => 'eb']], $this->queue()->descendants(50, 120, 500));
        self::assertSame(
            ['select filecache', 'where storage=3', 'where path LIKE "files/Specs/%"', 'where fileid > 120', 'order fileid ASC', 'limit 500'],
            $this->statements[1],
        );
    }

    public function testAFolderNoLongerInTheFileCacheQueuesNothing(): void
    {
        $this->queue()->addDescendants('ada', 50, 'deleted', 1_700, SourceEventQueue::STEP_HELD, 50);

        self::assertSame([], $this->sql);
        self::assertSame([], $this->queue()->descendants(50, 0, 500));
    }

    public function testADeletionThatHappenedReleasesItsHeldRows(): void
    {
        $this->queue()->release(50);

        self::assertSame(['update synaplan_source_events', 'set step="send"', 'where step="held"', 'where walked=50'], $this->statements[0]);
    }

    public function testTheHeldRowsOfADeletionThatNeverHappenedGoForThatPerson(): void
    {
        $this->queue()->removeHeld('ada', 50);

        self::assertSame(['delete synaplan_source_events', 'where uid="ada"', 'where step="held"', 'where walked=50'], $this->statements[0]);
    }

    public function testAWalkRemembersTheLastFileItQueued(): void
    {
        $this->queue()->walked(9, 121);

        self::assertSame(['update synaplan_source_events', 'set walked=121', 'where seq=9'], $this->statements[0]);
    }

    public function testTheOrderOfLaterEventsIsLater(): void
    {
        $first = SourceEventQueue::now();
        usleep(2);

        self::assertGreaterThan($first, SourceEventQueue::now());
    }

    private function queue(): SourceEventQueue
    {
        $db = $this->createStub(IDBConnection::class);
        $db->method('escapeLikeParameter')->willReturnCallback(static fn (string $value): string => addcslashes($value, '\\_%'));
        $db->method('executeStatement')->willReturnCallback(function (string $sql): int {
            $this->sql[] = $sql;

            return 1;
        });
        $db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->builder());

        return new SourceEventQueue($db);
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
        $expr->method('neq')->willReturnCallback(static fn (mixed $x, mixed $y): string => $x . '<>' . $y);
        $expr->method('in')->willReturnCallback(static fn (mixed $x, mixed $y): string => $x . ' IN ' . $y);
        $expr->method('like')->willReturnCallback(static fn (mixed $x, mixed $y): string => $x . ' LIKE ' . $y);
        $expr->method('gt')->willReturnCallback(static fn (mixed $x, mixed $y): string => $x . ' > ' . $y);
        $expr->method('literal')->willReturnCallback(function (mixed $value): ILiteral {
            $literal = $this->createStub(ILiteral::class);
            $literal->method('__toString')->willReturn((string) json_encode($value, JSON_UNESCAPED_SLASHES));

            return $literal;
        });
        $qb->method('getTableName')->willReturnCallback(static fn (string $table): string => '`*PREFIX*' . $table . '`');
        $qb->method('getColumnName')->willReturnCallback(static fn (string $column): string => '`' . $column . '`');
        $qb->method('getSQL')->willReturn('SELECT');
        $qb->method('getParameters')->willReturn([]);
        $qb->method('getParameterTypes')->willReturn([]);
        $qb->method('expr')->willReturn($expr);
        $qb->method('createNamedParameter')->willReturnCallback(function (mixed $value): IParameter {
            $parameter = $this->createStub(IParameter::class);
            $parameter->method('__toString')->willReturn((string) json_encode($value, JSON_UNESCAPED_SLASHES));

            return $parameter;
        });
        foreach (['insert', 'update', 'delete', 'from'] as $kind) {
            $qb->method($kind)->willReturnCallback(static function (string $table) use ($qb, $record, $kind): IQueryBuilder {
                $record(($kind === 'from' ? 'select' : $kind) . ' ' . $table);

                return $qb;
            });
        }
        $qb->method('select')->willReturnCallback(static function (mixed $columns) use ($qb, $record): IQueryBuilder {
            $columns = is_array($columns) ? $columns : [$columns];
            if (array_filter($columns, static fn (mixed $column): bool => !is_string($column)) !== []) {
                $record('select ' . implode(',', array_map(static fn (mixed $column): string => (string) $column, $columns)));
            }

            return $qb;
        });
        $qb->method('values')->willReturnCallback(static function (array $values) use ($qb, $record): IQueryBuilder {
            foreach ($values as $column => $value) {
                $record('value ' . $column . '=' . $value);
            }

            return $qb;
        });
        $qb->method('set')->willReturnCallback(static function (string $column, mixed $value) use ($qb, $record): IQueryBuilder {
            $record('set ' . $column . '=' . $value);

            return $qb;
        });
        foreach (['where', 'andWhere'] as $method) {
            $qb->method($method)->willReturnCallback(static function (mixed $condition) use ($qb, $record): IQueryBuilder {
                $record('where ' . $condition);

                return $qb;
            });
        }
        foreach (['orderBy', 'addOrderBy'] as $method) {
            $qb->method($method)->willReturnCallback(static function (string $column, string $direction) use ($qb, $record): IQueryBuilder {
                $record('order ' . $column . ' ' . $direction);

                return $qb;
            });
        }
        $qb->method('setMaxResults')->willReturnCallback(static function (int $limit) use ($qb, $record): IQueryBuilder {
            $record('limit ' . $limit);

            return $qb;
        });
        $qb->method('execute')->willReturnCallback(function () use ($at): Result|int {
            $lines = $this->statements[$at];
            if (!str_starts_with($lines[0] ?? '', 'select ')) {
                return 1;
            }
            $rows = match (true) {
                in_array('where fileid=50', $lines, true) => $this->folderPath === null ? [] : [['storage' => 3, 'path' => $this->folderPath]],
                $lines[0] === 'select filecache' => $this->cacheRows,
                default => $this->rows,
            };

            return new Result(new ArrayResult($rows), $this->createStub(Connection::class));
        });

        return $qb;
    }
}
