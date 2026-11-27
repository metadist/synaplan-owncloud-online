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
 * Why Synaplan holds a watch, so a restored target is reported only to a
 * watch that lost it.
 */
class Version20261124010000 implements ISchemaMigration
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
        if (!$table->hasColumn('reason')) {
            $table->addColumn('reason', Types::STRING, ['notnull' => false, 'length' => 32]);
        }
    }
}
