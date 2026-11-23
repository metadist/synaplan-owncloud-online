<?php

declare(strict_types=1);

namespace OCA\OcoSynaplan\Tests\Unit\Support;

use OCP\Http\Client\IClient;
use OCP\Http\Client\IClientService;
use OCP\Http\Client\IResponse;

/**
 * The server's HTTP client, recording each request and answering it with
 * what the test's responder returns: an array (sent as JSON with 200), an
 * IResponse, or a Throwable, which is thrown as the client throws a failed
 * request.
 */
final class RecordingHttp implements IClientService
{
    /**
     * @var list<array{method: string, url: string, path: string, options: array<string, mixed>, body: array<string, mixed>, multipart: bool, form: bool}>
     */
    public array $calls = [];

    /** @var \Closure(string, string, array<string, mixed>): (array<mixed>|IResponse|\Throwable) */
    private \Closure $responder;

    /**
     * @param (callable(string, string, array<string, mixed>): (array<mixed>|IResponse|\Throwable))|null $responder
     *        method, path and options of the request
     */
    public function __construct(?callable $responder = null)
    {
        $this->responder = \Closure::fromCallable($responder ?? static fn (): array => ['success' => true]);
    }

    /**
     * @param callable(string, string, array<string, mixed>): (array<mixed>|IResponse|\Throwable) $responder
     */
    public function answer(callable $responder): void
    {
        $this->responder = \Closure::fromCallable($responder);
    }

    public function newClient()
    {
        $http = $this;

        return new class ($http) implements IClient {
            public function __construct(
                private RecordingHttp $http,
            ) {
            }

            /**
             * @param array<string, mixed> $options
             */
            public function get($uri, array $options = [])
            {
                return $this->http->handle('GET', (string) $uri, $options);
            }

            /**
             * @param array<string, mixed> $options
             */
            public function head($uri, $options = [])
            {
                return $this->http->handle('HEAD', (string) $uri, $options);
            }

            /**
             * @param array<string, mixed> $options
             */
            public function post($uri, array $options = [])
            {
                return $this->http->handle('POST', (string) $uri, $options);
            }

            /**
             * @param array<string, mixed> $options
             */
            public function put($uri, array $options = [])
            {
                return $this->http->handle('PUT', (string) $uri, $options);
            }

            /**
             * @param array<string, mixed> $options
             */
            public function delete($uri, array $options = [])
            {
                return $this->http->handle('DELETE', (string) $uri, $options);
            }

            /**
             * @param array<string, mixed> $options
             */
            public function options($uri, array $options = [])
            {
                return $this->http->handle('OPTIONS', (string) $uri, $options);
            }
        };
    }

    /**
     * @param array<string, mixed> $options
     */
    public function handle(string $method, string $url, array $options): IResponse
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        $multipart = isset($options['multipart']) && is_array($options['multipart']);
        $headers = is_array($options['headers'] ?? null) ? $options['headers'] : [];
        $form = (isset($options['form_params']) && is_array($options['form_params']))
            || stripos((string) ($headers['Content-Type'] ?? ''), 'application/x-www-form-urlencoded') === 0;
        $body = [];
        if ($multipart) {
            foreach ($options['multipart'] as $part) {
                $body[(string) $part['name']] = is_string($part['contents']) ? $part['contents'] : '';
            }
        } elseif (isset($options['form_params']) && is_array($options['form_params'])) {
            $body = $options['form_params'];
        } elseif (isset($options['json']) && is_array($options['json'])) {
            $body = $options['json'];
        } elseif (isset($options['body']) && is_string($options['body'])) {
            if ($form) {
                parse_str($options['body'], $body);
            } else {
                $decoded = json_decode($options['body'], true);
                $body = is_array($decoded) ? $decoded : [];
            }
        }
        $this->calls[] = [
            'method' => $method,
            'url' => $url,
            'path' => $path,
            'options' => $options,
            'body' => $body,
            'multipart' => $multipart,
            'form' => $form,
        ];

        $answer = ($this->responder)($method, $path, $options);
        if ($answer instanceof \Throwable) {
            throw $answer;
        }
        if ($answer instanceof IResponse) {
            return $answer;
        }

        return self::response(200, (string) json_encode($answer));
    }

    /**
     * @param array<string, string> $headers
     */
    public static function response(int $status, string $body, array $headers = []): IResponse
    {
        return new class ($status, $body, $headers) implements IResponse {
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
                foreach ($this->headers as $name => $value) {
                    if (strcasecmp($name, (string) $key) === 0) {
                        return $value;
                    }
                }

                return '';
            }

            /**
             * @return array<string, list<string>>
             */
            public function getHeaders()
            {
                return array_map(static fn (string $value): array => [$value], $this->headers);
            }
        };
    }

    /**
     * The calls to one path, in order.
     *
     * @return list<array{method: string, url: string, path: string, options: array<string, mixed>, body: array<string, mixed>, multipart: bool, form: bool}>
     */
    public function to(string $path): array
    {
        return array_values(array_filter($this->calls, static fn (array $call): bool => $call['path'] === $path));
    }
}
