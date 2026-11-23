<?php

declare(strict_types=1);

namespace OCA\OcoSynaplan\Tests\Unit\Service;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Result;
use OCA\OcoSynaplan\Service\OwnWrites;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\File;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

final class OwnWritesTest extends TestCase
{
    private const NOW = 1700000000;

    /** @var array<int, array{written: int, version: string}> */
    private array $rows = [];
    private bool $tableExists = true;

    public function testAWriteIsKeptWithTheVersionTheFileHasAfterIt(): void
    {
        $this->writes()->note($this->file(7, 'e1'), self::NOW);

        self::assertSame([7 => ['written' => self::NOW, 'version' => 'e1']], $this->rows);
        self::assertTrue($this->writes()->isOwnVersion(7, 'e1'));
        self::assertFalse($this->writes()->isOwnVersion(7, 'e2'), 'a person changed it since');
        self::assertFalse($this->writes()->isOwnVersion(8, 'e1'));
    }

    public function testWritingTheFileAgainKeepsTheNewerVersion(): void
    {
        $writes = $this->writes();
        $writes->note($this->file(7, 'e1'), self::NOW);
        $writes->note($this->file(7, 'e2'), self::NOW + 5);

        self::assertSame([7 => ['written' => self::NOW + 5, 'version' => 'e2']], $this->rows);
    }

    public function testWritesOlderThanAWeekAreForgotten(): void
    {
        $this->rows = [7 => ['written' => self::NOW - 8 * 86400, 'version' => 'e1'], 8 => ['written' => self::NOW - 86400, 'version' => 'e2']];

        $this->writes()->prune(self::NOW);

        self::assertSame([8], array_keys($this->rows));
    }

    public function testBeforeTheMigrationRanNothingIsKeptOrAsked(): void
    {
        $this->tableExists = false;
        $writes = $this->writes();
        $writes->note($this->file(7, 'e1'), self::NOW);
        $writes->prune(self::NOW);

        self::assertSame([], $this->rows);
        self::assertFalse($writes->isOwnVersion(7, 'e1'));
    }

    private function file(int $id, string $etag): File
    {
        $file = $this->createStub(File::class);
        $file->method('getId')->willReturn($id);
        $file->method('getEtag')->willReturn($etag);

        return $file;
    }

    private function writes(): OwnWrites
    {
        $db = $this->createStub(IDBConnection::class);
        $db->method('tableExists')->willReturnCallback(fn (): bool => $this->tableExists);
        $db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->builder());

        return new OwnWrites($db);
    }

    /**
     * A query builder over $this->rows that understands the few queries
     * OwnWrites makes.
     */
    private function builder(): IQueryBuilder
    {
        $kind = '';
        $values = [];
        $where = null;
        $qb = $this->createStub(IQueryBuilder::class);
        $expr = $this->createStub(IExpressionBuilder::class);
        $expr->method('eq')->willReturnCallback(static fn (mixed $column, mixed $value): array => ['=', $value]);
        $expr->method('lt')->willReturnCallback(static fn (mixed $column, mixed $value): array => ['<', $value]);
        $qb->method('expr')->willReturn($expr);
        $qb->method('createNamedParameter')->willReturnCallback(static fn (mixed $value): mixed => $value);
        foreach (['insert', 'update', 'delete', 'select'] as $method) {
            $qb->method($method)->willReturnCallback(static function () use ($qb, &$kind, $method): IQueryBuilder {
                $kind = $method;

                return $qb;
            });
        }
        $qb->method('from')->willReturn($qb);
        $qb->method('values')->willReturnCallback(static function (array $given) use ($qb, &$values): IQueryBuilder {
            $values = $given;

            return $qb;
        });
        $qb->method('set')->willReturnCallback(static function (string $column, mixed $value) use ($qb, &$values): IQueryBuilder {
            $values[$column] = $value;

            return $qb;
        });
        $qb->method('where')->willReturnCallback(static function (mixed $condition) use ($qb, &$where): IQueryBuilder {
            $where = $condition;

            return $qb;
        });
        $qb->method('execute')->willReturnCallback(function () use (&$kind, &$values, &$where): int|Result {
            switch ($kind) {
                case 'insert':
                    if (isset($this->rows[$values['file_id']])) {
                        throw $this->createStub(UniqueConstraintViolationException::class);
                    }
                    $this->rows[$values['file_id']] = ['written' => $values['written'], 'version' => $values['version']];

                    return 1;
                case 'update':
                    $this->rows[$where[1]] = ['written' => $values['written'], 'version' => $values['version']];

                    return 1;
                case 'delete':
                    $before = count($this->rows);
                    $this->rows = array_filter($this->rows, static fn (array $row): bool => $row['written'] >= $where[1]);

                    return $before - count($this->rows);
                default:
                    $result = $this->createStub(Result::class);
                    $result->method('fetchOne')->willReturn($this->rows[$where[1]]['version'] ?? false);

                    return $result;
            }
        });

        return $qb;
    }
}
