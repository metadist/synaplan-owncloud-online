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
use OCA\OcoSynaplan\Service\CopyDelivery;
use OCA\OcoSynaplan\Service\FolderRead;
use OCA\OcoSynaplan\Service\NotificationSignature;
use OCA\OcoSynaplan\Service\NotifyKeyCache;
use OCA\OcoSynaplan\Service\PlatformLinkService;
use OCA\OcoSynaplan\Service\SeenNotificationStore;
use OCA\OcoSynaplan\Service\SynaplanConfig;
use OCA\OcoSynaplan\Service\UserAccountService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICacheFactory;
use OCP\ILogger;
use OCP\IRequest;

/**
 * The one address Synaplan calls. Nothing is fetched until the notification
 * verifies: signature, this instance, five minutes, and an id not seen in
 * the last ten minutes.
 */
class InboundController extends Controller
{
    /** Requests per minute from one address; ownCloud 11 has no rate-limit annotation. */
    private const PER_MINUTE = 120;

    public function __construct(
        IRequest $request,
        private SynaplanConfig $synaplanConfig,
        private NotifyKeyCache $keys,
        private SeenNotificationStore $seen,
        private CopyDelivery $copies,
        private FolderRead $reads,
        private PlatformLinkService $links,
        private UserAccountService $accounts,
        private ICacheFactory $cacheFactory,
        private ITimeFactory $time,
        private ILogger $logger,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * @PublicPage
     * @NoCSRFRequired
     */
    public function receive(): JSONResponse
    {
        if ($this->overLimit()) {
            return new JSONResponse(['error' => 'rate_limited'], Http::STATUS_TOO_MANY_REQUESTS);
        }
        $token = trim($this->body());
        $reason = $this->rejectReason($token);
        if ($reason !== null) {
            $this->noteReject();

            return new JSONResponse(['error' => $reason], Http::STATUS_UNAUTHORIZED);
        }

        $notification = NotificationSignature::claims($token);
        $type = (string) $notification['type'];
        $id = (string) $notification['id'];
        // A copy or a folder read is done for the person the signed notice
        // names, with that person's own credential. Without one nobody here
        // may act, and Synaplan is told so before anything is claimed.
        $person = is_string($notification['sub'] ?? null) ? $notification['sub'] : '';
        if (in_array($type, ['delivery', 'pickup'], true) && !$this->links->canActFor($person)) {
            return new JSONResponse(['error' => 'not_linked'], Http::STATUS_CONFLICT);
        }

        return match ($type) {
            'delivery' => $this->delivered($this->copies->handle($id, $person)),
            'pickup' => $this->read($id, $person),
            'identity_revoked' => $this->revoked($person),
            default => new JSONResponse([], Http::STATUS_NO_CONTENT),
        };
    }

    /**
     * The request body as sent: ownCloud 11's request object has no reader
     * for a body that is neither form data nor JSON.
     */
    protected function body(): string
    {
        return (string) file_get_contents('php://input');
    }

    private function rejectReason(string $token): ?string
    {
        $reason = $this->verify($token);
        if ($reason === NotificationSignature::BAD_SIGNATURE && $this->keys->refreshForUnknownKey($this->time->getTime())) {
            $reason = $this->verify($token);
        }
        if ($reason !== NotificationSignature::OK) {
            return $reason;
        }
        $notification = NotificationSignature::claims($token);
        $type = $notification['type'] ?? null;
        $id = $notification['id'] ?? null;
        if (!is_string($type) || $type === '' || !is_scalar($id)) {
            return NotificationSignature::BAD_SIGNATURE;
        }
        if ($this->seen->alreadySeen($type, (string) $id)) {
            return NotificationSignature::REPLAY;
        }

        return null;
    }

    private function verify(string $token): string
    {
        return NotificationSignature::verify(
            $token,
            $this->keys->issuer(),
            $this->synaplanConfig->getLinkInstanceId(),
            $this->keys->keys(),
            $this->time->getTime(),
        );
    }

    /**
     * @param array{status: int, body: array<string, mixed>} $result
     */
    private function delivered(array $result): JSONResponse
    {
        $status = $result['status'] === Http::STATUS_ACCEPTED
            ? Http::STATUS_ACCEPTED
            : Http::STATUS_OK;

        return new JSONResponse($result['body'], $status);
    }

    /**
     * The read answers Synaplan with its result call; this answer only says
     * the notice was taken.
     */
    private function read(string $id, string $person): JSONResponse
    {
        $this->reads->handle($id, $person);

        return new JSONResponse(['state' => 'accepted'], Http::STATUS_ACCEPTED);
    }

    private function revoked(string $person): JSONResponse
    {
        $this->accounts->linkEnded($person);

        return new JSONResponse([], Http::STATUS_NO_CONTENT);
    }

    /**
     * Counted in the app's memory cache, so the limit holds where the
     * server has one configured (`memcache.local`).
     */
    private function overLimit(): bool
    {
        $cache = $this->cacheFactory->create(Application::APP_ID);
        $key = 'inbound_' . hash('sha256', $this->request->getRemoteAddress()) . '_' . intdiv($this->time->getTime(), 60);
        $count = (int) $cache->get($key) + 1;
        $cache->set($key, (string) $count, 120);

        return $count > self::PER_MINUTE;
    }

    /**
     * One line per minute, with how many notifications were refused, instead
     * of one line per forged request.
     */
    private function noteReject(): void
    {
        $cache = $this->cacheFactory->create(Application::APP_ID);
        $minute = intdiv($this->time->getTime(), 60);
        $count = (int) $cache->get('reject_' . $minute);
        $cache->set('reject_' . $minute, (string) ($count + 1), 120);
        if ($count !== 0) {
            return;
        }
        $previous = (int) $cache->get('reject_' . ($minute - 1));
        if ($previous > 0) {
            $this->logger->info('Refused {count} Synaplan notifications in the last minute', [
                'app' => Application::APP_ID,
                'count' => $previous,
            ]);
        }
    }
}
