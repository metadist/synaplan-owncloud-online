<?php

declare(strict_types=1);

/**
 * Synaplan Integration for owncloud.online
 *
 * @copyright Copyright (c) 2026, Synaplan
 * @license Apache-2.0
 */

namespace OCA\OcoSynaplan\Migration;

use OCA\OcoSynaplan\AppInfo\Application;
use OCA\OcoSynaplan\Service\KeyStorage;
use OCA\OcoSynaplan\Service\SynaplanConfig;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Carries the 1.0 settings over: the per-person switch becomes the mode, and
 * keys stored in plain text are stored encrypted.
 */
class MigrateSettings implements IRepairStep
{
    public function __construct(
        private SynaplanConfig $synaplanConfig,
        private KeyStorage $keys,
        private IDBConnection $db,
    ) {
    }

    public function getName(): string
    {
        return 'Move the Synaplan settings to this version';
    }

    public function run(IOutput $output): void
    {
        $output->info('Synaplan mode: ' . $this->synaplanConfig->migrateMode());

        foreach (['api_key', 'api_key_local'] as $name) {
            $this->keys->getAppKey($name);
        }

        $query = $this->db->getQueryBuilder();
        $query->select('userid', 'configvalue')
            ->from('preferences')
            ->where($query->expr()->eq('appid', $query->createNamedParameter(Application::APP_ID)))
            ->andWhere($query->expr()->eq('configkey', $query->createNamedParameter(KeyStorage::USER_KEY)));
        $result = $query->execute();
        $uids = [];
        while (($row = $result->fetchAssociative()) !== false) {
            if (!str_starts_with((string) $row['configvalue'], KeyStorage::PREFIX)) {
                $uids[] = (string) $row['userid'];
            }
        }
        $result->free();

        foreach ($uids as $uid) {
            $this->keys->getUserKey($uid);
        }
        if ($uids !== []) {
            $output->info('Encrypted the Synaplan keys of ' . count($uids) . ' people');
        }
    }
}
