<?php

declare(strict_types=1);

/**
 * Synaplan Integration for owncloud.online
 *
 * @copyright Copyright (c) 2026, Synaplan
 * @license Apache-2.0
 */

namespace OCA\oco_synaplan\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\DBAL\Types\Types;
use OCP\Migration\ISchemaMigration;

/**
 * Each person's source events for Synaplan, sent in the order they happened:
 * `ord` is when, `seq` breaks ties, and a row that waits for a retry holds
 * back the rows after it.
 *
 * ownCloud loads an app's migrations from appinfo/Migrations under
 * OCA\<app id>\Migrations.
 */
class Version20261123140000 implements ISchemaMigration
{
    /**
     * @param array<string, mixed> $options
     */
    public function changeSchema(Schema $schema, array $options): void
    {
        $name = (string) ($options['tablePrefix'] ?? '') . 'synaplan_source_events';
        if ($schema->hasTable($name)) {
            return;
        }
        $table = $schema->createTable($name);
        $table->addColumn('seq', Types::BIGINT, ['autoincrement' => true, 'notnull' => true, 'unsigned' => true]);
        $table->addColumn('ord', Types::BIGINT, ['notnull' => true]);
        $table->addColumn('uid', Types::STRING, ['notnull' => true, 'length' => 64]);
        $table->addColumn('file_id', Types::BIGINT, ['notnull' => true]);
        $table->addColumn('event', Types::STRING, ['notnull' => true, 'length' => 16]);
        $table->addColumn('version', Types::STRING, ['notnull' => false, 'length' => 255]);
        $table->addColumn('path', Types::STRING, ['notnull' => false, 'length' => 255]);
        $table->addColumn('step', Types::STRING, ['notnull' => true, 'length' => 8, 'default' => 'send']);
        $table->addColumn('walked', Types::BIGINT, ['notnull' => true, 'default' => 0]);
        $table->addColumn('due', Types::BIGINT, ['notnull' => true, 'default' => 0]);
        $table->addColumn('attempts', Types::INTEGER, ['notnull' => true, 'default' => 0]);
        $table->setPrimaryKey(['seq']);
        $table->addIndex(['uid', 'ord', 'seq'], 'synaplan_source_ev_order');
    }
}
