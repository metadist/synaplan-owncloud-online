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
use OCP\AppFramework\Http\JSONResponse;

/**
 * For controllers with a `$logger`: logs the exception and answers with its
 * {@see Failure}. A state the person can fix is logged at info, the rest as
 * an error.
 */
trait RespondsToFailures
{
    /**
     * @param array<string, mixed> $extra keys the frontend reads beside the failure
     */
    private function failed(\Throwable $e, string $action, array $extra = []): JSONResponse
    {
        $failure = Failure::fromThrowable($e);
        $context = [
            'app' => Application::APP_ID,
            'message' => $e->getMessage(),
            'code' => $failure->code,
        ];
        if ($failure->status >= 500) {
            $this->logger->error($action . ': {message} ({code})', $context);
        } else {
            $this->logger->info($action . ': {message} ({code})', $context);
        }

        return $failure->toResponse($extra);
    }
}
