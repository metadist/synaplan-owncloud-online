<?php

declare(strict_types=1);

namespace OCA\OcoSynaplan\Tests\Unit\Migration;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use OCA\oco_synaplan\Migrations\Version20261124000000;
use OCA\oco_synaplan\Migrations\Version20261124010000;
use OCA\oco_synaplan\Migrations\Version20261124020000;
use PHPUnit\Framework\TestCase;

final class WatchTablesTest extends TestCase
{
    public function testTheMigrationCreatesTheWatchesTheirQueueAndTheOwnWrites(): void
    {
        $schema = new Schema();

        (new Version20261124000000())->changeSchema($schema, ['tablePrefix' => 'oc_']);

        $watches = $schema->getTable('oc_synaplan_watches');
        self::assertSame([
            'watch_id' => Types::BIGINT,
            'uid' => Types::STRING,
            'target_kind' => Types::STRING,
            'target_id' => Types::BIGINT,
            'state' => Types::STRING,
            'since' => Types::BIGINT,
            'reconciled' => Types::BIGINT,
        ], self::columns($watches->getColumns()));
        self::assertSame(['watch_id'], $watches->getPrimaryKey()?->getColumns());
        self::assertSame(['target_id'], $watches->getIndex('synaplan_watches_target')->getColumns());
        self::assertSame(['uid'], $watches->getIndex('synaplan_watches_uid')->getColumns());

        $queue = $schema->getTable('oc_synaplan_watch_queue');
        self::assertSame([
            'watch_id' => Types::BIGINT,
            'file_id' => Types::BIGINT,
            'event' => Types::STRING,
            'changed' => Types::BIGINT,
            'generation' => Types::STRING,
            'due' => Types::BIGINT,
            'attempts' => Types::INTEGER,
        ], self::columns($queue->getColumns()));
        self::assertSame(['watch_id', 'file_id'], $queue->getPrimaryKey()?->getColumns());
        self::assertFalse($queue->getColumn('event')->getNotnull(), 'a plain change has no event');

        $own = $schema->getTable('oc_synaplan_own_writes');
        self::assertSame(['file_id' => Types::BIGINT, 'written' => Types::BIGINT, 'version' => Types::STRING], self::columns($own->getColumns()));
        self::assertSame(['file_id'], $own->getPrimaryKey()?->getColumns());
        self::assertSame(['written'], $own->getIndex('synaplan_own_writes_written')->getColumns());
    }

    public function testTablesThatAreThereAlreadyAreLeftAlone(): void
    {
        $schema = new Schema();
        foreach (['oc_synaplan_watches', 'oc_synaplan_watch_queue', 'oc_synaplan_own_writes'] as $name) {
            $schema->createTable($name)->addColumn('watch_id', Types::BIGINT);
        }

        (new Version20261124000000())->changeSchema($schema, ['tablePrefix' => 'oc_']);

        foreach (['oc_synaplan_watches', 'oc_synaplan_watch_queue', 'oc_synaplan_own_writes'] as $name) {
            self::assertSame(['watch_id'], array_keys($schema->getTable($name)->getColumns()), $name);
        }
    }

    public function testAWatchKeepsWhySynaplanHoldsIt(): void
    {
        $schema = new Schema();
        (new Version20261124000000())->changeSchema($schema, ['tablePrefix' => 'oc_']);

        (new Version20261124010000())->changeSchema($schema, ['tablePrefix' => 'oc_']);
        (new Version20261124010000())->changeSchema($schema, ['tablePrefix' => 'oc_']);

        $reason = $schema->getTable('oc_synaplan_watches')->getColumn('reason');
        self::assertSame(Types::STRING, Type::lookupName($reason->getType()));
        self::assertFalse($reason->getNotnull(), 'watches noted before it have none');
        self::assertSame(32, $reason->getLength());
    }

    public function testWithoutTheWatchesTableTheReasonWaits(): void
    {
        $schema = new Schema();

        (new Version20261124010000())->changeSchema($schema, ['tablePrefix' => 'oc_']);

        self::assertFalse($schema->hasTable('oc_synaplan_watches'));
    }

    public function testAWatchKeepsWhereACutShortPassStopped(): void
    {
        $schema = new Schema();
        (new Version20261124000000())->changeSchema($schema, ['tablePrefix' => 'oc_']);

        (new Version20261124020000())->changeSchema($schema, ['tablePrefix' => 'oc_']);
        (new Version20261124020000())->changeSchema($schema, ['tablePrefix' => 'oc_']);

        $after = $schema->getTable('oc_synaplan_watches')->getColumn('reconcile_after');
        self::assertSame(Types::BIGINT, Type::lookupName($after->getType()));
        self::assertFalse($after->getNotnull(), 'watches noted before it have none');
        self::assertSame('0', (string) $after->getDefault());
    }

    public function testWithoutTheWatchesTableTheStopPointWaits(): void
    {
        $schema = new Schema();

        (new Version20261124020000())->changeSchema($schema, ['tablePrefix' => 'oc_']);

        self::assertFalse($schema->hasTable('oc_synaplan_watches'));
    }

    /**
     * @param array<string, Column> $columns
     * @return array<string, string>
     */
    private static function columns(array $columns): array
    {
        return array_map(static fn (Column $column): string => Type::lookupName($column->getType()), $columns);
    }
}
