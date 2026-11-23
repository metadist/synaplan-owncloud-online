<?php

declare(strict_types=1);

/**
 * Synaplan Integration for owncloud.online
 *
 * @copyright Copyright (c) 2026, Synaplan
 * @license Apache-2.0
 */

namespace OCA\OcoSynaplan\Service;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IConfig;
use OCP\IDBConnection;

/**
 * Notification ids already accepted, remembered for ten minutes in the
 * database. A repeat is refused. The reservation is one insert, so two
 * workers cannot both accept the same id, and it does not depend on a
 * memory cache being configured.
 */
class SeenNotifications implements SeenNotificationStore
{
    private const TABLE = 'oco_synaplan_seen';
    private const TTL = 600;

    private bool $tableReady = false;

    public function __construct(
        private IDBConnection $db,
        private IConfig $config,
    ) {
    }

    public function alreadySeen(string $type, string $id): bool
    {
        $this->ensureTable();
        $now = time();
        $this->forgetExpired($now);

        $insert = $this->db->getQueryBuilder();
        $insert->insert(self::TABLE)->values([
            'notice_hash' => $insert->createNamedParameter(hash('sha256', $type . '|' . $id)),
            'expires' => $insert->createNamedParameter($now + self::TTL, IQueryBuilder::PARAM_INT),
        ]);
        try {
            $insert->execute();
        } catch (UniqueConstraintViolationException) {
            return true;
        }

        return false;
    }

    private function forgetExpired(int $now): void
    {
        $delete = $this->db->getQueryBuilder();
        $delete->delete(self::TABLE)
            ->where($delete->expr()->lt('expires', $delete->createNamedParameter($now, IQueryBuilder::PARAM_INT)));
        $delete->execute();
    }

    /**
     * Created on first use rather than by a migration, so an installed app
     * gets it without a version change. The schema API writes the statement
     * for whichever database the server uses.
     */
    private function ensureTable(): void
    {
        if (!$this->tableReady && !$this->db->tableExists(self::TABLE)) {
            $this->createTable();
        }
        $this->tableReady = true;
    }

    private function createTable(): void
    {
        $schema = $this->db->createSchema();
        $table = $schema->createTable($this->config->getSystemValue('dbtableprefix', 'oc_') . self::TABLE);
        $table->addColumn('notice_hash', 'string', ['length' => 64, 'notnull' => true]);
        $table->addColumn('expires', 'bigint', ['notnull' => true]);
        $table->setPrimaryKey(['notice_hash']);
        try {
            $this->db->migrateToSchema($schema);
        } catch (\Throwable $e) {
            // Another request may have made it first.
            if (!$this->db->tableExists(self::TABLE)) {
                throw $e;
            }
        }
    }
}
