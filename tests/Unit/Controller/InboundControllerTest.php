<?php

declare(strict_types=1);

namespace OCA\OcoSynaplan\Tests\Unit\Controller;

use OCA\OcoSynaplan\Controller\InboundController;
use OCA\OcoSynaplan\Service\CopyDelivery;
use OCA\OcoSynaplan\Service\NotificationSignature;
use OCA\OcoSynaplan\Service\NotifyKeyCache;
use OCA\OcoSynaplan\Service\PlatformLinkService;
use OCA\OcoSynaplan\Service\SeenNotificationStore;
use OCA\OcoSynaplan\Service\SynaplanConfig;
use OCA\OcoSynaplan\Service\UserAccountService;
use OCA\OcoSynaplan\Tests\Unit\Support\MemoryCache;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ILogger;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class InboundControllerTest extends TestCase
{
    private const NOW = 1700000000;
    private const ISSUER = 'https://synaplan.example';

    private NotifyKeyCache&MockObject $keys;
    private CopyDelivery&MockObject $copies;
    private PlatformLinkService&MockObject $links;
    private UserAccountService&MockObject $accounts;
    private MemoryCache $cache;
    private MemorySeenNotifications $seen;
    private string $secret;
    private string $remote = '203.0.113.7';

    /** @var array{issuer: string, receiver_instance_id: string, public_key: string, now: int, vectors: list<array{name: string, expect: string, token: string}>} */
    private array $fixture;

    protected function setUp(): void
    {
        $fixture = json_decode((string) file_get_contents(__DIR__ . '/../../fixtures/synaplan-contract/notify-vectors.json'), true);
        self::assertIsArray($fixture);
        $this->fixture = $fixture;

        $pair = sodium_crypto_sign_keypair();
        $this->secret = sodium_crypto_sign_secretkey($pair);
        $this->keys = $this->keysOf([['publicKey' => 'ed25519:' . self::b64(sodium_crypto_sign_publickey($pair)), 'kid' => 'test']]);
        $this->copies = $this->createMock(CopyDelivery::class);
        $this->links = $this->createMock(PlatformLinkService::class);
        $this->links->method('canActFor')->willReturnCallback(static fn (string $uid): bool => $uid === 'ada');
        $this->accounts = $this->createMock(UserAccountService::class);
        $this->cache = new MemoryCache();
        $this->seen = new MemorySeenNotifications();
    }

    public function testACopyIsClaimedOnceForThePersonTheNoticeNames(): void
    {
        $this->copies->expects($this->once())->method('handle')->with('9', 'ada')->willReturn([
            'status' => 200,
            'body' => ['status' => 'delivered', 'remote_path' => '/Synaplan/Documents/plan.docx'],
        ]);

        $response = $this->receive($this->signed($this->notice('delivery', '9', 'ada')));

        self::assertSame(Http::STATUS_OK, $response->getStatus());
        self::assertSame('delivered', $response->getData()['status']);
    }

    public function testACopyStillUnderWayIsAccepted(): void
    {
        $this->copies->method('handle')->willReturn(['status' => 202, 'body' => ['state' => 'pending']]);

        $response = $this->receive($this->signed($this->notice('delivery', '9', 'ada')));

        self::assertSame(Http::STATUS_ACCEPTED, $response->getStatus());
        self::assertSame(['state' => 'pending'], $response->getData());
    }

    public function testAReplayIsRefusedAndNotClaimedAgain(): void
    {
        $this->copies->expects($this->once())->method('handle')->willReturn(['status' => 200, 'body' => ['status' => 'delivered']]);
        $body = $this->signed($this->notice('delivery', '9', 'ada'));

        $this->receive($body);
        $again = $this->receive($body);

        self::assertSame(Http::STATUS_UNAUTHORIZED, $again->getStatus());
        self::assertSame(NotificationSignature::REPLAY, $again->getData()['error']);
    }

    public function testACopyForSomeoneWithoutACredentialIsNotClaimed(): void
    {
        $this->copies->expects($this->never())->method('handle');

        foreach (['bob', ''] as $person) {
            $response = $this->receive($this->signed($this->notice('delivery', '9' . $person, $person)));

            self::assertSame(Http::STATUS_CONFLICT, $response->getStatus(), $person);
            self::assertSame('not_linked', $response->getData()['error']);
        }
    }

    public function testSynaplansRefusedVectorsAreRefusedWithoutCallingOut(): void
    {
        $this->copies->expects($this->never())->method('handle');
        $keys = $this->keysOf([['publicKey' => $this->fixture['public_key']]], $this->fixture['issuer']);
        $keys->expects($this->atLeastOnce())->method('refreshForUnknownKey')->willReturn(false);
        $this->links->expects($this->never())->method('claimDelivery');

        foreach ($this->fixture['vectors'] as $vector) {
            if ($vector['expect'] === NotificationSignature::OK) {
                continue;
            }
            $response = $this->receive($vector['token'], $keys);

            self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus(), $vector['name']);
            self::assertSame($vector['expect'], $response->getData()['error'], $vector['name']);
        }
    }

    public function testANoticeSignedWithAKeySynaplanJustStartedUsingIsTakenAfterOneRefresh(): void
    {
        $body = $this->signed($this->notice('delivery', '9', 'ada'));
        $current = $this->keys->keys();
        $keys = $this->createMock(NotifyKeyCache::class);
        $keys->method('issuer')->willReturn(self::ISSUER);
        $keys->method('keys')->willReturnOnConsecutiveCalls([], $current);
        $keys->expects($this->once())->method('refreshForUnknownKey')->with(self::NOW)->willReturn(true);
        $this->copies->expects($this->once())->method('handle')->willReturn(['status' => 200, 'body' => ['status' => 'delivered']]);

        self::assertSame(Http::STATUS_OK, $this->receive($body, $keys)->getStatus());
    }

    public function testANoticeNoKeyVerifiesIsRefusedWhenTheRefreshFindsNothingNew(): void
    {
        $keys = $this->keysOf([]);
        $keys->expects($this->once())->method('refreshForUnknownKey')->willReturn(false);
        $this->copies->expects($this->never())->method('handle');

        $response = $this->receive($this->signed($this->notice('delivery', '9', 'ada')), $keys);

        self::assertSame(Http::STATUS_UNAUTHORIZED, $response->getStatus());
        self::assertSame(NotificationSignature::BAD_SIGNATURE, $response->getData()['error']);
    }

    public function testAnUnsignedBodyIsRefused(): void
    {
        $this->copies->expects($this->never())->method('handle');

        self::assertSame(Http::STATUS_UNAUTHORIZED, $this->receive('{"type":"delivery","id":"9"}')->getStatus());
        self::assertSame(Http::STATUS_UNAUTHORIZED, $this->receive('')->getStatus());
    }

    public function testASignedNoticeWithoutTypeOrIdIsRefused(): void
    {
        $claims = $this->notice('delivery', '9', 'ada');
        unset($claims['id']);

        self::assertSame(Http::STATUS_UNAUTHORIZED, $this->receive($this->signed($claims))->getStatus());
    }

    public function testAWithdrawnIdentityEndsTheLinkOfThePersonItNames(): void
    {
        $this->accounts->expects($this->once())->method('linkEnded')->with('ada');
        $this->copies->expects($this->never())->method('handle');

        $response = $this->receive($this->signed($this->notice('identity_revoked', '2267', 'ada')));

        self::assertSame(Http::STATUS_NO_CONTENT, $response->getStatus());
    }

    public function testANoticeThisAppDoesNotHandleYetIsAcknowledged(): void
    {
        $this->copies->expects($this->never())->method('handle');

        $response = $this->receive($this->signed($this->notice('watches_changed', '31', 'ada')));

        self::assertSame(Http::STATUS_NO_CONTENT, $response->getStatus());
    }

    public function testOneAddressIsHeldToAHundredAndTwentyRequestsAMinute(): void
    {
        for ($i = 0; $i < 120; $i++) {
            self::assertSame(Http::STATUS_UNAUTHORIZED, $this->receive('forged')->getStatus());
        }
        $limited = $this->receive('forged');
        self::assertSame(Http::STATUS_TOO_MANY_REQUESTS, $limited->getStatus());
        self::assertSame('rate_limited', $limited->getData()['error']);

        $this->remote = '203.0.113.8';
        self::assertSame(Http::STATUS_UNAUTHORIZED, $this->receive('forged')->getStatus(), 'another address has its own count');
    }

    private function receive(string $body, ?NotifyKeyCache $keys = null): JSONResponse
    {
        $request = $this->createStub(IRequest::class);
        $request->method('getRemoteAddress')->willReturn($this->remote);
        $config = $this->createStub(SynaplanConfig::class);
        $config->method('getLinkInstanceId')->willReturn($this->fixture['receiver_instance_id']);
        $time = $this->createStub(ITimeFactory::class);
        $time->method('getTime')->willReturn(self::NOW);

        $controller = new class ($body, $request, $config, $keys ?? $this->keys, $this->seen, $this->copies, $this->links, $this->accounts, $this->cache, $time, $this->createStub(ILogger::class)) extends InboundController {
            public function __construct(private string $sent, mixed ...$services)
            {
                parent::__construct(...$services);
            }

            protected function body(): string
            {
                return $this->sent;
            }
        };

        return $controller->receive();
    }

    /**
     * @param list<array<string, mixed>> $keys
     */
    private function keysOf(array $keys, string $issuer = self::ISSUER): NotifyKeyCache&MockObject
    {
        $cache = $this->createMock(NotifyKeyCache::class);
        $cache->method('issuer')->willReturn($issuer);
        $cache->method('keys')->willReturn($keys);

        return $cache;
    }

    /**
     * @return array<string, mixed>
     */
    private function notice(string $type, string $id, string $person): array
    {
        return [
            'iss' => self::ISSUER,
            'aud' => $this->fixture['receiver_instance_id'],
            'sub' => $person,
            'type' => $type,
            'id' => $id,
            'iat' => self::NOW,
            'exp' => self::NOW + 300,
            'jti' => $type . '-' . $id,
        ];
    }

    /**
     * @param array<string, mixed> $claims
     */
    private function signed(array $claims): string
    {
        $input = self::b64((string) json_encode(['alg' => 'EdDSA', 'typ' => 'synaplan-notification+jwt', 'kid' => 'test']))
            . '.' . self::b64((string) json_encode($claims));

        return $input . '.' . self::b64(sodium_crypto_sign_detached($input, $this->secret));
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}

/**
 * The ten-minute memory the database store keeps, without a database.
 */
final class MemorySeenNotifications implements SeenNotificationStore
{
    /** @var array<string, true> */
    private array $seen = [];

    public function alreadySeen(string $type, string $id): bool
    {
        $key = $type . '|' . $id;
        if (isset($this->seen[$key])) {
            return true;
        }
        $this->seen[$key] = true;

        return false;
    }
}
