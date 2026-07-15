<?php

declare(strict_types=1);

/**
 * Synaplan Integration for owncloud.online
 *
 * @copyright Copyright (c) 2026, Synaplan
 * @license Apache-2.0
 */

namespace OCA\OcoSynaplan\Http;

use OCA\OcoSynaplan\AppInfo\Application;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\ICallbackResponse;
use OCP\AppFramework\Http\IOutput;
use OCP\AppFramework\Http\Response;
use OCP\ILogger;

/**
 * Streams Synaplan's OpenAI-compatible SSE chat response straight to the
 * browser, chunk by chunk, using a direct cURL transfer.
 *
 * Why cURL instead of the ownCloud HTTP client: with the buffered client the
 * upstream body was only handed over once complete, so the answer arrived all
 * at once. cURL's write callback hands us each network chunk the instant it
 * arrives, and we echo + flush it immediately, defeating PHP/ownCloud output
 * buffering so the answer truly streams token by token.
 */
class SseProxyResponse extends Response implements ICallbackResponse
{
    /**
     * @param string $url     Upstream chat-completions endpoint
     * @param string $apiKey  Synaplan API key
     * @param string $payload JSON request body (messages + stream:true)
     */
    public function __construct(
        private string $url,
        private string $apiKey,
        private string $payload,
        private ILogger $logger,
    ) {
        // ownCloud 10's OCP\AppFramework\Http\Response has no constructor, so we
        // must NOT call parent::__construct() here (it fatals "Cannot call
        // constructor"). Property defaults on the base class are already set.
        $this->setStatus(Http::STATUS_OK);
        $this->addHeader('Content-Type', 'text/event-stream; charset=utf-8');
        $this->addHeader('Cache-Control', 'no-cache, no-store, must-revalidate');
        $this->addHeader('Connection', 'keep-alive');
        // Tell intermediary proxies (nginx ingress / FrankenPHP) not to buffer.
        $this->addHeader('X-Accel-Buffering', 'no');
    }

    public function callback(IOutput $output): void
    {
        // Defeat every layer of buffering between us and the browser.
        @ini_set('zlib.output_compression', '0');
        @ini_set('output_buffering', '0');
        if (\function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
            @apache_setenv('dont-vary', '1');
        }
        while (ob_get_level() > 0) {
            ob_end_flush();
        }
        ob_implicit_flush(true);

        // Prime the connection so proxies with a minimum-bytes flush threshold
        // release the stream straight away (SSE comment line, ignored by clients).
        echo ': ' . str_repeat(' ', 2048) . "\n\n";
        $this->flush();

        $ch = curl_init($this->url);
        curl_setopt_array($ch, [
            \CURLOPT_POST => true,
            \CURLOPT_POSTFIELDS => $this->payload,
            \CURLOPT_HTTPHEADER => [
                'X-API-Key: ' . $this->apiKey,
                'Accept: text/event-stream',
                'Content-Type: application/json',
                // Force plain-text SSE — a gzipped body cannot be relayed raw.
                'Accept-Encoding: identity',
            ],
            \CURLOPT_RETURNTRANSFER => false,
            \CURLOPT_TIMEOUT => 300,
            \CURLOPT_CONNECTTIMEOUT => 15,
            \CURLOPT_BUFFERSIZE => 256,
            \CURLOPT_WRITEFUNCTION => function ($handle, string $data): int {
                if (connection_aborted()) {
                    return 0;
                }
                echo $data;
                $this->flush();

                return \strlen($data);
            },
        ]);

        if (curl_exec($ch) === false && !connection_aborted()) {
            $error = curl_error($ch);
            $this->logger->error('SSE chat proxy failed: {message}', [
                'app' => Application::APP_ID,
                'message' => $error,
            ]);
            echo 'data: ' . json_encode([
                'error' => ['message' => $error !== '' ? $error : 'Stream connection failed'],
            ]) . "\n\n";
            echo "data: [DONE]\n\n";
            $this->flush();
        }

        curl_close($ch);
    }

    private function flush(): void
    {
        if (ob_get_level() > 0) {
            @ob_flush();
        }
        flush();
    }
}
