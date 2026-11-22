<?php

declare(strict_types=1);

namespace OCA\OcoSynaplan\Tests\Contract;

use OCA\OcoSynaplan\AppInfo\Application;
use OCA\OcoSynaplan\Controller\ChatController;
use OCA\OcoSynaplan\Http\SseProxyResponse;
use OCA\OcoSynaplan\Listener\UserDeletedListener;
use OCA\OcoSynaplan\Service\KeyStorage;
use OCA\OcoSynaplan\Service\LanguageService;
use OCA\OcoSynaplan\Service\OAuthClient;
use OCA\OcoSynaplan\Service\SynaplanClient;
use OCA\OcoSynaplan\Service\SynaplanCredential;
use OCA\OcoSynaplan\Service\UserAccountService;
use OCA\OcoSynaplan\Tests\Unit\Support\BuildsServices;
use OCA\OcoSynaplan\Tests\Unit\Support\FakeCrypto;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

/**
 * Every call the app makes to Synaplan, checked against the Synaplan pinned in
 * .github/workflows/ci.yml: the route and method exist, required fields are
 * sent, no undeclared field is sent and enum values are accepted. A call the
 * pinned Synaplan lacks fails here instead of for a person.
 *
 * Spec and routes come from scripts/synaplan-openapi.sh (make test-contract).
 */
final class SynaplanContractTest extends TestCase
{
    use BuildsServices;

    private const CACHE = __DIR__ . '/.cache';

    private const CLOUD_APPS_CONTRACT = __DIR__ . '/../fixtures/synaplan-contract/paths.json';

    /**
     * Calls made with a person's credential that contract v1 (the vendored
     * paths.json) does not list yet. Both answer on the pinned Synaplan.
     */
    private const OUTSIDE_THE_CONTRACT = [
        'DELETE /api/v1/apikeys/{id}',
        'GET /api/v1/auth/me',
    ];

    private const CALLBACK = 'http://localhost:8083/apps/oco_synaplan/public/link-return.html';

    /** A body every caller can read far enough to make its next call. */
    private const RESPONSE = [
        'success' => true,
        'status' => 'ok',
        'user' => ['id' => 7],
        'api_key' => ['id' => 11, 'key' => 'sk_contract'],
        'results' => [],
        'files' => [['id' => 42]],
        'file' => ['url' => '/api/v1/files/uploads/7/cat.png', 'type' => 'image', 'mimeType' => 'image/png'],
    ];

    /** Lets translate() take the translation mode, so its request is checked too. */
    private const CAPABILITIES = [
        'summary' => ['types' => ['abstractive', 'extractive', 'bullet-points', 'translation']],
    ];

    public function testEveryCallIsAcceptedByThePinnedSynaplan(): void
    {
        $this->callEverything();
        $contract = $this->contract();

        $violations = [];
        foreach ($this->http->calls as $call) {
            array_push($violations, ...$contract->violations($call['method'], $call['path'], $call['body'], $call['multipart'], $call['form']));
        }

        self::assertSame([], array_values(array_unique($violations)));
    }

    public function testEveryOperationTheAppNamesIsCalled(): void
    {
        $this->callEverything();

        $missing = [];
        foreach (self::operationsInLib() as $operation) {
            $hit = false;
            [$method, $template] = explode(' ', $operation, 2);
            foreach ($this->http->calls as $call) {
                if (OpenApiContract::operationCalled($method, $template, $call['method'], $call['path'])) {
                    $hit = true;
                    break;
                }
            }
            if (!$hit) {
                $missing[] = $operation;
            }
        }

        self::assertSame([], $missing, 'Add a call to callEverything() for each Synaplan operation.');
    }

    public function testEveryOAuthCallIsMade(): void
    {
        $this->callEverything();
        $made = array_map(static fn (array $call): string => $call['method'] . ' ' . $call['path'], $this->http->calls);

        foreach ([
            'GET /.well-known/synaplan-configuration',
            'POST /oauth/register',
            'GET /oauth/register/client-1',
            'PUT /oauth/register/client-1',
            'POST /oauth/register/client-1/lifecycle',
            'DELETE /oauth/register/client-1',
            'POST /oauth/par',
            'POST /oauth/token',
            'POST /oauth/revoke',
        ] as $operation) {
            self::assertContains($operation, $made);
        }
    }

    public function testEveryCallWithAPersonsCredentialIsInTheCloudAppsContract(): void
    {
        $this->callEverything();
        $contract = json_decode((string) file_get_contents(self::CLOUD_APPS_CONTRACT), true);
        self::assertIsArray($contract);
        $granted = explode(' ', OAuthClient::SCOPE . ' memories');

        $outside = [];
        foreach ($this->http->calls as $call) {
            if (!$this->withPersonsCredential($call)) {
                continue;
            }
            $entry = null;
            foreach ([...$contract['paths'], ...$contract['anyKey']] as $candidate) {
                if (self::inContract($candidate['method'], $candidate['path'], $call['method'], $call['path'])) {
                    $entry = $candidate;
                    break;
                }
            }
            if ($entry === null) {
                $outside[] = $call['method'] . ' ' . (string) preg_replace('#/\d+(?=/|$)#', '/{id}', $call['path']);
                continue;
            }
            $scopes = $entry['scopes'] ?? [];
            self::assertTrue($scopes === [] || array_intersect($scopes, $granted) !== [], "{$call['method']} {$call['path']} needs one of: " . implode(', ', $scopes));
        }

        $outside = array_values(array_unique($outside));
        sort($outside);
        self::assertSame(self::OUTSIDE_THE_CONTRACT, $outside);
    }

    public function testASelectedModelIsSentToTheChatEndpoint(): void
    {
        $this->callEverything();
        $selected = array_filter(
            $this->http->calls,
            static fn (array $call): bool => $call['path'] === '/v1/chat/completions'
                && ($call['body']['model'] ?? null) === 'gpt-oss-120b',
        );

        self::assertNotEmpty($selected, 'callEverything() must exercise a selected model.');
    }

    public function testTheTranslationRequestIsChecked(): void
    {
        $this->callEverything();
        $translations = array_filter(
            $this->http->calls,
            static fn (array $call): bool => $call['path'] === '/api/v1/summary/generate'
                && ($call['body']['summaryType'] ?? null) === 'translation',
        );

        self::assertNotEmpty($translations, 'callEverything() must exercise the translation mode.');
    }

    public function testNestedItemFieldsAreChecked(): void
    {
        $contract = $this->contract();
        $renamed = $contract->violations('POST', '/api/v1/files/check-stale', [
            'items' => [['sourceId' => '7', 'source_etag' => null]],
        ], false);
        self::assertNotSame([], $renamed);
        self::assertStringContainsString("sends 'items[0].sourceId'", implode("\n", $renamed));

        self::assertSame([], $contract->violations('POST', '/api/v1/files/check-stale', [
            'source' => 'owncloud',
            'items' => [['source_id' => '7', 'source_etag' => 'etag-1']],
        ], false));
    }

    public function testAFinalParameterDoesNotMatchExtraSegments(): void
    {
        $contract = $this->contract();
        $extra = $contract->violations('DELETE', '/api/v1/files/42/not-a-route', [], false);
        self::assertNotSame([], $extra);
        self::assertStringContainsString('no such route', implode("\n", $extra));

        self::assertSame([], $contract->violations('DELETE', '/api/v1/files/42', [], false));
        self::assertSame([], $contract->violations('GET', '/api/v1/files/uploads/7/cat.png', [], false));
    }

    public function testASharedPrefixDoesNotCoverAnotherOperation(): void
    {
        self::assertFalse(OpenApiContract::operationCalled('DELETE', '/api/v1/files/{id}', 'GET', '/api/v1/files/42/content'));
        self::assertFalse(OpenApiContract::operationCalled('GET', '/api/v1/admin/users/{id}/usage', 'DELETE', '/api/v1/admin/users/42'));
        self::assertTrue(OpenApiContract::operationCalled('DELETE', '/api/v1/files/{id}', 'DELETE', '/api/v1/files/42'));
        self::assertTrue(OpenApiContract::operationCalled('GET', '/api/v1/admin/users/{id}/usage', 'GET', '/api/v1/admin/users/42/usage'));
    }

    private function contract(): OpenApiContract
    {
        return new OpenApiContract(self::CACHE . '/openapi.json', self::CACHE . '/routes.json');
    }

    /**
     * Ada has said yes and has a key this server created; the server is
     * registered for links, and Ada is connected too.
     */
    private function callEverything(): void
    {
        $this->setUpServices(
            ['api_key' => FakeCrypto::stored('sk_admin'), 'mode' => 'provision'],
            static fn (string $method, string $path): array => match ($path) {
                '/api/v1/config/capabilities' => self::CAPABILITIES,
                '/.well-known/synaplan-configuration' => ['issuer' => self::SYNAPLAN],
                '/oauth/register' => ['client_id' => 'client-1', 'status' => 'active'],
                '/oauth/par' => ['request_uri' => 'urn:ietf:params:oauth:request_uri:abc', 'expires_in' => 60],
                '/oauth/token' => ['access_token' => 'access-2', 'refresh_token' => 'refresh-2', 'expires_in' => 900, 'token_type' => 'Bearer'],
                '/api/v1/admin/users/7/usage' => ['user' => ['id' => 7, 'email' => 'ada@example.org', 'external_id' => 'oc1234567890:ada']],
                default => self::RESPONSE,
            },
        );
        $this->register('client-1');
        $ada = $this->person('ada');
        $this->signIn($ada);
        foreach ([
            UserAccountService::CONSENT_PREF => '1',
            KeyStorage::USER_KEY => FakeCrypto::stored('sk_ada'),
            UserAccountService::USER_ACCOUNT_ID_PREF => '7',
            UserAccountService::USER_KEY_ID_PREF => '11',
            UserAccountService::LINK_KIND_PREF => UserAccountService::KIND_PROVISIONED,
        ] as $key => $value) {
            $this->config->setUserValue('ada', Application::APP_ID, $key, $value);
        }

        $client = $this->client();
        $accounts = $this->accounts();
        $links = $this->links();
        $oauth = $this->oauth();

        $scenarios = [
            fn () => $client->healthCheck(),
            fn () => $client->summarize('A long text.'),
            fn () => $client->translate('A long text.', 'de'),
            fn () => $client->createChat('Contract'),
            fn () => $client->searchRagContext('What changed?', 'Contracts'),
            fn () => $this->recordBlockingChat($client),
            fn () => $this->recordChatStream($client),
            fn () => $client->checkMemoryService(),
            fn () => $client->searchMemoryContext('budget'),
            fn () => $client->getModels(),
            fn () => $client->getFileGroups(),
            fn () => $client->getFileContent(42),
            fn () => $client->knowledgeStatus(7, 'etag-1'),
            fn () => $client->deleteKnowledgeFile(42),
            fn () => $client->uploadFile('report.pdf', '%PDF', 'Contracts', 'vectorize', '/Projects/report.pdf', '7', 'etag-1', true),
            fn () => $client->generateMedia('A cat', 'image', 3),
            fn () => $client->downloadFile('/api/v1/files/uploads/7/cat.png'),
            fn () => $accounts->fetchUsage(7),
            fn () => $accounts->adoptLegacyAccount('ada'),
            fn () => $accounts->renewProvisionedKey(),
            fn () => $accounts->provisionAccount($this->person('bob')),
            fn () => $links->register('registration-token'),
            fn () => $links->registrationStatus(),
            fn () => $links->announce(),
            fn () => $oauth->reportLifecycle('ada', 'disabled'),
            fn () => $links->reportLifecycle('ada', 'enabled'),
            fn () => (new UserDeletedListener($accounts, $links))->handle($this->person('ada')),
            fn () => $oauth->authorizeUrl('ada', 'state-1', self::CALLBACK, false),
            fn () => $oauth->connect('ada', 'code-1', 'verifier-1', self::CALLBACK),
            function () use ($oauth): void {
                $this->expireAccessToken('ada');
                $oauth->accessToken('ada');
            },
            fn () => $links->whoAmI('ada'),
            fn () => $oauth->revoke('ada'),
            fn () => $links->disconnectUid('ada'),
            fn () => $links->forgetRegistration(),
        ];
        foreach ($scenarios as $scenario) {
            try {
                $scenario();
            } catch (\Throwable $error) {
                if ($error instanceof \PHPUnit\Framework\AssertionFailedError) {
                    throw $error;
                }
                // The response only has to be readable. testEveryOperationTheAppNamesIsCalled
                // fails when a scenario throws before the request goes out.
            }
        }
    }

    private function recordBlockingChat(SynaplanClient $client): void
    {
        $response = $this->chatController($client)->chat('Which model?', null, null, 'gpt-oss-120b');
        self::assertTrue($response->getData()['success'] ?? false);
    }

    /**
     * The stream is proxied with curl when the response is sent, so its
     * request is read from the response and recorded here.
     */
    private function recordChatStream(SynaplanClient $client): void
    {
        $response = $this->chatController($client)->chatStream('Hello', null, null, 'gpt-oss-120b', false);
        self::assertInstanceOf(SseProxyResponse::class, $response);
        $read = static fn (string $property): string => (string) (new \ReflectionProperty(SseProxyResponse::class, $property))->getValue($response);
        $url = $read('url');
        $body = json_decode($read('payload'), true);
        $this->http->calls[] = [
            'method' => 'POST',
            'url' => $url,
            'path' => (string) parse_url($url, PHP_URL_PATH),
            'options' => ['headers' => SynaplanCredential::headers($read('apiKey'), 'text/event-stream')],
            'body' => is_array($body) ? $body : [],
            'multipart' => false,
            'form' => false,
        ];
    }

    private function chatController(SynaplanClient $client): ChatController
    {
        $language = $this->createStub(LanguageService::class);
        $language->method('resolveLanguage')->willReturn('en');
        $language->method('getLanguageName')->willReturn('English');

        return new ChatController(
            $this->createStub(IRequest::class),
            $client,
            $this->createStub(IRootFolder::class),
            $this->session(),
            $language,
            $this->logger(),
        );
    }

    /**
     * @param array{path: string, options: array<string, mixed>} $call
     */
    private function withPersonsCredential(array $call): bool
    {
        if (preg_match('#^/(api|v1)/#', $call['path']) !== 1) {
            return false;
        }
        $headers = is_array($call['options']['headers'] ?? null) ? $call['options']['headers'] : [];
        $key = (string) ($headers['X-API-Key'] ?? '');

        return ($key !== '' && $key !== 'sk_admin') || str_starts_with((string) ($headers['Authorization'] ?? ''), 'Bearer ');
    }

    /**
     * A contract path with {path} (a stored upload) takes the rest of the
     * path; any other placeholder is one segment.
     */
    private static function inContract(string $method, string $template, string $callMethod, string $callPath): bool
    {
        if (str_ends_with($template, '/{path}')) {
            return $method === $callMethod && str_starts_with($callPath, substr($template, 0, -strlen('{path}')));
        }

        return OpenApiContract::operationCalled($method, $template, $callMethod, $callPath);
    }

    private function expireAccessToken(string $uid): void
    {
        $this->connect($uid, 'access-1', -60);
    }

    /**
     * Method plus full path template for every Synaplan call under lib/.
     * A quoted path that this cannot parse is reported as UNPARSED.
     *
     * @return list<string>
     */
    private static function operationsInLib(): array
    {
        $operations = [];
        $quoted = [];
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(dirname(__DIR__, 2) . '/lib'));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }
            $source = (string) file_get_contents($file->getPathname());
            preg_match_all("#'(/(?:api|v1)/[^'\$]*)'#", $source, $literals);
            array_push($quoted, ...$literals[1]);
            preg_match_all(
                '/->(?:request|adminRequest)\(\s*\'(GET|POST|PUT|PATCH|DELETE|HEAD)\'\s*,\s*/',
                $source,
                $calls,
                PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
            );
            foreach ($calls as $call) {
                $expression = self::readArgument($source, $call[0][1] + strlen($call[0][0]));
                $template = self::templateFromExpression($expression);
                $operations[] = $template === null
                    ? 'UNPARSED ' . $call[1][0] . ' ' . $expression
                    : $call[1][0] . ' ' . $template;
            }
            preg_match_all(
                '/getBaseUrl\(\)\s*\.\s*\'(\/(?:api|v1)\/[^\']*)\'/',
                $source,
                $urls,
                PREG_SET_ORDER | PREG_OFFSET_CAPTURE,
            );
            foreach ($urls as $url) {
                $verb = self::verbBeside($source, $url[0][1]);
                $operations[] = $verb === null ? 'UNPARSED ' . $url[1][0] : $verb . ' ' . $url[1][0];
            }
        }

        $operations = array_values(array_unique($operations));
        foreach (array_unique($quoted) as $literal) {
            $parsed = false;
            foreach ($operations as $operation) {
                $template = explode(' ', $operation, 2)[1] ?? '';
                if (str_starts_with($template, $literal)) {
                    $parsed = true;
                    break;
                }
            }
            if (!$parsed) {
                $operations[] = 'UNPARSED ' . $literal;
            }
        }
        sort($operations);

        return $operations;
    }

    private static function readArgument(string $source, int $offset): string
    {
        $length = strlen($source);
        $depth = 0;
        $quote = null;
        $argument = '';
        for ($i = $offset; $i < $length; $i++) {
            $character = $source[$i];
            if ($quote !== null) {
                $argument .= $character;
                if ($character === '\\' && $i + 1 < $length) {
                    $argument .= $source[++$i];
                    continue;
                }
                if ($character === $quote) {
                    $quote = null;
                }
                continue;
            }
            if ($character === '\'' || $character === '"') {
                $quote = $character;
                $argument .= $character;
                continue;
            }
            if ($character === '(') {
                $depth++;
                $argument .= $character;
                continue;
            }
            if ($character === ')') {
                if ($depth === 0) {
                    break;
                }
                $depth--;
                $argument .= $character;
                continue;
            }
            if ($character === ',' && $depth === 0) {
                break;
            }
            $argument .= $character;
        }

        return trim($argument);
    }

    private static function templateFromExpression(string $expression): ?string
    {
        $template = '';
        $length = strlen($expression);
        for ($i = 0; $i < $length; $i++) {
            $character = $expression[$i];
            if ($character === '\'' || $character === '"') {
                $quote = $character;
                for ($i++; $i < $length && $expression[$i] !== $quote; $i++) {
                    if ($expression[$i] === '\\' && $i + 1 < $length) {
                        $i++;
                    }
                    $template .= $expression[$i];
                }
                continue;
            }
            if (preg_match('/^rawurlencode\s*\(\s*\$(\w+)\s*\)/', substr($expression, $i), $match) === 1) {
                $template .= '{' . $match[1] . '}';
                $i += strlen($match[0]) - 1;
                continue;
            }
            if ($character === '$' && preg_match('/^(\w+)/', substr($expression, $i + 1), $match) === 1) {
                $template .= '{' . $match[1] . '}';
                $i += strlen($match[1]);
            }
        }

        return str_starts_with($template, '/') ? $template : null;
    }

    private static function verbBeside(string $source, int $offset): ?string
    {
        $start = strrpos(substr($source, 0, $offset), 'function ');
        $start = $start === false ? 0 : $start;
        $end = strlen($source);
        if (preg_match('/\n    (?:public|private|protected) function /', $source, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
            $end = $match[0][1];
        }
        $body = substr($source, $start, $end - $start);
        if (str_contains($body, 'new SseProxyResponse')) {
            return 'POST';
        }
        if (preg_match('/->(get|post|put|patch|delete)\(/', $body, $match) === 1) {
            return strtoupper($match[1]);
        }

        return null;
    }
}
