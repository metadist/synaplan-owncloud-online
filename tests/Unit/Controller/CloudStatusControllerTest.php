<?php

declare(strict_types=1);

namespace OCA\OcoSynaplan\Tests\Unit\Controller;

use OCA\OcoSynaplan\Controller\CloudStatusController;
use OCA\OcoSynaplan\Service\KeyStorage;
use OCA\OcoSynaplan\Tests\Unit\Support\BuildsServices;
use OCA\OcoSynaplan\Tests\Unit\Support\FakeCrypto;
use OCA\OcoSynaplan\Tests\Unit\Support\RecordingHttp;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Http\Client\IResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

final class CloudStatusControllerTest extends TestCase
{
    use BuildsServices;

    private const GLYPHS = ['bird' => null, 'bird_eyes' => null];

    /** @var list<string> */
    private array $offers = ['deliveries', 'cloud_status'];

    /** @var list<array<string, mixed>> */
    private array $states = [];

    /** @var list<array<string, mixed>> */
    private array $recent = [];

    /** @var array<int, Node> */
    private array $nodes = [];

    protected function setUp(): void
    {
        $this->setUpServices(['mode' => 'provision']);
        $this->signIn($this->person('ada'));
        $this->config->setUserValue('ada', 'oco_synaplan', KeyStorage::USER_KEY, FakeCrypto::stored('sk_ada'));
        $this->offers = ['deliveries', 'cloud_status'];
        $this->states = [];
        $this->recent = [];
        $this->nodes = [];
        $this->http->answer(fn (string $method, string $path): array|IResponse => match ($path) {
            '/.well-known/synaplan-configuration' => ['issuer' => self::SYNAPLAN, 'capabilities' => $this->offers],
            '/api/v1/cloud/status' => ['items' => $this->states, 'watches' => []],
            '/api/v1/cloud/files/42' => ['id' => 42, 'state' => 'searchable', 'knowledge_folder' => ['key' => 'Contracts', 'name' => 'Contracts'], 'kept_up_to_date' => true],
            '/api/v1/files' => ['files' => $this->recent],
            '/api/v1/config/runtime' => ['cloud_files' => ['glyphs' => self::GLYPHS]],
            default => RecordingHttp::response(404, '{"error":"not_found"}'),
        });
    }

    public function testTheFilesOfAFolderViewAreAskedInOneRequestWithOnlyTheIdsThatFit(): void
    {
        $this->states = [['id' => '7', 'state' => 'searchable']];

        $answer = $this->controller(['items' => [
            ['id' => ' 7 ', 'version' => '"e1"'],
            ['id' => '', 'version' => 'x'],
            ['id' => str_repeat('1', 256), 'version' => 'x'],
            ['id' => '8', 'version' => str_repeat('e', 256)],
            'not an item',
        ]])->status()->getData();

        self::assertSame([
            'success' => true,
            'enabled' => true,
            'unreachable' => false,
            'items' => [['id' => '7', 'state' => 'searchable']],
            'glyphs' => self::GLYPHS,
            'openSynaplanUrl' => self::SYNAPLAN . '/files',
        ], $answer);
        self::assertSame([['id' => '7', 'version' => '"e1"']], $this->http->to('/api/v1/cloud/status')[0]['body']['items']);
    }

    public function testAtMostFiveHundredFilesAreAskedAtOnce(): void
    {
        $items = array_map(static fn (int $id): array => ['id' => (string) $id, 'version' => 'e'], range(1, 600));

        $this->controller(['items' => $items])->status();

        self::assertCount(500, $this->http->to('/api/v1/cloud/status')[0]['body']['items']);
    }

    public function testNothingIsShownWhereSynaplanTellsNoFileState(): void
    {
        $this->offers = ['deliveries', 'pickups', 'read_scope'];
        $this->nodes[7] = $this->file(7, 'plan.docx');

        self::assertSame(['success' => true, 'enabled' => false, 'items' => []], $this->controller(['items' => [['id' => '7', 'version' => 'e']]])->status()->getData());
        self::assertSame(['success' => true, 'enabled' => false], $this->controller()->detail(7)->getData());
        self::assertSame(['success' => true, 'files' => []], $this->controller()->searchable()->getData());
        self::assertSame([], $this->http->to('/api/v1/cloud/status'));
        self::assertSame([], $this->http->to('/api/v1/files'));
    }

    public function testNothingIsAskedWithoutFilesOrAPerson(): void
    {
        self::assertFalse($this->controller(['items' => []])->status()->getData()['enabled']);
        $this->signIn(null);
        self::assertFalse($this->controller(['items' => [['id' => '7', 'version' => 'e']]])->status()->getData()['enabled']);
        self::assertSame([], $this->http->calls);
    }

    public function testTheSynaplanTabTellsWhatSynaplanMadeOfTheFile(): void
    {
        $this->nodes[7] = $this->file(7, 'plan.docx');
        $this->states = [['id' => '7', 'state' => 'searchable', 'file_id' => 42]];

        $answer = $this->controller()->detail(7)->getData();

        self::assertSame([
            'success' => true,
            'enabled' => true,
            'unreachable' => false,
            'kind' => 'file',
            'fileId' => 7,
            'name' => 'plan.docx',
            'modified' => 1_800_000_000,
            'state' => 'searchable',
            'knowledgeFolder' => 'Contracts',
            'keptUpToDate' => true,
            'synaplanFileId' => 42,
            'glyphs' => self::GLYPHS,
            'openSynaplanUrl' => self::SYNAPLAN . '/files',
        ], $answer);
        self::assertSame([['id' => '7', 'version' => '"e7"']], $this->http->to('/api/v1/cloud/status')[0]['body']['items']);
    }

    public function testAFileSynaplanDoesNotHoldIsNotSearchable(): void
    {
        $this->nodes[7] = $this->file(7, 'plan.docx');
        $this->states = [['id' => '7', 'state' => 'none']];

        $answer = $this->controller()->detail(7)->getData();

        self::assertSame(['none', null, false, null], [$answer['state'], $answer['knowledgeFolder'], $answer['keptUpToDate'], $answer['synaplanFileId']]);
        self::assertSame([], $this->http->to('/api/v1/cloud/files/42'));
    }

    public function testAFileThePersonCannotSeeIsNotFound(): void
    {
        $response = $this->controller()->detail(7);

        self::assertSame(404, $response->getStatus());
        self::assertSame([], $this->http->to('/api/v1/cloud/status'));
    }

    public function testASynaplanThatDoesNotAnswerIsShownAsUnreachable(): void
    {
        $this->nodes[7] = $this->file(7, 'plan.docx');
        $this->http->answer(fn (string $method, string $path): array|IResponse => $path === '/.well-known/synaplan-configuration'
            ? ['issuer' => self::SYNAPLAN, 'capabilities' => $this->offers]
            : RecordingHttp::response(503, ''));

        self::assertSame(['success' => true, 'enabled' => true, 'unreachable' => true], $this->controller()->detail(7)->getData());
        self::assertTrue($this->controller(['items' => [['id' => '7', 'version' => 'e']]])->status()->getData()['unreachable']);
    }

    public function testTheSearchableListHoldsThePersonsFilesFromHereThatSynaplanCanSearch(): void
    {
        $this->nodes = [7 => $this->file(7, 'plan.docx'), 8 => $this->file(8, 'draft.docx'), 9 => $this->createStub(Folder::class)];
        $this->recent = [
            ['id' => 42, 'source' => 'owncloud', 'source_id' => '7', 'group_key' => 'Contracts'],
            ['id' => 43, 'source' => 'owncloud', 'source_id' => '8', 'group_key' => 'DEFAULT'],
            ['id' => 44, 'source' => 'owncloud', 'source_id' => '7', 'group_key' => 'Contracts'],
            ['id' => 45, 'source' => 'nextcloud', 'source_id' => '7'],
            ['id' => 46, 'source' => 'owncloud', 'source_id' => 'x7'],
            ['id' => 47, 'source' => 'owncloud', 'source_id' => '9'],
            ['id' => 48, 'source' => 'owncloud', 'source_id' => '10'],
            ['id' => 49, 'source' => 'owncloud'],
        ];
        $this->states = [['id' => '7', 'state' => 'searchable'], ['id' => '8', 'state' => 'none']];

        $answer = $this->controller()->searchable()->getData();

        self::assertSame([[
            'id' => 42,
            'fileId' => 7,
            'name' => 'plan.docx',
            'path' => 'Projects/plan.docx',
            'knowledgeFolder' => 'Contracts',
            'openUrl' => '/index.php/route/files.viewcontroller.showFile?fileId=7',
        ]], $answer['files']);
        parse_str((string) parse_url($this->http->to('/api/v1/files')[0]['url'], PHP_URL_QUERY), $query);
        self::assertSame(['page' => '1', 'limit' => '100', 'source' => 'owncloud'], $query);
        self::assertSame([['id' => '7', 'version' => '"e7"'], ['id' => '8', 'version' => '"e8"']], $this->http->to('/api/v1/cloud/status')[0]['body']['items']);
    }

    public function testRowsWithoutTheFileOfThisServerListNothing(): void
    {
        $this->recent = [['id' => 42, 'source' => 'owncloud', 'source_id' => null, 'group_key' => 'DEFAULT']];

        self::assertSame([], $this->controller()->searchable()->getData()['files']);
        self::assertSame([], $this->http->to('/api/v1/cloud/status'));
    }

    /**
     * @param array<string, mixed> $params
     */
    private function controller(array $params = []): CloudStatusController
    {
        $request = $this->createStub(IRequest::class);
        $request->method('getParam')->willReturnCallback(static fn (string $key): mixed => $params[$key] ?? null);
        $userFolder = $this->createStub(Folder::class);
        $userFolder->method('getById')->willReturnCallback(fn (int $id): array => isset($this->nodes[$id]) ? [$this->nodes[$id]] : []);
        $userFolder->method('getRelativePath')->willReturnCallback(static fn (string $path): string => (string) preg_replace('#^/ada/files#', '', $path));
        $root = $this->createStub(IRootFolder::class);
        $root->method('getUserFolder')->willReturn($userFolder);

        return new CloudStatusController($request, $this->cloudState(), $this->client(), $this->synaplanConfig(), $root, $this->session(), $this->urls());
    }

    private function file(int $id, string $name): File
    {
        $file = $this->createStub(File::class);
        $file->method('getId')->willReturn($id);
        $file->method('getName')->willReturn($name);
        $file->method('getPath')->willReturn('/ada/files/Projects/' . $name);
        $file->method('getEtag')->willReturn('"e' . $id . '"');
        $file->method('getMTime')->willReturn(1_800_000_000);

        return $file;
    }
}
