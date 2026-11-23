<?php

declare(strict_types=1);

namespace OCA\OcoSynaplan\Tests\Unit\Controller;

use OCA\OcoSynaplan\Controller\ApiController;
use OCA\OcoSynaplan\Service\KeyStorage;
use OCA\OcoSynaplan\Service\LanguageService;
use OCA\OcoSynaplan\Service\ReadScope;
use OCA\OcoSynaplan\Tests\Unit\Support\BuildsServices;
use OCA\OcoSynaplan\Tests\Unit\Support\FakeCrypto;
use OCA\OcoSynaplan\Tests\Unit\Support\RecordingHttp;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Http\Client\IResponse;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ApiControllerTest extends TestCase
{
    use BuildsServices;

    protected function setUp(): void
    {
        $this->setUpServices(['mode' => 'link']);
        $this->signIn($this->person('ada'));
    }

    /**
     * @return array<string, array{0: list<string>, 1: bool}>
     */
    public static function summaryTypes(): array
    {
        return [
            'translates' => [['bullet-points', 'translation'], true],
            'does not translate' => [['bullet-points', 'abstractive'], false],
        ];
    }

    /**
     * @param list<string> $types
     */
    #[DataProvider('summaryTypes')]
    public function testTheFilesAppIsToldWhetherSynaplanTranslates(array $types, bool $translation): void
    {
        $this->synaplanOffers($types, ['deliveries', 'pickups', 'read_scope']);

        self::assertSame(
            ['translation' => $translation, 'read_folders' => true, 'file_state' => false, 'follows_changes' => false],
            $this->controller()->features()->getData(),
        );
    }

    /**
     * @return array<string, array{0: string, 1: list<string>|null, 2: bool, 3: bool}>
     */
    public static function fileStates(): array
    {
        $cloudFiles = ['deliveries', 'pickups', 'read_scope', 'cloud_status', 'source_events', 'watches'];

        return [
            'cloud files on, people connect' => ['link', $cloudFiles, true, true],
            'cloud files on, people provisioned' => ['provision', $cloudFiles, true, true],
            'cloud files on, one shared key' => ['shared', $cloudFiles, false, false],
            'cloud files off' => ['link', ['deliveries', 'pickups', 'read_scope'], false, false],
            'platform links off' => ['link', null, false, false],
        ];
    }

    /**
     * @param list<string>|null $capabilities
     */
    #[DataProvider('fileStates')]
    public function testTheBirdAndKeepingUpToDateShowOnlyWhereSynaplanTellsThemForEachPerson(
        string $mode,
        ?array $capabilities,
        bool $fileState,
        bool $followsChanges,
    ): void {
        $this->config->setAppValue('oco_synaplan', 'mode', $mode);
        $this->register();
        $this->synaplanOffers(['bullet-points'], $capabilities);

        $features = $this->controller()->features()->getData();

        self::assertSame($fileState, $features['file_state']);
        self::assertSame($followsChanges, $features['follows_changes']);
    }

    public function testKeepingUpToDateNeedsThisServerRegisteredWithSynaplan(): void
    {
        $this->synaplanOffers(['bullet-points'], ['deliveries', 'pickups', 'read_scope', 'cloud_status', 'source_events']);

        $features = $this->controller()->features()->getData();

        self::assertTrue($features['file_state']);
        self::assertFalse($features['follows_changes']);
    }

    public function testTheFolderMenuOffersReadingOnlyWhereTheAdministratorLetsPeopleNameFolders(): void
    {
        $this->synaplanOffers(['bullet-points'], ['deliveries', 'pickups', 'read_scope']);

        foreach ([ReadScope::MODE_WATCHED => false, ReadScope::MODE_ALLOWED => true, ReadScope::MODE_ALL => true] as $ceiling => $offered) {
            $this->config->setAppValue('oco_synaplan', ReadScope::CEILING, $ceiling);

            self::assertSame($offered, $this->controller()->features()->getData()['read_folders'], $ceiling);
        }
    }

    /**
     * @return array<string, array{0: list<string>|null, 1: bool}>
     */
    public static function configurations(): array
    {
        return [
            'reads folders' => [['deliveries', 'pickups', 'read_scope'], true],
            'takes no read setting' => [['deliveries', 'pickups'], false],
            'reads no folders' => [['deliveries', 'read_scope'], false],
            'has platform links off' => [null, false],
        ];
    }

    /**
     * @param list<string>|null $capabilities
     */
    #[DataProvider('configurations')]
    public function testTheFolderMenuOffersReadingOnlyWhereSynaplanReadsFolders(?array $capabilities, bool $offered): void
    {
        $this->config->setAppValue('oco_synaplan', ReadScope::CEILING, ReadScope::MODE_ALL);
        $this->synaplanOffers(['bullet-points'], $capabilities);

        self::assertSame($offered, $this->controller()->features()->getData()['read_folders']);
    }

    /**
     * @return array<string, array{0: string, 1: bool, 2: bool|null, 3: string|null}>
     */
    public static function keepingUpToDate(): array
    {
        return [
            'kept unless the person says no' => ['link', true, null, '1'],
            'kept, as the person chose' => ['link', true, true, '1'],
            'not kept, as the person chose' => ['link', true, false, '0'],
            'left to Synaplan before this server registered' => ['provision', false, true, null],
            'left to Synaplan with one shared key' => ['shared', true, true, null],
        ];
    }

    #[DataProvider('keepingUpToDate')]
    public function testAFileIsKeptUpToDateOnlyWhereSynaplanHearsWhenItChanges(
        string $mode,
        bool $registered,
        ?bool $asked,
        ?string $sent,
    ): void {
        $this->config->setAppValue('oco_synaplan', 'mode', $mode);
        $this->keys()->setAppKey('api_key', 'sk_admin');
        $this->config->setUserValue('ada', 'oco_synaplan', KeyStorage::USER_KEY, FakeCrypto::stored('sk_ada'));
        if ($registered) {
            $this->register();
            $this->connect('ada');
        }
        $this->synaplanOffers(['bullet-points'], ['deliveries', 'pickups', 'read_scope', 'cloud_status', 'source_events']);
        $this->root = $this->filesOfAda($this->file(7, 'plan.docx'));

        $this->controller()->uploadToKnowledge(7, 'Contracts', $asked);

        $upload = $this->http->to('/api/v1/files/upload');
        self::assertCount(1, $upload);
        self::assertSame($sent, $upload[0]['body']['keep_synced'] ?? null);
    }

    /**
     * @param list<string> $types
     * @param list<string>|null $capabilities null while Synaplan has platform links off
     */
    private function synaplanOffers(array $types, ?array $capabilities): void
    {
        $this->http->answer(static fn (string $method, string $path): array|IResponse => match (true) {
            $path !== '/.well-known/synaplan-configuration' => ['summary' => ['types' => $types]],
            $capabilities === null => RecordingHttp::response(404, '{"error":"not_found"}'),
            default => [
                'issuer' => 'https://synaplan.example',
                'jwks_uri' => 'https://synaplan.example/.well-known/jwks.json',
                'capabilities' => $capabilities,
            ],
        });
    }

    private function filesOfAda(File $file): IRootFolder
    {
        $userFolder = $this->createStub(Folder::class);
        $userFolder->method('getById')->willReturnCallback(static fn (int $id): array => $id === $file->getId() ? [$file] : []);
        $userFolder->method('getRelativePath')->willReturn('/Projects/' . $file->getName());
        $root = $this->createStub(IRootFolder::class);
        $root->method('getUserFolder')->willReturn($userFolder);

        return $root;
    }

    private function file(int $id, string $name): File
    {
        $file = $this->createStub(File::class);
        $file->method('getId')->willReturn($id);
        $file->method('getName')->willReturn($name);
        $file->method('getPath')->willReturn('/ada/files/Projects/' . $name);
        $file->method('getSize')->willReturn(7);
        $file->method('getEtag')->willReturn('"e1"');
        $file->method('getContent')->willReturn('content');

        return $file;
    }

    private function controller(): ApiController
    {
        return new ApiController(
            $this->createStub(IRequest::class),
            $this->client(),
            $this->root ?? $this->createStub(IRootFolder::class),
            $this->session(),
            new LanguageService($this->config, $this->session()),
            $this->config,
            $this->appManager(),
            $this->logger(),
            $this->accounts(),
            $this->readScope(),
            $this->links(),
            $this->cloudState(),
        );
    }
}
