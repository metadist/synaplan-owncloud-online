<?php

declare(strict_types=1);

namespace OCA\OcoSynaplan\Tests\Unit\Service;

use OCA\OcoSynaplan\Exception\PlatformLinkException;
use OCA\OcoSynaplan\Tests\Unit\Support\BuildsServices;
use OCA\OcoSynaplan\Tests\Unit\Support\FakeCrypto;
use OCA\OcoSynaplan\Tests\Unit\Support\RecordingHttp;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\TestCase;

final class OAuthClientTest extends TestCase
{
    use BuildsServices;

    private const METADATA = [
        'redirect_uri' => self::OWNCLOUD . '/apps/oco_synaplan/public/link-return.html',
        'client_uri' => self::OWNCLOUD . '/',
        'delivery_url' => '',
        'capabilities' => [],
        'server_id' => 'oc1234567890',
        'version' => '1.1.0',
    ];

    protected function setUp(): void
    {
        $this->setUpServices();
    }

    public function testRegisteringSendsAPublicKeyAndKeepsThePrivateOneEncrypted(): void
    {
        $this->http->answer(static fn (): array => ['client_id' => 'client-1', 'status' => 'active']);

        $answer = $this->oauth()->register(self::METADATA, 'initial-access-token');

        self::assertSame('active', $answer['status']);
        $call = $this->http->to('/oauth/register')[0];
        self::assertSame('Bearer initial-access-token', $call['options']['headers']['Authorization']);
        self::assertSame('owncloud', $call['body']['software_id']);
        self::assertSame('1.1.0', $call['body']['software_version']);
        self::assertSame([self::METADATA['redirect_uri']], $call['body']['redirect_uris']);
        self::assertSame('private_key_jwt', $call['body']['token_endpoint_auth_method']);
        self::assertSame(['authorization_code', 'refresh_token'], $call['body']['grant_types']);
        self::assertSame([], $call['body']['app_capabilities']);
        self::assertSame('oc1234567890', $call['body']['server_id']);
        $jwk = $call['body']['jwks']['keys'][0];
        self::assertSame(['RSA', 'sig', 'RS256'], [$jwk['kty'], $jwk['use'], $jwk['alg']]);
        self::assertArrayNotHasKey('d', $jwk, 'only the public key leaves');

        self::assertSame('client-1', $this->oauth()->clientId());
        self::assertTrue($this->oauth()->isRegistered());
        self::assertSame($jwk['kid'], $this->config->appValue('oauth_kid'));
        self::assertStringStartsWith('enc:', (string) $this->config->appValue('oauth_private_key'));
    }

    public function testWithoutATokenNoAuthorizationIsSent(): void
    {
        $this->http->answer(static fn (): array => ['client_id' => 'client-1', 'status' => 'pending']);

        $this->oauth()->register(self::METADATA, '');

        self::assertArrayNotHasKey('Authorization', $this->http->calls[0]['options']['headers']);
    }

    public function testARefusedRegistrationLeavesTheWorkingOneAsItWas(): void
    {
        $this->register('client-1');
        $key = $this->config->appValue('oauth_private_key');
        $this->http->answer(static fn (): IResponse => RecordingHttp::response(401, '{"error":"invalid_token"}'));

        try {
            $this->oauth()->register(self::METADATA, 'wrong-token');
            self::fail('A refused registration must throw');
        } catch (PlatformLinkException) {
        }

        self::assertSame('client-1', $this->oauth()->clientId());
        self::assertSame($key, $this->config->appValue('oauth_private_key'));
    }

    public function testTheAuthorizationRequestIsPushedFromTheServerWithThePersonAndPkce(): void
    {
        $this->register('client-1');
        $this->http->answer(static fn (): array => ['request_uri' => 'urn:ietf:params:oauth:request_uri:abc', 'expires_in' => 60]);

        $authorize = $this->oauth()->authorizeUrl('ada', 'state-1', self::METADATA['redirect_uri'], true);

        $pushed = $this->http->to('/oauth/par')[0];
        self::assertTrue(str_contains($pushed['options']['headers']['Content-Type'], 'x-www-form-urlencoded'));
        self::assertSame('ada', $pushed['body']['external_id']);
        self::assertSame('S256', $pushed['body']['code_challenge_method']);
        self::assertSame(
            rtrim(strtr(base64_encode(hash('sha256', $authorize['verifier'], true)), '+/', '-_'), '='),
            $pushed['body']['code_challenge'],
        );
        self::assertSame('chat files rag cloud:watch memories', $pushed['body']['scope']);
        self::assertSame('state-1', $pushed['body']['state']);
        $claims = $this->verifiedClaims($pushed['body']['client_assertion']);
        self::assertSame(['client-1', 'client-1', self::SYNAPLAN], [$claims['iss'], $claims['sub'], $claims['aud']]);

        self::assertSame(
            self::SYNAPLAN . '/oauth/authorize?client_id=client-1&request_uri=' . rawurlencode('urn:ietf:params:oauth:request_uri:abc'),
            $authorize['url'],
        );
        self::assertStringNotContainsString('ada', $authorize['url'], 'the browser carries only the reference');
    }

    public function testTheCodeBecomesAGrantKeptEncrypted(): void
    {
        $this->register('client-1');
        $this->http->answer(static fn (): array => ['access_token' => 'access-1', 'refresh_token' => 'refresh-1', 'expires_in' => 900]);

        $this->oauth()->connect('ada', 'code-1', 'verifier-1', self::METADATA['redirect_uri']);

        $token = $this->http->to('/oauth/token')[0]['body'];
        self::assertSame(['authorization_code', 'code-1', 'verifier-1'], [$token['grant_type'], $token['code'], $token['code_verifier']]);
        self::assertArrayNotHasKey('client_id', $token, 'the assertion names the client');
        self::assertSame(FakeCrypto::stored('refresh-1'), $this->config->userValue('ada', 'synaplan_oauth_refresh'));
        self::assertSame(FakeCrypto::stored('access-1'), $this->config->userValue('ada', 'synaplan_oauth_access'));
        self::assertTrue($this->oauth()->isConnected('ada'));
        self::assertSame('access-1', $this->oauth()->accessToken('ada'));
    }

    public function testAnExpiredAccessTokenIsRefreshedAndTheRotatedRefreshTokenKept(): void
    {
        $this->register('client-1');
        $this->connect('ada', 'access-old', -60);
        $this->http->answer(static fn (): array => ['access_token' => 'access-new', 'refresh_token' => 'refresh-2', 'expires_in' => 900]);

        self::assertSame('access-new', $this->oauth()->accessToken('ada'));

        $refresh = $this->http->to('/oauth/token')[0]['body'];
        self::assertSame(['refresh_token', 'refresh-1'], [$refresh['grant_type'], $refresh['refresh_token']]);
        self::assertSame(FakeCrypto::stored('refresh-2'), $this->config->userValue('ada', 'synaplan_oauth_refresh'));
    }

    public function testAGrantSynaplanEndedIsForgotten(): void
    {
        $this->register('client-1');
        $this->connect('ada', 'access-old', -60);
        $this->http->answer(static fn (): IResponse => RecordingHttp::response(400, '{"error":"invalid_grant"}'));

        self::assertSame('', $this->oauth()->accessToken('ada'));
        self::assertFalse($this->oauth()->isConnected('ada'));
    }

    public function testAnUnansweredRefreshIsAskedOnceMoreAndAPassingFailureKeepsTheGrant(): void
    {
        $this->register('client-1');
        $this->connect('ada', 'access-old', -60);
        $this->http->answer(static fn (): \Throwable => new \RuntimeException('cURL error 28'));

        self::assertSame('', $this->oauth()->accessToken('ada'));
        self::assertCount(2, $this->http->to('/oauth/token'));
        self::assertTrue($this->oauth()->isConnected('ada'));
    }

    public function testAGrantOfAnEarlierRegistrationCountsAsNone(): void
    {
        $this->register('client-1');
        $this->connect('ada');
        $this->register('client-2');

        self::assertFalse($this->oauth()->isConnected('ada'));
        self::assertSame('', $this->oauth()->accessToken('ada'));
    }

    public function testDisconnectingRevokesTheGrantAtSynaplan(): void
    {
        $this->register('client-1');
        $this->connect('ada');

        $this->oauth()->revoke('ada');

        self::assertSame('refresh-1', $this->http->to('/oauth/revoke')[0]['body']['token']);
        self::assertFalse($this->oauth()->isConnected('ada'));
    }

    public function testASynaplanThatNoLongerAcceptsThisServerStillLetsThePersonLeave(): void
    {
        $this->register('client-1');
        $this->connect('ada');
        $this->http->answer(static fn (): IResponse => RecordingHttp::response(401, '{"error":"invalid_client"}'));

        $this->oauth()->revoke('ada');

        self::assertFalse($this->oauth()->isConnected('ada'));
    }

    public function testAnUnreachableSynaplanKeepsTheGrantSoDisconnectingCanBeRetried(): void
    {
        $this->register('client-1');
        $this->connect('ada');
        $this->http->answer(static fn (): \Throwable => new \RuntimeException('cURL error 7'));

        try {
            $this->oauth()->revoke('ada');
            self::fail('An unreachable Synaplan must be reported');
        } catch (PlatformLinkException $e) {
            self::assertSame(PlatformLinkException::CODE_UNREACHABLE, $e->getErrorCode());
        }
        self::assertTrue($this->oauth()->isConnected('ada'));
    }

    public function testACallAboutTheRegistrationIsSignedForThatEndpoint(): void
    {
        $this->register('client-1');

        $this->oauth()->updateConfiguration(['delivery_url' => '']);

        $call = $this->http->to('/oauth/register/client-1')[0];
        self::assertSame('PUT', $call['method']);
        $claims = $this->verifiedClaims(substr($call['options']['headers']['Authorization'], strlen('Bearer ')));
        self::assertSame(self::SYNAPLAN . '/oauth/register/client-1', $claims['aud']);
        self::assertLessThanOrEqual(time() + 60, $claims['exp']);
    }

    /**
     * @return array<string, mixed>
     */
    private function verifiedClaims(string $jwt): array
    {
        [$header, $payload, $signature] = explode('.', $jwt);
        $decode = static fn (string $part): string => (string) base64_decode(strtr($part, '-_', '+/'), true);
        $private = openssl_pkey_get_private(self::clientKey());
        self::assertNotFalse($private);
        $public = openssl_pkey_get_details($private);
        self::assertIsArray($public);
        self::assertSame(1, openssl_verify($header . '.' . $payload, $decode($signature), $public['key'], OPENSSL_ALGO_SHA256));
        self::assertSame(['alg' => 'RS256', 'typ' => 'JWT', 'kid' => 'kid-1'], json_decode($decode($header), true));

        return (array) json_decode($decode($payload), true);
    }
}
