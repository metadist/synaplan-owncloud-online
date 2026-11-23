<?php

declare(strict_types=1);

/**
 * Synaplan Integration for owncloud.online
 *
 * @copyright Copyright (c) 2026, Synaplan
 * @license Apache-2.0
 */

namespace OCA\OcoSynaplan\Http;

use OCA\OcoSynaplan\Exception\SynaplanHttpException;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;
use OCP\ICertificateManager;
use OCP\IConfig;

/**
 * The server's HTTP clients, which also send PATCH: with cURL, through the
 * server's proxy and trusting the certificates its administrator added.
 */
final class ClientService implements IClientService
{
    public function __construct(
        private IClientService $server,
        private IConfig $config,
        private ?ICertificateManager $certificates,
    ) {
    }

    public function newClient(): PatchingClient
    {
        $client = $this->server->newClient();
        $config = $this->config;
        $certificates = $this->certificates;

        return new class ($client, $config, $certificates) implements PatchingClient {
            public function __construct(
                private \OCP\Http\Client\IClient $client,
                private IConfig $config,
                private ?ICertificateManager $certificates,
            ) {
            }

            /**
             * @param array<string, mixed> $options
             */
            public function get($uri, array $options = [])
            {
                return $this->client->get($uri, $options);
            }

            /**
             * @param array<string, mixed> $options
             */
            public function head($uri, $options = [])
            {
                return $this->client->head($uri, $options);
            }

            /**
             * @param array<string, mixed> $options
             */
            public function post($uri, array $options = [])
            {
                return $this->client->post($uri, $options);
            }

            /**
             * @param array<string, mixed> $options
             */
            public function put($uri, array $options = [])
            {
                return $this->client->put($uri, $options);
            }

            /**
             * @param array<string, mixed> $options
             */
            public function delete($uri, array $options = [])
            {
                return $this->client->delete($uri, $options);
            }

            /**
             * @param array<string, mixed> $options
             */
            public function options($uri, array $options = [])
            {
                return $this->client->options($uri, $options);
            }

            public function patch(string $uri, array $options = []): IResponse
            {
                $headers = [];
                foreach (is_array($options['headers'] ?? null) ? $options['headers'] : [] as $name => $value) {
                    $headers[] = $name . ': ' . (is_scalar($value) ? (string) $value : '');
                }
                $received = [];
                $handle = curl_init($uri);
                curl_setopt_array($handle, [
                    \CURLOPT_CUSTOMREQUEST => 'PATCH',
                    \CURLOPT_POSTFIELDS => is_string($options['body'] ?? null) ? $options['body'] : '',
                    \CURLOPT_HTTPHEADER => $headers,
                    \CURLOPT_RETURNTRANSFER => true,
                    \CURLOPT_TIMEOUT => is_int($options['timeout'] ?? null) ? $options['timeout'] : 30,
                    \CURLOPT_CONNECTTIMEOUT => 15,
                    \CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$received): int {
                        $parts = explode(':', $line, 2);
                        if (count($parts) === 2) {
                            $received[strtolower(trim($parts[0]))] = trim($parts[1]);
                        }

                        return strlen($line);
                    },
                ] + $this->transport());
                $body = curl_exec($handle);
                $status = (int) curl_getinfo($handle, \CURLINFO_RESPONSE_CODE);
                $error = curl_error($handle);
                curl_close($handle);
                if (!is_string($body)) {
                    throw new \RuntimeException('cURL error: ' . $error);
                }
                if ($status >= 400) {
                    throw new SynaplanHttpException($status, $body);
                }

                return new class ($status, $body, $received) implements IResponse {
                    /**
                     * @param array<string, string> $headers
                     */
                    public function __construct(
                        private int $status,
                        private string $body,
                        private array $headers,
                    ) {
                    }

                    /**
                     * @return string
                     */
                    public function getBody()
                    {
                        return $this->body;
                    }

                    public function getStatusCode()
                    {
                        return $this->status;
                    }

                    /**
                     * @param string $key
                     */
                    public function getHeader($key)
                    {
                        return $this->headers[strtolower((string) $key)] ?? '';
                    }

                    /**
                     * @return array<string, string>
                     */
                    public function getHeaders()
                    {
                        return $this->headers;
                    }
                };
            }

            /**
             * The proxy and certificates the server's own client uses.
             *
             * @return array<int, mixed>
             */
            private function transport(): array
            {
                $options = [];
                $proxy = $this->config->getSystemValue('proxy', '');
                if (is_string($proxy) && $proxy !== '') {
                    $options[\CURLOPT_PROXY] = $proxy;
                    $credentials = $this->config->getSystemValue('proxyuserpwd', '');
                    if (is_string($credentials) && $credentials !== '') {
                        $options[\CURLOPT_PROXYUSERPWD] = $credentials;
                    }
                    $ignore = $this->config->getSystemValue('proxy_ignore', []);
                    if (is_array($ignore) && $ignore !== []) {
                        $options[\CURLOPT_NOPROXY] = implode(',', array_filter($ignore, 'is_string'));
                    }
                }
                try {
                    if ($this->certificates !== null && $this->certificates->listCertificates() !== []) {
                        $options[\CURLOPT_CAINFO] = $this->certificates->getAbsoluteBundlePath();
                    }
                } catch (\Throwable) {
                    // The system's certificates stay in use.
                }

                return $options;
            }
        };
    }
}
