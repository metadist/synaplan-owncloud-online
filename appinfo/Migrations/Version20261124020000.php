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
 * Where a watch's reconcile pass stopped at its deadline, so the next run
 * goes on from there instead of asking about the same files again.
 */
class Version20261124020000 implements ISchemaMigration
{
    /**
     * @param array<string, mixed> $options
     */
    public function changeSchema(Schema $schema, array $options): void
    {
        $name = (string) ($options['tablePrefix'] ?? '') . 'synaplan_watches';
        if (!$schema->hasTable($name)) {
            return;
        }
        $table = $schema->getTable($name);
        if (!$table->hasColumn('reconcile_after')) {
            $table->addColumn('reconcile_after', Types::BIGINT, ['notnull' => false, 'default' => 0]);
        }
    }
}
