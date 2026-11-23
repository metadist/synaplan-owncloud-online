<?php

declare(strict_types=1);

/**
 * Synaplan Integration for owncloud.online
 *
 * @copyright Copyright (c) 2026, Synaplan
 * @license Apache-2.0
 */

namespace OCA\OcoSynaplan\Service;

use OCA\OcoSynaplan\AppInfo\Application;
use OCP\IConfig;

/**
 * Central accessor for the Synaplan integration configuration.
 *
 * Extracted from SynaplanClient so that both the HTTP client AND the per-user
 * account service can read the base URL / admin key without a circular
 * dependency between them.
 *
 * How people use Synaplan is one of three modes: everyone shares one account
 * (`shared`), this server creates an account per person with the admin key
 * (`provision`), or each person connects an account they already have
 * (`link`). Organisation login does not exist on ownCloud.online.
 */
class SynaplanConfig
{
    public const MODE_SHARED = 'shared';
    public const MODE_PROVISION = 'provision';
    public const MODE_LINK = 'link';
    public const MODES = [self::MODE_SHARED, self::MODE_PROVISION, self::MODE_LINK];

    /** The boolean of release 1.0.0 that the mode replaced. */
    public const LEGACY_PER_USER = 'per_user_accounts';

    /** Off by default, set only with occ config:app:set (README, Development). */
    public const DEV_HOLD_INBOUND = 'dev_hold_inbound';
    public const DEV_MUTE_EVENTS = 'dev_mute_events';
    public const DEV_ALLOW_SELF_WRITE = 'dev_allow_self_write';

    public function __construct(
        private IConfig $config,
        private KeyStorage $keys,
    ) {
    }

    /**
     * Active backend environment: 'live' (production) or 'local' (development).
     */
    public function getActiveEnv(): string
    {
        return $this->config->getAppValue(Application::APP_ID, 'active_env', 'live') === 'local'
            ? 'local'
            : 'live';
    }

    public function getBaseUrl(): string
    {
        if ($this->getActiveEnv() === 'local') {
            return rtrim(
                $this->config->getAppValue(Application::APP_ID, 'synaplan_url_local', 'http://localhost:8000'),
                '/'
            );
        }

        return rtrim(
            $this->config->getAppValue(Application::APP_ID, 'synaplan_url', 'http://localhost:8000'),
            '/'
        );
    }

    /**
     * Origin the person's browser opens (Synaplan's consent page, links into
     * Synaplan). Falls back to getBaseUrl() when unset; needed when this server
     * reaches Synaplan on an address the browser cannot resolve.
     */
    public function getPublicBaseUrl(): string
    {
        $key = $this->getActiveEnv() === 'local'
            ? 'synaplan_public_url_local'
            : 'synaplan_public_url';
        $public = rtrim((string) $this->config->getAppValue(Application::APP_ID, $key, ''), '/');

        return $public !== '' ? $public : $this->getBaseUrl();
    }

    /**
     * The install-wide key configured by the admin. In the per-user modes this
     * is an ADMIN key (used to create accounts and mint their keys); in shared
     * mode it is the key every request uses.
     */
    public function getAdminApiKey(): string
    {
        return $this->keys->getAppKey($this->getActiveEnv() === 'local' ? 'api_key_local' : 'api_key');
    }

    /**
     * The stored mode. An install upgraded from 1.0.0 has only the boolean
     * `per_user_accounts`: it becomes `provision` (true) or `shared`, once.
     */
    public function getMode(): string
    {
        $mode = (string) $this->config->getAppValue(Application::APP_ID, 'mode', '');
        if (in_array($mode, self::MODES, true)) {
            return $mode;
        }

        return $this->migrateMode();
    }

    /**
     * Turn the 1.0.0 boolean into the mode and drop the boolean. Called by
     * the repair step on upgrade and by getMode() when it finds no mode.
     */
    public function migrateMode(): string
    {
        $mode = (string) $this->config->getAppValue(Application::APP_ID, 'mode', '');
        if (!in_array($mode, self::MODES, true)) {
            $legacy = (string) $this->config->getAppValue(Application::APP_ID, self::LEGACY_PER_USER, '0');
            $mode = $legacy === '1' ? self::MODE_PROVISION : self::MODE_SHARED;
            $this->config->setAppValue(Application::APP_ID, 'mode', $mode);
        }
        $this->config->deleteAppValue(Application::APP_ID, self::LEGACY_PER_USER);

        return $mode;
    }

    public function setMode(string $mode): bool
    {
        if (!in_array($mode, self::MODES, true)) {
            return false;
        }
        $this->config->setAppValue(Application::APP_ID, 'mode', $mode);
        $this->config->deleteAppValue(Application::APP_ID, self::LEGACY_PER_USER);

        return true;
    }

    /**
     * Each person acts as their own Synaplan account (provision or link).
     */
    public function isPerUserAccountsEnabled(): bool
    {
        return $this->getMode() !== self::MODE_SHARED;
    }

    public function isLinkMode(): bool
    {
        return $this->getMode() === self::MODE_LINK;
    }

    /**
     * This server registered itself with Synaplan as an OAuth client and can
     * still sign as it: a key that no longer decrypts is no registration.
     */
    public function isRegistered(): bool
    {
        return $this->getLinkInstanceId() !== ''
            && $this->config->getAppValue(Application::APP_ID, 'oauth_kid', '') !== ''
            && $this->keys->getAppKey('oauth_private_key') !== '';
    }

    /**
     * People can connect: link mode and a registration.
     */
    public function isLinkAvailable(): bool
    {
        return $this->isLinkMode() && $this->isRegistered();
    }

    /**
     * Link mode offers "Create one for me" to people without an account. It
     * needs the admin key.
     */
    public function isAutoProvisionEnabled(): bool
    {
        return $this->isLinkMode()
            && $this->config->getAppValue(Application::APP_ID, 'link_auto_provision', '0') === '1'
            && $this->getAdminApiKey() !== '';
    }

    /**
     * The OAuth client id Synaplan gave this server; '' before registering.
     */
    public function getLinkInstanceId(): string
    {
        return (string) $this->config->getAppValue(Application::APP_ID, 'link_instance_id', '');
    }

    public function isMemoriesEnabled(): bool
    {
        return $this->config->getAppValue(Application::APP_ID, 'enable_memories', '1') === '1';
    }

    /**
     * One of the three test switches of the plan (`08` §0.8). Never shown in
     * the UI; harmless when off.
     */
    public function isDevSwitchOn(string $name): bool
    {
        return in_array($name, [self::DEV_HOLD_INBOUND, self::DEV_MUTE_EVENTS, self::DEV_ALLOW_SELF_WRITE], true)
            && $this->config->getAppValue(Application::APP_ID, $name, '0') === '1';
    }

    /**
     * Stable identifier for THIS ownCloud.online instance — used to namespace the
     * external_id sent to Synaplan so two ownCloud.online installs pointing at the
     * same Synaplan never collide.
     */
    public function getInstanceId(): string
    {
        return (string) $this->config->getSystemValue('instanceid', 'owncloud');
    }
}
