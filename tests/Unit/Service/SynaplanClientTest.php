<?php

declare(strict_types=1);

namespace OCA\OcoSynaplan\Tests\Unit\Service;

use OCA\OcoSynaplan\Http\Failure;
use OCA\OcoSynaplan\Service\KeyStorage;
use OCA\OcoSynaplan\Service\SynaplanClient;
use OCA\OcoSynaplan\Service\UserAccountService;
use OCA\OcoSynaplan\Tests\Unit\Support\BuildsServices;
use OCA\OcoSynaplan\Tests\Unit\Support\FakeCrypto;
use OCA\OcoSynaplan\Tests\Unit\Support\HttpErrorException;
use OCA\OcoSynaplan\Tests\Unit\Support\RecordingHttp;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SynaplanClientTest extends TestCase
{
    use BuildsServices;

    private const SUMMARY = ['success' => true, 'summary' => 'Three points.'];

    protected function setUp(): void
    {
        $this->setUpServices(['api_key' => FakeCrypto::stored('sk_admin')]);
        $this->signIn($this->person('ada'));
    }

    public function testSharedModeUsesTheInstallKey(): void
    {
        $this->config->setAppValue('oco_synaplan', 'mode', 'shared');
        $this->http->answer(static fn (): array => self::SUMMARY);

        self::assertSame(self::SUMMARY, $this->client()->summarize('Some text'));
        self::assertSame('sk_admin', $this->http->to('/api/v1/summary/generate')[0]['options']['headers']['X-API-Key']);
    }

    public function testWithoutConsentNothingIsSentAndThePersonIsAskedFirst(): void
    {
        $this->config->setAppValue('oco_synaplan', 'mode', 'provision');

        $this->assertFailure(Failure::CONSENT_REQUIRED, fn () => $this->client()->summarize('Some text'));
        self::assertSame([], $this->http->calls);
    }

    public function testTheFirstRequestAfterConsentCreatesTheAccountWithTheInstallKeyAndUsesThePersonsKey(): void
    {
        $this->config->setAppValue('oco_synaplan', 'mode', 'provision');
        $this->config->setUserValue('ada', 'oco_synaplan', UserAccountService::CONSENT_PREF, '1');
        $this->http->answer(static fn (string $method, string $path): array => match ($path) {
            '/api/v1/admin/users' => ['success' => true, 'user' => ['id' => 7]],
            '/api/v1/admin/users/7/api-keys' => ['success' => true, 'api_key' => ['id' => 11, 'key' => 'sk_ada']],
            default => self::SUMMARY,
        });

        $this->client()->summarize('Some text');

        [$account, $key, $summary] = $this->http->calls;
        self::assertSame(['/api/v1/admin/users', 'sk_admin'], [$account['path'], $account['options']['headers']['X-API-Key']]);
        self::assertSame('owncloud', $account['body']['source']);
        self::assertSame('oc1234567890:ada', $account['body']['external_id'], 'unregistered: the id release 1.0.0 sent');
        self::assertSame('ada@example.org', $account['body']['email']);
        self::assertSame(['/api/v1/admin/users/7/api-keys', 'sk_admin'], [$key['path'], $key['options']['headers']['X-API-Key']]);
        self::assertSame(['chat', 'files', 'rag', 'cloud:watch', 'memories'], $key['body']['scopes']);
        self::assertSame(['/api/v1/summary/generate', 'sk_ada'], [$summary['path'], $summary['options']['headers']['X-API-Key']]);
        self::assertSame(FakeCrypto::stored('sk_ada'), $this->config->userValue('ada', KeyStorage::USER_KEY));
        self::assertSame('7', $this->config->userValue('ada', UserAccountService::USER_ACCOUNT_ID_PREF));
        self::assertSame('11', $this->config->userValue('ada', UserAccountService::USER_KEY_ID_PREF));
    }

    public function testARegisteredServerSendsItsClientIdAndTheBareUserId(): void
    {
        $this->register('client-1');
        $this->config->setAppValue('oco_synaplan', 'mode', 'provision');
        $this->config->setUserValue('ada', 'oco_synaplan', UserAccountService::CONSENT_PREF, '1');
        $this->http->answer(static fn (string $method, string $path): array => match ($path) {
            '/api/v1/admin/users' => ['user' => ['id' => 7]],
            '/api/v1/admin/users/7/api-keys' => ['api_key' => ['id' => 11, 'key' => 'sk_ada']],
            default => self::SUMMARY,
        });

        $this->client()->summarize('Some text');

        $account = $this->http->to('/api/v1/admin/users')[0]['body'];
        self::assertSame(['client-1', 'ada'], [$account['instance_id'], $account['external_id']]);
        self::assertSame('client-1', $this->http->to('/api/v1/admin/users/7/api-keys')[0]['body']['instance_id']);
    }

    public function testARefusedProvisionedKeyIsReplacedOnceAndTheRequestRepeated(): void
    {
        $this->config->setAppValue('oco_synaplan', 'mode', 'provision');
        $this->config->setUserValue('ada', 'oco_synaplan', UserAccountService::CONSENT_PREF, '1');
        $this->keys()->setUserKey('ada', 'sk_old');
        $this->http->answer(static fn (string $method, string $path, array $options): array|\Throwable => match (true) {
            $path === '/api/v1/admin/users' => ['user' => ['id' => 7]],
            $path === '/api/v1/admin/users/7/api-keys' => ['api_key' => ['id' => 12, 'key' => 'sk_new']],
            ($options['headers']['X-API-Key'] ?? '') === 'sk_old' => new HttpErrorException(401, '{"error":"Invalid API key"}'),
            default => self::SUMMARY,
        });

        self::assertSame(self::SUMMARY, $this->client()->summarize('Some text'));
        self::assertCount(2, $this->http->to('/api/v1/summary/generate'));
        self::assertSame('sk_new', $this->http->to('/api/v1/summary/generate')[1]['options']['headers']['X-API-Key']);
        self::assertSame('sk_new', $this->keys()->getUserKey('ada'));
    }

    public function testInLinkModeAPersonWhoDidNotConnectIsAskedToConnect(): void
    {
        $this->config->setAppValue('oco_synaplan', 'mode', 'link');
        $this->register();

        $this->assertFailure(Failure::NOT_CONNECTED, fn () => $this->client()->summarize('Some text'));
        self::assertSame([], $this->http->calls);
    }

    public function testAConnectedPersonsAccessTokenTravelsAsBearerAndNeverTheInstallKey(): void
    {
        $this->config->setAppValue('oco_synaplan', 'mode', 'link');
        $this->register();
        $this->connect('ada', 'access-ada');
        $this->http->answer(static fn (): array => self::SUMMARY);

        $this->client()->summarize('Some text');

        $headers = $this->http->to('/api/v1/summary/generate')[0]['options']['headers'];
        self::assertSame('Bearer access-ada', $headers['Authorization']);
        self::assertArrayNotHasKey('X-API-Key', $headers);
    }

    public function testARefusedTokenEndsTheConnectionHereAndIsNotRetried(): void
    {
        $this->config->setAppValue('oco_synaplan', 'mode', 'link');
        $this->register();
        $this->connect('ada', 'access-ada');
        $this->config->setUserValue('ada', 'oco_synaplan', UserAccountService::LINK_KIND_PREF, 'linked');
        $this->http->answer(static fn (): \Throwable => new HttpErrorException(401, '{"error":"invalid_token"}'));

        $this->assertFailure(Failure::CONNECTION_ENDED, fn () => $this->client()->summarize('Some text'));
        self::assertCount(1, $this->http->calls);
        self::assertFalse($this->oauth()->isConnected('ada'));
        self::assertNull($this->config->userValue('ada', UserAccountService::LINK_KIND_PREF));
    }

    public function testTurningTheAppOffInSynaplanReachesThePersonWithItsSentence(): void
    {
        $this->config->setAppValue('oco_synaplan', 'mode', 'provision');
        $this->keys()->setUserKey('ada', 'sk_ada');
        $this->http->answer(static fn (): \Throwable => new HttpErrorException(403, '{"error":"platform_disconnected","message":"You turned Synaplan off for this app in Synaplan."}'));

        $failure = $this->assertFailure(Failure::PLATFORM_DISCONNECTED, fn () => $this->client()->summarize('Some text'));
        self::assertSame('You turned Synaplan off for this app in Synaplan.', $failure->message);
        self::assertSame('sk_ada', $this->keys()->getUserKey('ada'), 'the key works again once the person turns the app back on');
    }

    public function testASynaplanWithoutTranslationIsNotAskedForASummaryInstead(): void
    {
        $this->config->setAppValue('oco_synaplan', 'mode', 'shared');
        $this->http->answer(static fn (string $method, string $path): array => match ($path) {
            '/api/v1/config/capabilities' => ['summary' => ['types' => ['bullet-points', 'abstract']]],
            default => self::SUMMARY,
        });

        $this->assertFailure(Failure::TRANSLATION_UNAVAILABLE, fn () => $this->client()->translate('Hallo', 'en'));
        self::assertSame([], $this->http->to('/api/v1/summary/generate'));
        self::assertArrayNotHasKey('X-API-Key', $this->http->to('/api/v1/config/capabilities')[0]['options']['headers']);
    }

    public function testTranslationIsAskedAsTheTranslationMode(): void
    {
        $this->config->setAppValue('oco_synaplan', 'mode', 'shared');
        $this->http->answer(static fn (string $method, string $path): array => match ($path) {
            '/api/v1/config/capabilities' => ['summary' => ['types' => ['translation']]],
            default => self::SUMMARY,
        });

        $this->client()->translate('Hallo', 'en');

        $body = $this->http->to('/api/v1/summary/generate')[0]['body'];
        self::assertSame(['Hallo', 'translation', 'en'], [$body['text'], $body['summaryType'], $body['outputLanguage']]);
    }

    /**
     * @return array<string, array{0: array<string, mixed>|\Throwable, 1: bool}>
     */
    public static function capabilityAnswers(): array
    {
        return [
            'translates' => [['summary' => ['types' => ['bullet-points', 'translation']]], true],
            'does not translate' => [['summary' => ['types' => ['bullet-points', 'abstract']]], false],
            'names no summary types' => [['file_formats' => ['pdf']], true],
            'cannot be read' => [new HttpErrorException(502, 'Bad gateway'), true],
        ];
    }

    /**
     * @param array<string, mixed>|\Throwable $answer
     */
    #[DataProvider('capabilityAnswers')]
    public function testTranslateIsOfferedUnlessSynaplanSaidItCannotTranslate(array|\Throwable $answer, bool $offered): void
    {
        $this->http->answer(static fn (): array|\Throwable => $answer);

        self::assertSame($offered, $this->client()->offersTranslation());
    }

    public function testSynaplanIsAskedWhatItSupportsOnceForEachAddress(): void
    {
        $this->http->answer(static fn (): array => ['summary' => ['types' => ['translation']]]);

        $this->client()->offersTranslation();
        $this->nextRequest()->offersTranslation();
        self::assertCount(1, $this->http->to('/api/v1/config/capabilities'));
        self::assertSame([600], array_values($this->cache->ttls));

        $this->config->setAppValue('oco_synaplan', 'synaplan_url', 'http://other.example.org');
        $this->nextRequest()->offersTranslation();
        self::assertCount(2, $this->http->to('/api/v1/config/capabilities'));
    }

    public function testAnUnreadableAnswerIsAskedAgainSoon(): void
    {
        $this->http->answer(static fn (): \Throwable => new HttpErrorException(502, 'Bad gateway'));

        $this->client()->offersTranslation();

        self::assertSame([60], array_values($this->cache->ttls));
    }

    public function testOnlyAPathOnTheSynaplanHostIsFollowed(): void
    {
        self::assertTrue(SynaplanClient::isSynaplanPath('/api/v1/files/uploads/1/image.png'));
        $refused = ['', 'api/v1/x', '//evil.example.org/x', '/x\\y', "/x\r\nHost: evil", '/a b', '.example.org/x', '@example.org/x', "/x\0@evil.example.org", "/x\x0b", "/x\x1b[0m", "/x\x7f"];
        foreach ($refused as $path) {
            self::assertFalse(SynaplanClient::isSynaplanPath($path), $path);
        }
    }

    public function testAFileToSaveLargerThanTheLimitIsRefusedAndOneToShowIsNotLimited(): void
    {
        $this->http->answer(static fn (): IResponse => RecordingHttp::response(200, str_repeat('x', 11)));

        $this->assertFailure(Failure::COPY_TOO_LARGE, fn () => $this->client()->downloadFile('/api/v1/files/uploads/1/song.mp3', 10));
        self::assertArrayHasKey('on_headers', $this->http->calls[0]['options'], 'an announced length is refused before the body is read');

        self::assertSame(11, strlen($this->nextRequest()->downloadFile('/api/v1/files/uploads/1/song.mp3')));
        self::assertArrayNotHasKey('on_headers', $this->http->calls[1]['options']);
    }

    public function testAProvisionedKeyWithoutMemoriesIsReplacedWhenSynaplanAsksForTheScope(): void
    {
        $this->config->setAppValue('oco_synaplan', 'mode', 'provision');
        $this->config->setUserValue('ada', 'oco_synaplan', UserAccountService::CONSENT_PREF, '1');
        $this->keys()->setUserKey('ada', 'sk_old');
        $this->config->setUserValue('ada', 'oco_synaplan', UserAccountService::USER_KEY_ID_PREF, '11');
        $this->config->setUserValue('ada', 'oco_synaplan', UserAccountService::KEY_SCOPES_PREF, 'chat files rag cloud:watch');
        $this->http->answer(static fn (string $method, string $path, array $options): array|\Throwable => match (true) {
            $path === '/api/v1/user/memories/search' && $options['headers']['X-API-Key'] === 'sk_old' => new HttpErrorException(403, '{"error":"insufficient_scope","required":["memories"]}', 'POST', $path),
            $path === '/api/v1/user/memories/search' => ['memories' => [['key' => 'team', 'value' => 'Platform']]],
            $path === '/api/v1/admin/users' => ['user' => ['id' => 7]],
            $path === '/api/v1/admin/users/7/api-keys' => ['api_key' => ['id' => 12, 'key' => 'sk_new']],
            default => ['success' => true],
        });

        self::assertSame('- team: Platform', $this->client()->searchMemoryContext('team'));
        self::assertContains('memories', $this->http->to('/api/v1/admin/users/7/api-keys')[0]['body']['scopes']);
        self::assertSame('DELETE', $this->http->to('/api/v1/apikeys/11')[0]['method'], 'the replaced key is revoked');
    }

    /**
     * The client the server builds for the next request: the same cache, no
     * answer kept from this one.
     */
    private function nextRequest(): SynaplanClient
    {
        return new SynaplanClient($this->http, $this->logger(), $this->synaplanConfig(), $this->accounts(), $this->cache);
    }

    /**
     * The failure the person is shown, read as the controllers read it.
     */
    private function assertFailure(string $code, callable $call): Failure
    {
        try {
            $call();
        } catch (\Exception $e) {
            $failure = Failure::fromThrowable($e);
            self::assertSame($code, $failure->code, $e->getMessage());

            return $failure;
        }
        self::fail('Expected the failure ' . $code);
    }
}
