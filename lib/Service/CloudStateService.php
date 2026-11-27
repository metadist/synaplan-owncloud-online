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
use OCP\Http\Client\IClientService;
use OCP\ICacheFactory;
use OCP\ILogger;

/**
 * What Synaplan made of a person's files, asked with that person's
 * credential: the state of up to 500 files of a folder view, one file for
 * the Synaplan tab, and the glyphs Synaplan wants shown instead of the bird.
 */
class CloudStateService
{
    private const TIMEOUT = 15;
    private const GLYPHS_TTL = 300;
    private const GLYPHS_RETRY_TTL = 60;

    public function __construct(
        private IClientService $clientService,
        private SynaplanConfig $synaplanConfig,
        private SynaplanClient $synaplanClient,
        private PlatformLinkService $links,
        private ICacheFactory $cacheFactory,
        private ILogger $logger,
    ) {
    }

    /**
     * Whether files show what Synaplan made of them: where each person uses
     * their own credential, while Synaplan tells the state of cloud files.
     */
    public function available(): bool
    {
        return $this->synaplanConfig->isPerUserAccountsEnabled() && $this->links->tellsFileState();
    }

    /**
     * Whether Synaplan hears from here when a person's files change, move or
     * go: each person reports with their own credential, for this cloud.
     */
    public function followsChanges(): bool
    {
        return $this->synaplanConfig->isPerUserAccountsEnabled()
            && $this->synaplanConfig->getLinkInstanceId() !== ''
            && $this->links->followsChanges();
    }

    /**
     * The state of each file, in the order asked. Not enabled when Synaplan
     * has the state of cloud files turned off or the person has no
     * credential; unreachable when Synaplan did not answer.
     *
     * @param list<array{id: string, version: string}> $items
     * @return array{enabled: bool, unreachable?: bool, items: list<array<mixed>>, watches: list<array<mixed>>}
     */
    public function status(array $items, ?string $credential = null): array
    {
        if ($items === [] || !$this->synaplanConfig->isPerUserAccountsEnabled()) {
            return ['enabled' => false, 'items' => [], 'watches' => []];
        }
        $key = $credential !== null && $credential !== '' ? $credential : $this->synaplanClient->getApiKey();
        if ($key === '') {
            return ['enabled' => false, 'items' => [], 'watches' => []];
        }

        try {
            $decoded = $this->request('POST', '/api/v1/cloud/status', ['items' => array_slice($items, 0, 500)], $key);
        } catch (\Throwable $e) {
            $this->logger->warning('Could not read the state of files in Synaplan: {message}', [
                'app' => Application::APP_ID,
                'message' => $e->getMessage(),
            ]);

            return ['enabled' => true, 'unreachable' => true, 'items' => [], 'watches' => []];
        }

        $status = (int) ($decoded['status_code'] ?? 200);
        if ($status === 404) {
            return ['enabled' => false, 'items' => [], 'watches' => []];
        }
        if ($status >= 400) {
            return ['enabled' => true, 'unreachable' => true, 'items' => [], 'watches' => []];
        }

        return [
            'enabled' => true,
            'items' => is_array($decoded['items'] ?? null) ? array_values(array_filter($decoded['items'], 'is_array')) : [],
            'watches' => is_array($decoded['watches'] ?? null) ? array_values(array_filter($decoded['watches'], 'is_array')) : [],
        ];
    }

    /**
     * One file in Synaplan by its Synaplan id, null when Synaplan does not
     * know it as the person's or did not answer.
     *
     * @return array<mixed>|null
     */
    public function detail(int $synaplanFileId, ?string $credential = null): ?array
    {
        if ($synaplanFileId <= 0 || !$this->synaplanConfig->isPerUserAccountsEnabled()) {
            return null;
        }
        $key = $credential !== null && $credential !== '' ? $credential : $this->synaplanClient->getApiKey();
        if ($key === '') {
            return null;
        }

        try {
            $decoded = $this->request('GET', '/api/v1/cloud/files/' . $synaplanFileId, null, $key);
        } catch (\Throwable) {
            return null;
        }

        return (int) ($decoded['status_code'] ?? 200) < 400 ? $decoded : null;
    }

    /**
     * The glyphs Synaplan wants shown instead of the bird, from its runtime
     * configuration, kept as long as Synaplan's configuration. Without an
     * answer the bird is shown, and Synaplan is asked again after a minute.
     *
     * @return array{bird: string|null, bird_eyes: string|null}
     */
    public function glyphOverrides(): array
    {
        $cache = $this->cacheFactory->create(Application::APP_ID);
        $key = 'glyphs/' . md5($this->synaplanConfig->getBaseUrl());
        $kept = json_decode((string) $cache->get($key), true);
        if (is_array($kept) && array_key_exists('bird', $kept) && array_key_exists('bird_eyes', $kept)) {
            return ['bird' => self::url($kept['bird']), 'bird_eyes' => self::url($kept['bird_eyes'])];
        }

        try {
            $decoded = $this->request('GET', '/api/v1/config/runtime', null, '');
        } catch (\Throwable) {
            $decoded = ['status_code' => 503];
        }
        $answered = (int) ($decoded['status_code'] ?? 200) < 400;
        $cloudFiles = $answered && is_array($decoded['cloud_files'] ?? null) ? $decoded['cloud_files'] : [];
        $glyphs = is_array($cloudFiles['glyphs'] ?? null) ? $cloudFiles['glyphs'] : [];
        $overrides = ['bird' => self::url($glyphs['bird'] ?? null), 'bird_eyes' => self::url($glyphs['bird_eyes'] ?? null)];
        $cache->set($key, (string) json_encode($overrides), $answered ? self::GLYPHS_TTL : self::GLYPHS_RETRY_TTL);

        return $overrides;
    }

    private static function url(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<mixed>
     */
    private function request(string $method, string $path, ?array $body, string $credential): array
    {
        $options = [
            'headers' => $credential !== '' ? SynaplanCredential::headers($credential) : ['Accept' => 'application/json'],
            'timeout' => self::TIMEOUT,
            'http_errors' => false,
        ];
        if ($body !== null) {
            $options['headers']['Content-Type'] = 'application/json';
            $options['body'] = json_encode($body);
        }
        $client = $this->clientService->newClient();
        $url = $this->synaplanConfig->getBaseUrl() . $path;
        $response = $method === 'POST' ? $client->post($url, $options) : $client->get($url, $options);
        $decoded = json_decode((string) $response->getBody(), true);

        return ['status_code' => (int) $response->getStatusCode()] + (is_array($decoded) ? $decoded : []);
    }
}
