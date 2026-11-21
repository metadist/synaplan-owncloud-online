<?php

declare(strict_types=1);

namespace OCA\OcoSynaplan\Tests\Unit\Service;

use OCA\OcoSynaplan\Exception\EmailConflictException;
use OCA\OcoSynaplan\Http\Failure;
use OCA\OcoSynaplan\Service\UserAccountService;
use OCA\OcoSynaplan\Tests\Unit\Support\BuildsServices;
use OCA\OcoSynaplan\Tests\Unit\Support\FakeCrypto;
use OCA\OcoSynaplan\Tests\Unit\Support\RecordingHttp;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UserAccountServiceTest extends TestCase
{
    use BuildsServices;

    protected function setUp(): void
    {
        $this->setUpServices(['mode' => 'provision', 'api_key' => FakeCrypto::stored('sk_admin')]);
        $this->signIn($this->person('ada'));
        $this->config->setUserValue('ada', 'oco_synaplan', UserAccountService::CONSENT_PREF, '1');
    }

    public function testAnEmailSynaplanAlreadyKnowsIsNamedForThePerson(): void
    {
        $this->http->answer(static fn (): IResponse => RecordingHttp::response(409, '{"error":"Email already in use"}'));

        self::assertNull($this->accounts()->getCurrentUserApiKey());
        self::assertSame(Failure::EMAIL_ALREADY_USED, $this->accounts()->provisioningFailure()?->code);

        $this->expectException(EmailConflictException::class);
        $this->accounts()->provisionAccount($this->person('ada'));
    }

    public function testWithoutTheInstallKeyNoAccountIsCreated(): void
    {
        unset($this->config->app['oco_synaplan']['api_key']);

        self::assertNull($this->accounts()->getCurrentUserApiKey());
        self::assertSame(Failure::ADMIN_KEY_MISSING, $this->accounts()->provisioningFailure()?->code);
        self::assertSame([], $this->http->calls);
    }

    public function testAPlanWithoutApiKeysIsNamed(): void
    {
        $this->http->answer(static fn (): IResponse => RecordingHttp::response(403, '{"error":"API_PLAN_REQUIRED"}'));

        $this->accounts()->getCurrentUserApiKey();

        self::assertSame(Failure::API_PLAN_REQUIRED, $this->accounts()->provisioningFailure()?->code);
    }

    public function testACrashInSynaplanStaysInTheLog(): void
    {
        $this->http->answer(static fn (): IResponse => RecordingHttp::response(500, '{"error":"SQLSTATE[42S02]: Base table or view not found"}'));

        $this->accounts()->getCurrentUserApiKey();

        $failure = $this->accounts()->provisioningFailure();
        self::assertSame([Failure::ACCOUNT_UNAVAILABLE, 'Synaplan answered HTTP 500.'], [$failure?->code, $failure?->message]);
    }

    public function testAPersonWithoutAnEmailGetsAStableOne(): void
    {
        $this->signIn($this->person('bob', ''));
        $this->config->setUserValue('bob', 'oco_synaplan', UserAccountService::CONSENT_PREF, '1');
        $this->http->answer(static fn (string $method, string $path): array => match ($path) {
            '/api/v1/admin/users' => ['user' => ['id' => 8]],
            default => ['api_key' => ['id' => 13, 'key' => 'sk_bob']],
        });

        self::assertSame('sk_bob', $this->accounts()->getCurrentUserApiKey());
        self::assertSame('bob@oc1234567890.owncloud.local', $this->http->to('/api/v1/admin/users')[0]['body']['email']);
    }

    public function testUnattendedWorkUsesThePersonsOwnCredential(): void
    {
        $this->keys()->setUserKey('ada', 'sk_ada');
        self::assertSame('sk_ada', $this->accounts()->credentialFor('ada'));

        $this->config->setAppValue('oco_synaplan', 'mode', 'link');
        $this->register();
        $this->connect('bob', 'access-bob');
        self::assertSame('access-bob', $this->accounts()->credentialFor('bob'));
        self::assertSame([], $this->http->calls);
    }

    public function testAConnectedPersonIsShownWithTheirSynaplanEmail(): void
    {
        $this->config->setAppValue('oco_synaplan', 'mode', 'link');
        $this->register();
        $this->connect('ada');
        $this->accounts()->storeLinkedAccount($this->person('ada'), ['id' => 5, 'email' => 'ada@synaplan.example.org']);

        $status = $this->accounts()->getLinkStatus();

        self::assertSame('link', $status['mode']);
        self::assertTrue($status['link_available']);
        self::assertSame('linked', $status['kind']);
        self::assertIsArray($status['linked']);
        self::assertSame('ada@synaplan.example.org', $status['linked']['email']);
        self::assertNotSame('', $status['linked']['since']);
        self::assertTrue($this->accounts()->wasLinked('ada'));
    }

    public function testDeletingAPersonDeletesOnlyAnAccountTheAppCreated(): void
    {
        $this->config->setUserValue('ada', 'oco_synaplan', UserAccountService::USER_ACCOUNT_ID_PREF, '7');
        $this->accounts()->deleteRemoteAccount('ada');
        self::assertSame(['DELETE', '/api/v1/admin/users/7', 'sk_admin'], [
            $this->http->calls[0]['method'],
            $this->http->calls[0]['path'],
            $this->http->calls[0]['options']['headers']['X-API-Key'],
        ]);

        $this->config->setUserValue('bob', 'oco_synaplan', UserAccountService::USER_ACCOUNT_ID_PREF, '5');
        $this->config->setUserValue('bob', 'oco_synaplan', UserAccountService::LINK_ORIGIN_PREF, 'linked');
        $this->accounts()->deleteRemoteAccount('bob');
        self::assertCount(1, $this->http->calls, 'an account the person brought is theirs');
    }

    public function testWithdrawingConsentForgetsTheKey(): void
    {
        $this->keys()->setUserKey('ada', 'sk_ada');

        $this->accounts()->revokeConsent();

        self::assertFalse($this->accounts()->hasConsentForCurrentUser());
        self::assertSame('', $this->keys()->getUserKey('ada'));
        self::assertNull($this->accounts()->getCurrentUserApiKey(), 'no account is created without consent');
        self::assertSame([], $this->http->calls);
    }

    public function testAKeyMintedBeforeWatchingIsReplacedAndTheOldOneRevoked(): void
    {
        $this->keys()->setUserKey('ada', 'sk_old');
        $this->config->setUserValue('ada', 'oco_synaplan', UserAccountService::USER_KEY_ID_PREF, '11');
        $this->http->answer(static fn (string $method, string $path): array => match ($path) {
            '/api/v1/admin/users' => ['user' => ['id' => 7]],
            '/api/v1/admin/users/7/api-keys' => ['api_key' => ['id' => 12, 'key' => 'sk_new']],
            default => ['success' => true],
        });

        self::assertSame('sk_new', $this->accounts()->renewProvisionedKey());

        $revoke = $this->http->to('/api/v1/apikeys/11')[0];
        self::assertSame(['DELETE', 'sk_old'], [$revoke['method'], $revoke['options']['headers']['X-API-Key']]);
        self::assertSame('chat files rag cloud:watch memories', $this->config->userValue('ada', UserAccountService::KEY_SCOPES_PREF));
        self::assertNull($this->accounts()->renewProvisionedKey(), 'a key holding every scope stays');
    }

    /**
     * @return array<string, array{0: string, 1: list<string>}>
     */
    public static function memoriesOffered(): array
    {
        return [
            'memories offered' => ['1', ['chat', 'files', 'rag', 'cloud:watch', 'memories']],
            'memories off' => ['0', ['chat', 'files', 'rag', 'cloud:watch']],
        ];
    }

    /**
     * @param list<string> $scopes
     */
    #[DataProvider('memoriesOffered')]
    public function testAKeyCarriesMemoriesWhileTheAdministratorOffersThem(string $enabled, array $scopes): void
    {
        $this->config->setAppValue('oco_synaplan', 'enable_memories', $enabled);
        $this->http->answer(static fn (string $method, string $path): array => match ($path) {
            '/api/v1/admin/users' => ['user' => ['id' => 7]],
            default => ['api_key' => ['id' => 12, 'key' => 'sk_ada']],
        });

        self::assertSame('sk_ada', $this->accounts()->getCurrentUserApiKey());
        self::assertSame($scopes, $this->http->to('/api/v1/admin/users/7/api-keys')[0]['body']['scopes']);
        self::assertSame(implode(' ', $scopes), $this->config->userValue('ada', UserAccountService::KEY_SCOPES_PREF));
    }

    public function testAKeyWithoutMemoriesIsReplacedOnceTheyAreOffered(): void
    {
        $this->keys()->setUserKey('ada', 'sk_old');
        $this->config->setUserValue('ada', 'oco_synaplan', UserAccountService::KEY_SCOPES_PREF, 'chat files rag cloud:watch');
        $this->config->setAppValue('oco_synaplan', 'enable_memories', '0');
        self::assertNull($this->accounts()->renewProvisionedKey(), 'while memories are off the key holds every scope');

        $this->config->setAppValue('oco_synaplan', 'enable_memories', '1');
        $this->http->answer(static fn (string $method, string $path): array => match ($path) {
            '/api/v1/admin/users' => ['user' => ['id' => 7]],
            default => ['api_key' => ['id' => 12, 'key' => 'sk_new']],
        });

        self::assertSame('sk_new', $this->accounts()->renewProvisionedKey());
        self::assertSame('chat files rag cloud:watch memories', $this->config->userValue('ada', UserAccountService::KEY_SCOPES_PREF));
    }
}
