<?php

declare(strict_types=1);

namespace OCA\OcoSynaplan\Tests\Unit\Service;

use OCA\OcoSynaplan\Exception\EmailConflictException;
use OCA\OcoSynaplan\Exception\FailureException;
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

    public function testDeactivatePausesAProvisionedPersonAndKeepsTheKey(): void
    {
        $this->provisionedCarol();
        $this->http->answer(static fn (): array => self::lifecycleAnswer('kept', 'suspended'));

        self::assertTrue($this->accounts()->pauseProvisioned('carol'));

        self::assertSame(['external_id' => 'carol', 'event' => 'disabled'], $this->http->to('/oauth/register/client-1/lifecycle')[0]['body']);
        self::assertCount(1, $this->http->calls, 'the key is not deleted');
        self::assertTrue($this->accounts()->isPaused('carol'));
        self::assertNull($this->config->userValue('carol', UserAccountService::CONSENT_PREF));
        self::assertNull($this->config->userValue('carol', UserAccountService::CONSENT_AT_PREF));
        self::assertSame('sk_carol', $this->keys()->getUserKey('carol'));
        self::assertSame('9', $this->config->userValue('carol', UserAccountService::USER_KEY_ID_PREF));
        self::assertSame(4, $this->accounts()->getSynaplanUserId('carol'));
    }

    public function testAPausedPersonResolvesNoKeyUntilTheyActivateAgain(): void
    {
        $this->pausedCarol();

        self::assertNull($this->accounts()->resolveKeyForUser($this->person('carol')));
        self::assertSame([], $this->http->calls, 'nothing is provisioned for a paused person');
    }

    public function testActivatingAPausedPersonResumesTheSameKey(): void
    {
        $this->pausedCarol();
        $this->http->answer(static fn (): array => self::lifecycleAnswer('kept', 'active'));

        $this->accounts()->grantConsent();

        self::assertSame(['external_id' => 'carol', 'event' => 'enabled'], $this->http->calls[0]['body']);
        self::assertFalse($this->accounts()->isPaused('carol'));
        self::assertTrue($this->accounts()->hasConsentForCurrentUser());
        self::assertSame('sk_carol', $this->accounts()->resolveKeyForUser($this->person('carol')));
        self::assertCount(1, $this->http->calls, 'the same account and key: nothing is provisioned');
    }

    public function testAResumeSynaplanRefusesLeavesAiOffAndSaysWhy(): void
    {
        $this->pausedCarol();
        $this->http->answer(static fn (): IResponse => RecordingHttp::response(409, '{"error":"identity_revoked"}'));

        try {
            $this->accounts()->grantConsent();
            self::fail('the refusal reaches the person');
        } catch (FailureException $e) {
            self::assertSame([409, Failure::ACCOUNT_UNAVAILABLE], [$e->failure->status, $e->failure->code]);
        }
        self::assertFalse($this->accounts()->hasConsentForCurrentUser());
        self::assertTrue($this->accounts()->isPaused('carol'));
    }

    public function testAResumeWhileSynaplanCannotBeReachedSaysSo(): void
    {
        $this->pausedCarol();
        $this->http->answer(static fn (): \Throwable => new \RuntimeException('cURL error 7'));

        try {
            $this->accounts()->grantConsent();
            self::fail('the person learns Synaplan cannot be reached');
        } catch (FailureException $e) {
            self::assertSame([502, Failure::SYNAPLAN_UNREACHABLE], [$e->failure->status, $e->failure->code]);
        }
        self::assertTrue($this->accounts()->isPaused('carol'));
    }

    public function testActivatingAfterTheAccountWasDeletedAtSynaplanStartsOver(): void
    {
        $this->pausedCarol();
        $this->http->answer(static fn (): array => self::lifecycleAnswer('absent', 'revoked'));

        $this->accounts()->grantConsent();

        self::assertFalse($this->accounts()->isPaused('carol'));
        self::assertSame('', $this->keys()->getUserKey('carol'), 'the key of a deleted account is dead');
        self::assertTrue($this->accounts()->hasConsentForCurrentUser());
        self::assertCount(1, $this->http->calls);
    }

    public function testActivatingAfterTheRegistrationEndedStartsOver(): void
    {
        $this->keys()->setUserKey('carol', 'sk_carol');
        $this->config->setUserValue('carol', 'oco_synaplan', UserAccountService::PAUSED_PREF, '1');
        $this->signIn($this->person('carol'));

        $this->accounts()->grantConsent();

        self::assertFalse($this->accounts()->isPaused('carol'));
        self::assertSame('', $this->keys()->getUserKey('carol'), 'the paused identity went with the registration');
        self::assertTrue($this->accounts()->hasConsentForCurrentUser());
        self::assertSame([], $this->http->calls);
    }

    public function testAPausedPersonWhoConnectsTheirOwnAccountUsesIt(): void
    {
        $this->pausedCarol();
        $this->config->setAppValue('oco_synaplan', 'mode', 'link');
        $this->connect('carol', 'access-carol');

        $this->accounts()->storeLinkedAccount($this->person('carol'), ['id' => 12, 'email' => 'carol@synaplan.example.org']);

        self::assertFalse($this->accounts()->isPaused('carol'));
        self::assertSame('', $this->keys()->getUserKey('carol'), 'their own account replaces the key this server created');
        self::assertSame('access-carol', $this->accounts()->resolveKeyForUser($this->person('carol')));
        self::assertSame(12, $this->accounts()->getSynaplanUserId('carol'));
        self::assertSame([], $this->http->calls);
    }

    public function testDeactivateChangesNothingWhenSynaplanCannotBeTold(): void
    {
        $this->provisionedCarol();
        $this->http->answer(static fn (): \Throwable => new \RuntimeException('cURL error 7'));

        try {
            $this->accounts()->pauseProvisioned('carol');
            self::fail('the admin learns Synaplan was not told');
        } catch (FailureException $e) {
            self::assertSame([502, Failure::SYNAPLAN_UNREACHABLE], [$e->failure->status, $e->failure->code]);
        }
        self::assertSame('1', $this->config->userValue('carol', UserAccountService::CONSENT_PREF));
        self::assertFalse($this->accounts()->isPaused('carol'));
        self::assertSame('sk_carol', $this->keys()->getUserKey('carol'));
    }

    #[DataProvider('nothingToResume')]
    public function testDeactivateWithNothingToResumeForgetsTheKeyHereOnly(string $account): void
    {
        $this->provisionedCarol();
        $this->http->answer(static fn (): array => self::lifecycleAnswer($account, 'revoked'));

        self::assertTrue($this->accounts()->pauseProvisioned('carol'));

        self::assertCount(1, $this->http->calls, 'the key is not deleted there');
        self::assertSame('', $this->keys()->getUserKey('carol'));
        self::assertNull($this->config->userValue('carol', UserAccountService::CONSENT_PREF));
        self::assertFalse($this->accounts()->isPaused('carol'));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function nothingToResume(): array
    {
        return [
            'a key from before the registration' => ['absent'],
            'an identity Synaplan already ended' => ['unchanged'],
        ];
    }

    public function testPausingIsNotForConnectedPeopleOrAnUnregisteredServer(): void
    {
        $this->keys()->setUserKey('carol', 'sk_carol');
        self::assertFalse($this->accounts()->pauseProvisioned('carol'), 'not registered');

        $this->register();
        $this->connect('bob');
        self::assertFalse($this->accounts()->pauseProvisioned('bob'), 'connected');
        self::assertFalse($this->accounts()->pauseProvisioned('nobody'), 'no key');
        self::assertSame([], $this->http->calls);
    }

    public function testAnAccountFromBeforeTheRegistrationIsMovedOntoItWithItsOwnEmail(): void
    {
        $this->legacyCarol();
        $this->http->answer(static fn (string $method, string $path): array => match ($path) {
            '/api/v1/admin/users/4/usage' => ['user' => [
                'id' => 4,
                'email' => 'carol@old.example.org',
                'external_id' => 'oc1234567890:carol',
                'display_name' => 'Carol',
            ]],
            default => ['user' => ['id' => 4]],
        });

        self::assertTrue($this->accounts()->adoptLegacyAccount('carol'));

        $provision = $this->http->to('/api/v1/admin/users');
        self::assertCount(1, $provision);
        self::assertSame([
            'source' => 'owncloud',
            'instance_id' => 'client-1',
            'external_id' => 'carol',
            'email' => 'carol@old.example.org',
            'display_name' => 'Carol',
        ], $provision[0]['body']);
        self::assertSame('sk_admin', $provision[0]['options']['headers']['X-API-Key']);
        self::assertCount(2, $this->http->calls, 'no key is minted');
        self::assertSame('sk_carol', $this->keys()->getUserKey('carol'));
    }

    public function testAnAccountProvisionedOnTheRegistrationIsLeftAlone(): void
    {
        $this->legacyCarol();
        $this->http->answer(static fn (): array => ['user' => ['id' => 4, 'email' => 'carol@example.org', 'external_id' => 'carol']]);

        self::assertTrue($this->accounts()->adoptLegacyAccount('carol'));

        self::assertSame(['/api/v1/admin/users/4/usage'], array_column($this->http->calls, 'path'));
    }

    public function testNothingIsAdoptedBeforeTheRegistrationOrWithoutAnAccount(): void
    {
        $this->config->setUserValue('carol', 'oco_synaplan', UserAccountService::USER_ACCOUNT_ID_PREF, '4');

        self::assertTrue($this->accounts()->adoptLegacyAccount('carol'), 'not registered');
        $this->register();
        self::assertTrue($this->accounts()->adoptLegacyAccount('bob'), 'no account');
        self::assertSame([], $this->http->calls);
    }

    public function testAnAccountSynaplanCannotFindIsNotCreatedAgain(): void
    {
        $this->legacyCarol();
        $this->http->answer(static fn (string $method, string $path): array|IResponse => match ($path) {
            '/api/v1/admin/users/4/usage' => ['user' => ['email' => 'carol@old.example.org', 'external_id' => 'oc1234567890:carol']],
            default => RecordingHttp::response(409, '{"error":"Email already in use"}'),
        });

        self::assertTrue($this->accounts()->adoptLegacyAccount('carol'), 'trying again would only be refused again');
    }

    public function testAnAdoptionWhileSynaplanCannotBeReachedIsTriedAgainLater(): void
    {
        $this->legacyCarol();
        $this->http->answer(static fn (): \Throwable => new \RuntimeException('cURL error 7'));

        self::assertFalse($this->accounts()->adoptLegacyAccount('carol'));
    }

    /** Carol's account was provisioned before this ownCloud.online registered. */
    private function legacyCarol(): void
    {
        $this->register();
        $this->keys()->setUserKey('carol', 'sk_carol');
        $this->config->setUserValue('carol', 'oco_synaplan', UserAccountService::USER_ACCOUNT_ID_PREF, '4');
    }

    /** Carol holds a key provisioned on this registered ownCloud.online. */
    private function provisionedCarol(): void
    {
        $this->register();
        $this->keys()->setUserKey('carol', 'sk_carol');
        foreach ([
            UserAccountService::USER_KEY_ID_PREF => '9',
            UserAccountService::USER_ACCOUNT_ID_PREF => '4',
            UserAccountService::CONSENT_PREF => '1',
            UserAccountService::CONSENT_AT_PREF => '2026-11-01T00:00:00+00:00',
        ] as $key => $value) {
            $this->config->setUserValue('carol', 'oco_synaplan', $key, $value);
        }
    }

    /** Carol, after an admin's Deactivate paused her, signed in. */
    private function pausedCarol(): void
    {
        $this->provisionedCarol();
        $this->config->setUserValue('carol', 'oco_synaplan', UserAccountService::PAUSED_PREF, '1');
        $this->config->deleteUserValue('carol', 'oco_synaplan', UserAccountService::CONSENT_PREF);
        $this->config->deleteUserValue('carol', 'oco_synaplan', UserAccountService::CONSENT_AT_PREF);
        $this->signIn($this->person('carol'));
    }

    /**
     * @return array{success: bool, account: string, status: string, message: string}
     */
    private static function lifecycleAnswer(string $account, string $status): array
    {
        return ['success' => true, 'account' => $account, 'status' => $status, 'message' => ''];
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
