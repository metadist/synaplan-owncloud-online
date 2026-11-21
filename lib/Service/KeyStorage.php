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
use OCP\ILogger;
use OCP\Security\ICrypto;

/**
 * Every Synaplan credential this app keeps: the administrator's keys, each
 * person's key or tokens, and this server's OAuth client key.
 *
 * ownCloud 11 has no sensitive config values, so values are encrypted with
 * the server secret (ICrypto) and stored as `enc:<ciphertext>`. A value
 * without the prefix is a plaintext one from an earlier release: it is read
 * once and saved again encrypted. A value that no longer decrypts (the
 * server secret changed) is removed, so the person connects again or a new
 * key is minted, instead of every request failing on it.
 */
final class KeyStorage
{
    public const USER_KEY = 'synaplan_user_api_key';
    public const PREFIX = 'enc:';

    public function __construct(
        private IConfig $config,
        private ICrypto $crypto,
        private ILogger $logger,
    ) {
    }

    /**
     * The person's key: '' when none is stored, null when the stored one
     * could not be decrypted (it is removed).
     */
    public function getUserKey(string $uid): ?string
    {
        return $this->getUserSecret($uid, self::USER_KEY);
    }

    public function setUserKey(string $uid, string $key): void
    {
        $this->setUserSecret($uid, self::USER_KEY, $key);
    }

    public function deleteUserKey(string $uid): void
    {
        $this->config->deleteUserValue($uid, Application::APP_ID, self::USER_KEY);
    }

    /**
     * A secret in the person's settings: '' when none is stored, null when it
     * could not be decrypted (it is removed).
     */
    public function getUserSecret(string $uid, string $name): ?string
    {
        $stored = (string) $this->config->getUserValue($uid, Application::APP_ID, $name, '');
        if ($stored === '') {
            return '';
        }
        if (!str_starts_with($stored, self::PREFIX)) {
            $this->setUserSecret($uid, $name, $stored);

            return $stored;
        }
        $plain = $this->decrypt(substr($stored, strlen(self::PREFIX)));
        if ($plain === null) {
            $this->config->deleteUserValue($uid, Application::APP_ID, $name);
            $this->logger->warning('A stored Synaplan credential of {uid} ({name}) could not be decrypted and was removed', [
                'app' => Application::APP_ID,
                'uid' => $uid,
                'name' => $name,
            ]);
        }

        return $plain;
    }

    public function setUserSecret(string $uid, string $name, string $value): void
    {
        if ($value === '') {
            $this->config->deleteUserValue($uid, Application::APP_ID, $name);

            return;
        }
        $this->config->setUserValue($uid, Application::APP_ID, $name, self::PREFIX . $this->crypto->encrypt($value));
    }

    /**
     * An install-wide secret ('api_key', 'api_key_local', the OAuth client
     * key); '' when none is stored or it could not be decrypted.
     */
    public function getAppKey(string $name): string
    {
        $stored = (string) $this->config->getAppValue(Application::APP_ID, $name, '');
        if ($stored === '') {
            return '';
        }
        if (!str_starts_with($stored, self::PREFIX)) {
            $this->setAppKey($name, $stored);

            return $stored;
        }
        $plain = $this->decrypt(substr($stored, strlen(self::PREFIX)));
        if ($plain === null) {
            $this->config->deleteAppValue(Application::APP_ID, $name);
            $this->logger->warning('The stored Synaplan setting {name} could not be decrypted and was removed', [
                'app' => Application::APP_ID,
                'name' => $name,
            ]);

            return '';
        }

        return $plain;
    }

    public function setAppKey(string $name, string $value): void
    {
        if ($value === '') {
            $this->config->deleteAppValue(Application::APP_ID, $name);

            return;
        }
        $this->config->setAppValue(Application::APP_ID, $name, self::PREFIX . $this->crypto->encrypt($value));
    }

    public function deleteAppKey(string $name): void
    {
        $this->config->deleteAppValue(Application::APP_ID, $name);
    }

    /**
     * A value as stored, opened: '' stays '', a plaintext value is returned
     * as it is, null when it does not decrypt.
     */
    public function open(string $stored): ?string
    {
        if ($stored === '' || !str_starts_with($stored, self::PREFIX)) {
            return $stored;
        }

        return $this->decrypt(substr($stored, strlen(self::PREFIX)));
    }

    private function decrypt(string $ciphertext): ?string
    {
        try {
            return (string) $this->crypto->decrypt($ciphertext);
        } catch (\Throwable) {
            return null;
        }
    }
}
