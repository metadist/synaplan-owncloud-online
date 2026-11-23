<?php

declare(strict_types=1);

/**
 * Synaplan Integration for owncloud.online
 *
 * @copyright Copyright (c) 2026, Synaplan
 * @license Apache-2.0
 */

namespace OCA\OcoSynaplan\Controller;

use OCA\OcoSynaplan\AppInfo\Application;
use OCA\OcoSynaplan\Exception\PlatformLinkException;
use OCA\OcoSynaplan\Service\KeyStorage;
use OCA\OcoSynaplan\Service\NotifyKeyCache;
use OCA\OcoSynaplan\Service\PlatformLinkService;
use OCA\OcoSynaplan\Service\ReadScope;
use OCA\OcoSynaplan\Service\SynaplanClient;
use OCA\OcoSynaplan\Service\SynaplanConfig;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IConfig;
use OCP\ILogger;
use OCP\IRequest;

/**
 * API controller for admin settings.
 *
 * Handles reading/writing Synaplan connection configuration, the way people
 * use Synaplan, the registration of this server with Synaplan, and testing
 * the connection to the Synaplan API.
 *
 * All methods are admin-only (no @NoAdminRequired).
 */
class SettingsController extends Controller
{
    public function __construct(
        IRequest $request,
        private IConfig $config,
        private SynaplanClient $synaplanClient,
        private SynaplanConfig $synaplanConfig,
        private KeyStorage $keys,
        private PlatformLinkService $platformLinks,
        private NotifyKeyCache $notifyKeys,
        private ILogger $logger,
        private ReadScope $readScope,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * Return current settings (API keys are masked).
     */
    public function getSettings(): JSONResponse
    {
        $apiKey = $this->keys->getAppKey('api_key');
        $apiKeyLocal = $this->keys->getAppKey('api_key_local');

        return new JSONResponse([
            'active_env' => $this->synaplanClient->getActiveEnv(),
            'synaplan_url' => $this->config->getAppValue(Application::APP_ID, 'synaplan_url', 'http://localhost:8000'),
            'api_key_set' => $apiKey !== '',
            'api_key_masked' => $apiKey !== '' ? $this->maskApiKey($apiKey) : '',
            'synaplan_url_local' => $this->config->getAppValue(Application::APP_ID, 'synaplan_url_local', 'http://localhost:8000'),
            'synaplan_public_url' => $this->config->getAppValue(Application::APP_ID, 'synaplan_public_url', ''),
            'synaplan_public_url_local' => $this->config->getAppValue(Application::APP_ID, 'synaplan_public_url_local', ''),
            'api_key_local_set' => $apiKeyLocal !== '',
            'api_key_local_masked' => $apiKeyLocal !== '' ? $this->maskApiKey($apiKeyLocal) : '',
            'default_language' => $this->config->getAppValue(Application::APP_ID, 'default_language', 'en'),
            'use_interface_language' => $this->config->getAppValue(Application::APP_ID, 'use_interface_language', '1') === '1',
            'enable_memories' => $this->synaplanConfig->isMemoriesEnabled(),
            'per_user_accounts' => $this->synaplanConfig->isPerUserAccountsEnabled(),
            'mode' => $this->synaplanConfig->getMode(),
            'link_auto_provision' => $this->config->getAppValue(Application::APP_ID, 'link_auto_provision', '0') === '1',
            'link_registered' => $this->synaplanConfig->isRegistered(),
            'link_instance_id' => $this->synaplanConfig->getLinkInstanceId(),
            'link_instance' => $this->registrationStatus(),
            'delivery_url' => (string) $this->config->getAppValue(Application::APP_ID, 'delivery_url', ''),
            'delivery_url_default' => $this->platformLinks->defaultDeliveryUrl(),
            'read_scope_ceiling' => $this->readScope->ceiling(),
            'read_folder_cap' => $this->readScope->cap(),
        ]);
    }

    /**
     * Persist settings.
     *
     * Named parameters work when the framework extracts them; falls back to raw
     * JSON body parsing for PUT requests.
     */
    public function saveSettings(
        string $synaplan_url = '',
        string $api_key = '',
        string $synaplan_url_local = '',
        string $api_key_local = '',
        ?string $active_env = null,
        ?string $default_language = null,
        ?bool $use_interface_language = null,
        ?bool $enable_memories = null,
        ?bool $per_user_accounts = null,
        ?string $mode = null,
        ?bool $link_auto_provision = null,
        ?string $synaplan_public_url = null,
        ?string $synaplan_public_url_local = null,
        ?string $delivery_url = null,
        ?string $read_scope_ceiling = null,
        ?int $read_folder_cap = null,
    ): JSONResponse {
        // Always re-read the raw body so the boolean flags also surface for PUT.
        $body = file_get_contents('php://input');
        if ($body !== false && $body !== '') {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                if ($synaplan_url === '' && isset($decoded['synaplan_url'])) {
                    $synaplan_url = trim((string) $decoded['synaplan_url']);
                }
                if ($api_key === '' && isset($decoded['api_key'])) {
                    $api_key = trim((string) $decoded['api_key']);
                }
                if ($synaplan_url_local === '' && isset($decoded['synaplan_url_local'])) {
                    $synaplan_url_local = trim((string) $decoded['synaplan_url_local']);
                }
                if ($api_key_local === '' && isset($decoded['api_key_local'])) {
                    $api_key_local = trim((string) $decoded['api_key_local']);
                }
                if ($active_env === null && isset($decoded['active_env'])) {
                    $active_env = trim((string) $decoded['active_env']);
                }
                if ($default_language === null && isset($decoded['default_language'])) {
                    $default_language = trim((string) $decoded['default_language']);
                }
                if ($use_interface_language === null && array_key_exists('use_interface_language', $decoded)) {
                    $use_interface_language = (bool) $decoded['use_interface_language'];
                }
                if ($enable_memories === null && array_key_exists('enable_memories', $decoded)) {
                    $enable_memories = (bool) $decoded['enable_memories'];
                }
                if ($per_user_accounts === null && array_key_exists('per_user_accounts', $decoded)) {
                    $per_user_accounts = (bool) $decoded['per_user_accounts'];
                }
                if ($mode === null && isset($decoded['mode'])) {
                    $mode = trim((string) $decoded['mode']);
                }
                if ($link_auto_provision === null && array_key_exists('link_auto_provision', $decoded)) {
                    $link_auto_provision = (bool) $decoded['link_auto_provision'];
                }
                if ($synaplan_public_url === null && array_key_exists('synaplan_public_url', $decoded)) {
                    $synaplan_public_url = trim((string) $decoded['synaplan_public_url']);
                }
                if ($synaplan_public_url_local === null && array_key_exists('synaplan_public_url_local', $decoded)) {
                    $synaplan_public_url_local = trim((string) $decoded['synaplan_public_url_local']);
                }
                if ($delivery_url === null && array_key_exists('delivery_url', $decoded)) {
                    $delivery_url = trim((string) $decoded['delivery_url']);
                }
                if ($read_scope_ceiling === null && isset($decoded['read_scope_ceiling'])) {
                    $read_scope_ceiling = trim((string) $decoded['read_scope_ceiling']);
                }
                if ($read_folder_cap === null && isset($decoded['read_folder_cap'])) {
                    $read_folder_cap = (int) $decoded['read_folder_cap'];
                }
            }
        }

        if ($synaplan_url !== '') {
            $this->config->setAppValue(Application::APP_ID, 'synaplan_url', rtrim($synaplan_url, '/'));
        }

        if ($api_key !== '') {
            $this->keys->setAppKey('api_key', $api_key);
        }

        if ($synaplan_url_local !== '') {
            $this->config->setAppValue(Application::APP_ID, 'synaplan_url_local', rtrim($synaplan_url_local, '/'));
        }

        if ($api_key_local !== '') {
            $this->keys->setAppKey('api_key_local', $api_key_local);
        }

        if ($synaplan_public_url !== null) {
            $this->config->setAppValue(
                Application::APP_ID,
                'synaplan_public_url',
                $synaplan_public_url === '' ? '' : rtrim($synaplan_public_url, '/')
            );
        }

        if ($synaplan_public_url_local !== null) {
            $this->config->setAppValue(
                Application::APP_ID,
                'synaplan_public_url_local',
                $synaplan_public_url_local === '' ? '' : rtrim($synaplan_public_url_local, '/')
            );
        }

        if ($active_env !== null && in_array($active_env, ['live', 'local'], true)) {
            $this->config->setAppValue(Application::APP_ID, 'active_env', $active_env);
        }

        if ($default_language !== null && $default_language !== '') {
            $this->config->setAppValue(
                Application::APP_ID,
                'default_language',
                strtolower(substr($default_language, 0, 2))
            );
        }

        if ($use_interface_language !== null) {
            $this->config->setAppValue(Application::APP_ID, 'use_interface_language', $use_interface_language ? '1' : '0');
        }

        if ($enable_memories !== null) {
            $this->config->setAppValue(Application::APP_ID, 'enable_memories', $enable_memories ? '1' : '0');
        }

        if ($mode !== null && $mode !== '') {
            $this->synaplanConfig->setMode($mode);
        } elseif ($per_user_accounts !== null) {
            $this->synaplanConfig->setMode(
                $per_user_accounts ? SynaplanConfig::MODE_PROVISION : SynaplanConfig::MODE_SHARED
            );
        }

        if ($link_auto_provision !== null) {
            $this->config->setAppValue(Application::APP_ID, 'link_auto_provision', $link_auto_provision ? '1' : '0');
        }

        if ($read_scope_ceiling !== null) {
            $this->readScope->setCeiling($read_scope_ceiling);
        }

        if ($read_folder_cap !== null) {
            $this->config->setAppValue(Application::APP_ID, ReadScope::CAP, (string) max(1, min(500, $read_folder_cap)));
        }

        // An empty address means this server's own inbound route.
        $deliveryError = '';
        if ($delivery_url !== null) {
            if ($delivery_url === '' || self::isHttpUrl($delivery_url)) {
                $this->config->setAppValue(Application::APP_ID, 'delivery_url', $delivery_url);
            } else {
                $deliveryError = 'invalid';
            }
        }

        // A registered server tells Synaplan what it can do now. A failure
        // here does not undo the save.
        $announceError = false;
        if ($this->synaplanConfig->isRegistered()) {
            try {
                $this->platformLinks->announce();
                $this->notifyKeys->refresh();
            } catch (\Throwable $e) {
                if ($e instanceof PlatformLinkException && $e->getErrorCode() === PlatformLinkException::CODE_DELIVERY_REFUSED) {
                    $deliveryError = $deliveryError !== '' ? $deliveryError : 'refused';
                } else {
                    $announceError = true;
                }
                $this->logger->warning('Could not update the Synaplan registration: {message}', [
                    'app' => Application::APP_ID,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return new JSONResponse([
            'success' => true,
            'announce_error' => $announceError,
            'delivery_error' => $deliveryError,
        ]);
    }

    private static function isHttpUrl(string $url): bool
    {
        $parts = parse_url($url);

        return is_array($parts)
            && in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            && (string) ($parts['host'] ?? '') !== '';
    }

    /**
     * Register this ownCloud.online with Synaplan.
     */
    public function registerInstance(): JSONResponse
    {
        try {
            $this->notifyKeys->refreshTrust();
            $result = $this->platformLinks->register(trim((string) $this->request->getParam('registration_token', '')));
            // Synaplan can send its first notification right after registering.
            $this->notifyKeys->refresh();

            return new JSONResponse(['success' => true] + $result);
        } catch (PlatformLinkException $e) {
            return new JSONResponse([
                'success' => false,
                'error' => $e->getMessage(),
                'code' => $e->getErrorCode(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Registering with Synaplan failed: {message}', [
                'app' => Application::APP_ID,
                'message' => $e->getMessage(),
            ]);

            return new JSONResponse([
                'success' => false,
                'error' => 'Registering with Synaplan failed.',
            ]);
        }
    }

    /**
     * Leave Synaplan: the registration ends there, every person's grant with
     * it, and the client key goes here.
     */
    public function forgetInstance(): JSONResponse
    {
        $this->platformLinks->forgetRegistration();

        return new JSONResponse(['success' => true]);
    }

    public function instanceStatus(): JSONResponse
    {
        return new JSONResponse([
            'success' => true,
            'instance' => $this->registrationStatus(),
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function registrationStatus(): ?array
    {
        try {
            return $this->platformLinks->registrationStatus();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Test connection to Synaplan API.
     *
     * Always returns HTTP 200 — the endpoint itself succeeds,
     * only the external health check may fail.
     */
    public function testConnection(): JSONResponse
    {
        try {
            $result = $this->synaplanClient->healthCheck();

            return new JSONResponse([
                'success' => true,
                'status' => $result['status'] ?? 'unknown',
                'providers' => $result['providers'] ?? [],
            ]);
        } catch (\Exception $e) {
            return new JSONResponse([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Mask an API key for display: show first 6 and last 4 chars.
     */
    private function maskApiKey(string $key): string
    {
        if (strlen($key) <= 10) {
            return str_repeat('•', strlen($key));
        }

        return substr($key, 0, 6) . str_repeat('•', strlen($key) - 10) . substr($key, -4);
    }
}
