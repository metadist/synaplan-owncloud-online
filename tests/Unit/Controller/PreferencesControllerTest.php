<?php

declare(strict_types=1);

namespace OCA\OcoSynaplan\Tests\Unit\Controller;

use OCA\OcoSynaplan\Controller\PreferencesController;
use OCA\OcoSynaplan\Service\ReadScope;
use OCA\OcoSynaplan\Service\SynaplanFolders;
use OCA\OcoSynaplan\Tests\Unit\Support\BuildsServices;
use OCA\OcoSynaplan\Tests\Unit\Support\RecordingHttp;
use OCP\AppFramework\Http;
use OCP\Http\Client\IResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

final class PreferencesControllerTest extends TestCase
{
    use BuildsServices;

    /** @var array<string, mixed> what the request body carries besides the copy folder */
    private array $params = [];

    protected function setUp(): void
    {
        $this->setUpServices(['mode' => 'link']);
        $this->signIn($this->person('ada'));
        $this->params = [];
        $this->synaplanAnswers(static fn (): array => ['success' => true]);
    }

    public function testTheFolderIsSavedForThePersonAlone(): void
    {
        $saved = $this->controller()->save('/Projects/Q3/')->getData();

        self::assertTrue($saved['success']);
        self::assertSame('Projects/Q3', $saved['copy_folder']);
        self::assertSame('Projects/Q3', $this->config->userValue('ada', SynaplanFolders::COPY_FOLDER));
        self::assertSame('Projects/Q3', $this->controller()->get()->getData()['copy_folder']);

        $this->signIn($this->person('bob'));
        self::assertSame('', $this->controller()->get()->getData()['copy_folder']);
    }

    public function testTheTopOfTheFilesMeansTheSynaplanFolder(): void
    {
        $this->controller()->save('Projects');

        self::assertSame('', $this->controller()->save('/')->getData()['copy_folder']);
    }

    public function testAFolderOutsideThePersonsFilesIsRefused(): void
    {
        $refused = $this->controller()->save('Projects/../../other');

        self::assertSame(Http::STATUS_BAD_REQUEST, $refused->getStatus());
        self::assertNull($this->config->userValue('ada', SynaplanFolders::COPY_FOLDER));
    }

    public function testNobodySignedInHasNoFolder(): void
    {
        $this->signIn(null);

        self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller()->get()->getStatus());
        self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller()->save('Projects')->getStatus());
        self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller()->allowFolder('42', 'HR')->getStatus());
    }

    public function testThePersonSeesTheirReadSettingAndWhatTheAdministratorAllows(): void
    {
        $this->config->setAppValue('oco_synaplan', ReadScope::CEILING, ReadScope::MODE_ALL);
        $this->config->setAppValue('oco_synaplan', ReadScope::CAP, '3');

        self::assertSame([
            'copy_folder' => '',
            'read_scope' => ['mode' => ReadScope::MODE_WATCHED, 'folders' => []],
            'read_folder_cap' => 3,
            'read_scope_ceiling' => ReadScope::MODE_ALL,
            'reads_folders' => true,
        ], $this->controller()->get()->getData());
    }

    public function testSavingTheReadSettingLeavesTheFolderAsItIsAndTellsSynaplan(): void
    {
        $this->register();
        $this->connect('ada');
        $this->controller()->save('Projects');
        $this->params = ['read_scope' => ['mode' => 'allowed', 'folders' => [['id' => '7', 'path' => '/Invoices/2026/']]]];

        $saved = $this->controller()->save()->getData();

        $scope = ['mode' => ReadScope::MODE_ALLOWED, 'folders' => [['id' => '', 'path' => 'Invoices/2026']]];
        self::assertSame('Projects', $saved['copy_folder']);
        self::assertSame($scope, $saved['read_scope'], 'the id is the one this server finds, never the one sent');
        $put = $this->http->to('/api/v1/platform-links/read-scope');
        self::assertCount(1, $put);
        self::assertSame(['external_id' => 'ada'] + $scope, $put[0]['body']);
        self::assertNull($this->config->userValue('ada', ReadScope::PENDING));
    }

    public function testAReadSettingSynaplanDidNotTakeWaitsForTheHourlyJob(): void
    {
        $this->register();
        $this->connect('ada');
        $this->synaplanAnswers(static fn (): IResponse => RecordingHttp::response(503, ''));
        $this->params = ['read_scope' => ['mode' => 'all', 'folders' => []]];

        $saved = $this->controller()->save()->getData();

        self::assertSame(ReadScope::MODE_ALLOWED, $saved['read_scope']['mode'], 'never wider than the administrator allows');
        self::assertSame('1', $this->config->userValue('ada', ReadScope::PENDING));
    }

    public function testTheFolderMenuNamesAFolderAndLetsSynaplanReadIt(): void
    {
        $allowed = $this->controller()->allowFolder('42', 'HR/Contracts');

        self::assertSame(Http::STATUS_OK, $allowed->getStatus());
        self::assertSame(
            ['mode' => ReadScope::MODE_ALLOWED, 'folders' => [['id' => '', 'path' => 'HR/Contracts']]],
            $allowed->getData()['read_scope'],
        );
        self::assertTrue($this->readScope()->mayRead('ada', 'HR/Contracts/2026'));
    }

    public function testTheFolderMenuStopsAtTheAdministratorsLimits(): void
    {
        $this->config->setAppValue('oco_synaplan', ReadScope::CAP, '1');
        $this->controller()->allowFolder('42', 'HR');

        $full = $this->controller()->allowFolder('43', 'Invoices');

        self::assertSame(Http::STATUS_CONFLICT, $full->getStatus());
        self::assertSame('cap', $full->getData()['error']);
        self::assertSame(['HR'], array_column($full->getData()['read_scope']['folders'], 'path'));

        $this->config->setAppValue('oco_synaplan', ReadScope::CEILING, ReadScope::MODE_WATCHED);
        $closed = $this->controller()->allowFolder('43', 'Invoices');

        self::assertSame(Http::STATUS_FORBIDDEN, $closed->getStatus());
        self::assertSame(['success' => false, 'error' => 'ceiling'], $closed->getData());
    }

    public function testAFolderNameOutsideThePersonsFilesIsRefused(): void
    {
        foreach (['', '/', 'HR/../../bob'] as $path) {
            $refused = $this->controller()->allowFolder('42', $path);

            self::assertSame(Http::STATUS_BAD_REQUEST, $refused->getStatus(), $path);
            self::assertSame('folder', $refused->getData()['error'], $path);
        }
        self::assertSame([], $this->readScope()->get('ada')['folders']);
    }

    public function testASynaplanThatReadsNoFoldersGetsNoReadSettingAndTheSavedOneStays(): void
    {
        $this->register();
        $this->connect('ada');
        $this->controller()->allowFolder('42', 'HR');
        $this->cache->values = [];
        $this->http->answer(static fn (string $method, string $path): array|IResponse => $path === '/.well-known/synaplan-configuration'
            ? RecordingHttp::response(404, '{"error":"not_found"}')
            : ['success' => true]);
        $this->http->calls = [];

        self::assertFalse($this->controller()->get()->getData()['reads_folders']);

        $refused = $this->controller()->allowFolder('43', 'Invoices');
        self::assertSame(Http::STATUS_CONFLICT, $refused->getStatus());
        self::assertSame(['success' => false, 'error' => 'unavailable'], $refused->getData());

        $this->params = ['read_scope' => ['mode' => 'all', 'folders' => []]];
        $refused = $this->controller()->save('Projects');
        self::assertSame(Http::STATUS_CONFLICT, $refused->getStatus());
        self::assertSame(['success' => false, 'error' => 'unavailable'], $refused->getData());

        self::assertSame(
            ['mode' => ReadScope::MODE_ALLOWED, 'folders' => [['id' => '', 'path' => 'HR']]],
            $this->readScope()->get('ada'),
        );
        self::assertSame('', $this->controller()->get()->getData()['copy_folder'], 'the refused save changed nothing');
        self::assertSame([], $this->http->to('/api/v1/platform-links/read-scope'));

        $this->params = [];
        self::assertSame('Projects', $this->controller()->save('Projects')->getData()['copy_folder'], 'the copy folder needs no folder reads');
    }

    /**
     * Synaplan offers folder reads and the read setting, and answers every
     * other call with the responder.
     *
     * @param callable(string, string, array<string, mixed>): (array<mixed>|IResponse|\Throwable) $responder
     */
    private function synaplanAnswers(callable $responder): void
    {
        $this->http->answer(static fn (string $method, string $path, array $options): array|IResponse|\Throwable => $path === '/.well-known/synaplan-configuration'
            ? [
                'issuer' => 'https://synaplan.example',
                'jwks_uri' => 'https://synaplan.example/.well-known/jwks.json',
                'capabilities' => ['deliveries', 'pickups', 'read_scope'],
            ]
            : $responder($method, $path, $options));
    }

    private function controller(): PreferencesController
    {
        $request = $this->createStub(IRequest::class);
        $request->method('getParam')->willReturnCallback(
            fn (string $key, mixed $default = null): mixed => $this->params[$key] ?? $default,
        );

        return new PreferencesController($request, $this->config, $this->session(), $this->readScope(), $this->links());
    }
}
