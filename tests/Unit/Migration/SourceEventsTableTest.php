<?php

declare(strict_types=1);

namespace OCA\OcoSynaplan\Tests\Unit\Migration;

use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use OCA\oco_synaplan\Migrations\Version20261123140000;
use PHPUnit\Framework\TestCase;

final class SourceEventsTableTest extends TestCase
{
    public function testTheMigrationCreatesTheQueueOrderedPerPerson(): void
    {
        $schema = new Schema();

        (new Version20261123140000())->changeSchema($schema, ['tablePrefix' => 'oc_']);

        $table = $schema->getTable('oc_synaplan_source_events');
        self::assertSame([
            'seq' => Types::BIGINT,
            'ord' => Types::BIGINT,
            'uid' => Types::STRING,
            'file_id' => Types::BIGINT,
            'event' => Types::STRING,
            'version' => Types::STRING,
            'path' => Types::STRING,
            'step' => Types::STRING,
            'walked' => Types::BIGINT,
            'due' => Types::BIGINT,
            'attempts' => Types::INTEGER,
        ], array_map(static fn (Column $column): string => Type::lookupName($column->getType()), $table->getColumns()));
        self::assertTrue($table->getColumn('seq')->getAutoincrement());
        self::assertSame(['seq'], $table->getPrimaryKey()?->getColumns());
        self::assertSame(['uid', 'ord', 'seq'], $table->getIndex('synaplan_source_ev_order')->getColumns());
        self::assertSame('send', $table->getColumn('step')->getDefault());
        self::assertSame(255, $table->getColumn('path')->getLength());
    }

    public function testATableThatIsThereAlreadyIsLeftAlone(): void
    {
        $schema = new Schema();
        $schema->createTable('oc_synaplan_source_events')->addColumn('seq', Types::BIGINT);

        (new Version20261123140000())->changeSchema($schema, ['tablePrefix' => 'oc_']);

        self::assertSame(['seq'], array_keys($schema->getTable('oc_synaplan_source_events')->getColumns()));
    }
}
