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
 * The watches of the people here by target, the files that wait out their
 * quiet period before they go to a watch, and the versions of the files the
 * app wrote itself, which a watch does not send back to Synaplan.
 */
class Version20261124000000 implements ISchemaMigration
{
    /**
     * @param array<string, mixed> $options
     */
    public function changeSchema(Schema $schema, array $options): void
    {
        $prefix = (string) ($options['tablePrefix'] ?? '');

        if (!$schema->hasTable($prefix . 'synaplan_watches')) {
            $table = $schema->createTable($prefix . 'synaplan_watches');
            $table->addColumn('watch_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('uid', Types::STRING, ['notnull' => true, 'length' => 64]);
            $table->addColumn('target_kind', Types::STRING, ['notnull' => true, 'length' => 8]);
            $table->addColumn('target_id', Types::BIGINT, ['notnull' => true]);
            $table->addColumn('state', Types::STRING, ['notnull' => true, 'length' => 16]);
            $table->addColumn('since', Types::BIGINT, ['notnull' => true, 'default' => 0]);
            $table->addColumn('reconciled', Types::BIGINT, ['notnull' => true, 'default' => 0]);
            $table->setPrimaryKey(['watch_id']);
            $table->addIndex(['target_id'], 'synaplan_watches_target');
            $table->addIndex(['uid'], 'synaplan_watches_uid');
        }

        if (!$schema->hasTable($prefix . 'synaplan_watch_queue')) {
            $table = $schema->createTable($prefix . 'synaplan_watch_queue');
            $table->addColumn('watch_id', Types::BIGINT, ['notnull' => true, 'unsigned' => true]);
            $table->addColumn('file_id', Types::BIGINT, ['notnull' => true]);
            $table->addColumn('event', Types::STRING, ['notnull' => false, 'length' => 16]);
            $table->addColumn('changed', Types::BIGINT, ['notnull' => true]);
            $table->addColumn('generation', Types::STRING, ['notnull' => true, 'length' => 32]);
            $table->addColumn('due', Types::BIGINT, ['notnull' => true, 'default' => 0]);
            $table->addColumn('attempts', Types::INTEGER, ['notnull' => true, 'default' => 0]);
            $table->setPrimaryKey(['watch_id', 'file_id']);
            $table->addIndex(['due'], 'synaplan_watch_queue_due');
        }

        if (!$schema->hasTable($prefix . 'synaplan_own_writes')) {
            $table = $schema->createTable($prefix . 'synaplan_own_writes');
            $table->addColumn('file_id', Types::BIGINT, ['notnull' => true]);
            $table->addColumn('written', Types::BIGINT, ['notnull' => true]);
            $table->addColumn('version', Types::STRING, ['notnull' => false, 'length' => 255]);
            $table->setPrimaryKey(['file_id']);
            $table->addIndex(['written'], 'synaplan_own_writes_written');
        }
    }
}
