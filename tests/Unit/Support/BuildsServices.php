<?php

declare(strict_types=1);

namespace OCA\OcoSynaplan\Tests\Unit\Support;

use Doctrine\DBAL\Cache\ArrayResult;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Result;
use OCA\OcoSynaplan\Service\CloudStateService;
use OCA\OcoSynaplan\Service\KeyStorage;
use OCA\OcoSynaplan\Service\NotifyKeyCache;
use OCA\OcoSynaplan\Service\OAuthClient;
use OCA\OcoSynaplan\Service\PlatformLinkService;
use OCA\OcoSynaplan\Service\ReadScope;
use OCA\OcoSynaplan\Service\SynaplanClient;
use OCA\OcoSynaplan\Service\SynaplanConfig;
use OCA\OcoSynaplan\Service\UserAccountService;
use OCP\App\IAppManager;
use OCP\DB\QueryBuilder\IExpressionBuilder;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\IRootFolder;
use OCP\IDBConnection;
use OCP\ILogger;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserSession;
use OCP\Lock\ILockingProvider;

/**
 * The app's services wired as the server wires them, over settings in
 * memory and an HTTP client that records each request. Use it from a
 * TestCase; call setUpServices() first.
 */
trait BuildsServices
{
    protected MemoryConfig $config;
    protected RecordingHttp $http;
    protected MemoryCache $cache;
    protected ?IUser $signedIn = null;
    /** Grants every lock at once when a test sets none. */
    protected ?ILockingProvider $locking = null;
    /** Everyone's files; finds no folder when a test sets none. */
    protected ?IRootFolder $root = null;

    /** @var array<string, object> */
    private array $built = [];

    public const SYNAPLAN = 'http://synaplan.example.org';
    public const OWNCLOUD = 'http://localhost:8083';

    /**
     * @param array<string, string> $app values of the Synaplan app
     * @param (callable(string, string, array<string, mixed>): mixed)|null $responder
     */
    protected function setUpServices(array $app = [], ?callable $responder = null): void
    {
        $this->config = new MemoryConfig($app + ['synaplan_url' => self::SYNAPLAN]);
        $this->http = new RecordingHttp($responder);
        $this->cache = new MemoryCache();
        $this->signedIn = null;
        $this->locking = null;
        $this->root = null;
        $this->built = [];
    }

    protected function person(string $uid, ?string $email = null): IUser
    {
        $user = $this->createStub(IUser::class);
        $user->method('getUID')->willReturn($uid);
        $user->method('getEMailAddress')->willReturn($email ?? $uid . '@example.org');
        $user->method('getDisplayName')->willReturn(ucfirst($uid));

        return $user;
    }

    protected function signIn(?IUser $user): void
    {
        $this->signedIn = $user;
    }

    protected function session(): IUserSession
    {
        $session = $this->createStub(IUserSession::class);
        $session->method('getUser')->willReturnCallback(fn (): ?IUser => $this->signedIn);

        return $session;
    }

    protected function logger(): ILogger
    {
        return $this->createStub(ILogger::class);
    }

    protected function keys(): KeyStorage
    {
        return $this->built['keys'] ??= new KeyStorage($this->config, new FakeCrypto(), $this->logger());
    }

    protected function synaplanConfig(): SynaplanConfig
    {
        return $this->built['config'] ??= new SynaplanConfig($this->config, $this->keys());
    }

    protected function oauth(): OAuthClient
    {
        return $this->built['oauth'] ??= new OAuthClient(
            $this->http,
            $this->config,
            $this->db(),
            $this->locks(),
            $this->keys(),
            $this->synaplanConfig(),
            $this->logger(),
        );
    }

    protected function notifyKeys(): NotifyKeyCache
    {
        return $this->built['notifyKeys'] ??= new NotifyKeyCache($this->config, $this->db(), $this->locks(), $this->links());
    }

    protected function locks(): ILockingProvider
    {
        return $this->locking ??= $this->createStub(ILockingProvider::class);
    }

    protected function accounts(): UserAccountService
    {
        return $this->built['accounts'] ??= new UserAccountService(
            $this->http,
            $this->config,
            $this->session(),
            $this->synaplanConfig(),
            $this->logger(),
            $this->keys(),
            $this->oauth(),
        );
    }

    protected function client(): SynaplanClient
    {
        return $this->built['client'] ??= new SynaplanClient($this->http, $this->logger(), $this->synaplanConfig(), $this->accounts(), $this->cache);
    }

    protected function links(): PlatformLinkService
    {
        return $this->built['links'] ??= new PlatformLinkService(
            $this->http,
            $this->config,
            $this->urls(),
            $this->oauth(),
            $this->appManager(),
            $this->synaplanConfig(),
            $this->accounts(),
            $this->logger(),
            $this->cache,
        );
    }

    protected function cloudState(): CloudStateService
    {
        return $this->built['cloudState'] ??= new CloudStateService(
            $this->http,
            $this->synaplanConfig(),
            $this->client(),
            $this->links(),
            $this->cache,
            $this->logger(),
        );
    }

    protected function readScope(): ReadScope
    {
        return $this->built['readScope'] ??= new ReadScope(
            $this->config,
            $this->db(),
            $this->links(),
            $this->root ??= $this->createStub(IRootFolder::class),
            $this->locks(),
        );
    }

    protected function urls(): IURLGenerator
    {
        $urls = $this->createStub(IURLGenerator::class);
        $urls->method('linkTo')->willReturnCallback(
            static fn (string $app, string $file): string => '/apps/' . $app . '/' . $file,
        );
        $urls->method('getAbsoluteURL')->willReturnCallback(
            static fn (string $path): string => self::OWNCLOUD . $path,
        );
        $urls->method('linkToRoute')->willReturnCallback(
            static fn (string $route, array $parameters = []): string => '/index.php/route/' . $route . ($parameters === [] ? '' : '?' . http_build_query($parameters)),
        );
        $urls->method('linkToRouteAbsolute')->willReturnCallback(
            static fn (string $route, array $parameters = []): string => self::OWNCLOUD . '/index.php/route/' . $route . ($parameters === [] ? '' : '?' . http_build_query($parameters)),
        );

        return $urls;
    }

    protected function appManager(): IAppManager
    {
        $apps = $this->createStub(IAppManager::class);
        $apps->method('getAppInfo')->willReturn(['id' => 'oco_synaplan', 'version' => '1.1.0']);

        return $apps;
    }

    /**
     * Registered with Synaplan as an OAuth client: a client id and a real key,
     * so assertions are signed.
     */
    protected function register(string $clientId = 'client-1'): void
    {
        $this->keys()->setAppKey('oauth_private_key', self::clientKey());
        $this->config->setAppValue('oco_synaplan', 'oauth_kid', 'kid-1');
        $this->config->setAppValue('oco_synaplan', 'link_instance_id', $clientId);
        $this->config->setAppValue('oco_synaplan', 'notify_issuer', self::SYNAPLAN);
    }

    /**
     * One RSA key for the whole run: making one takes a while.
     */
    protected static function clientKey(): string
    {
        static $private = null;
        if ($private === null) {
            $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            self::assertNotFalse($key);
            openssl_pkey_export($key, $private);
        }

        return $private;
    }

    /**
     * A person who connected their Synaplan account: a grant of this client.
     */
    protected function connect(string $uid, string $access = 'access-1', int $expiresIn = 900): void
    {
        $this->keys()->setUserSecret($uid, 'synaplan_oauth_refresh', 'refresh-1');
        $this->keys()->setUserSecret($uid, 'synaplan_oauth_access', $access);
        $this->config->setUserValue($uid, 'oco_synaplan', 'synaplan_oauth_expires', (string) (time() + $expiresIn));
        $this->config->setUserValue($uid, 'oco_synaplan', 'synaplan_oauth_client', (string) $this->config->appValue('link_instance_id'));
    }

    /**
     * The database as the app reads it: the preferences and appconfig
     * tables, from the settings in memory.
     */
    protected function db(): IDBConnection
    {
        $db = $this->createStub(IDBConnection::class);
        $db->method('getQueryBuilder')->willReturnCallback(fn (): IQueryBuilder => $this->settingsQuery());

        return $db;
    }

    private function settingsQuery(): IQueryBuilder
    {
        $table = '';
        $columns = [];
        $parameters = [];
        $query = $this->createStub(IQueryBuilder::class);
        $query->method('select')->willReturnCallback(function (mixed $select = null) use (&$columns, $query): IQueryBuilder {
            $columns = is_array($select) ? $select : func_get_args();

            return $query;
        });
        $query->method('from')->willReturnCallback(function (string $from) use (&$table, $query): IQueryBuilder {
            $table = $from;

            return $query;
        });
        foreach (['where', 'andWhere'] as $method) {
            $query->method($method)->willReturnSelf();
        }
        $query->method('expr')->willReturn($this->createStub(IExpressionBuilder::class));
        $query->method('createNamedParameter')->willReturnCallback(function (mixed $value) use (&$parameters): string {
            $parameters[] = (string) $value;

            return ':param' . count($parameters);
        });
        $query->method('execute')->willReturnCallback(function () use (&$table, &$columns, &$parameters): Result {
            if ($table === 'appconfig') {
                [$app, $key] = $parameters + ['', ''];
                $value = $this->config->app[$app][$key] ?? null;
                $rows = $value === null ? [] : [['configvalue' => $value]];
            } elseif (in_array('userid', $columns, true)) {
                [$app, $key] = $parameters + ['', ''];
                $rows = [];
                foreach ($this->config->user as $uid => $apps) {
                    if (isset($apps[$app][$key])) {
                        $rows[] = ['userid' => (string) $uid, 'configvalue' => $apps[$app][$key]];
                    }
                }
            } else {
                [$uid, $app, $key] = $parameters + ['', '', ''];
                $value = $this->config->user[$uid][$app][$key] ?? null;
                $rows = $value === null ? [] : [['configvalue' => $value]];
            }

            return new Result(new ArrayResult($rows), $this->createStub(Connection::class));
        });

        return $query;
    }
}
