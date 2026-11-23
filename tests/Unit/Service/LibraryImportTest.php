<?php

declare(strict_types=1);

namespace OCA\OcoSynaplan\Tests\Unit\Service;

use OCA\OcoSynaplan\Exception\FailureException;
use OCA\OcoSynaplan\Http\Failure;
use OCA\OcoSynaplan\Service\CopyDelivery;
use OCA\OcoSynaplan\Service\LibraryImport;
use OCA\OcoSynaplan\Tests\Unit\Support\BuildsServices;
use OCA\OcoSynaplan\Tests\Unit\Support\RecordingHttp;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotEnoughSpaceException;
use OCP\Files\NotPermittedException;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class LibraryImportTest extends TestCase
{
    use BuildsServices;

    private Folder&MockObject $userFolder;
    private Folder&MockObject $projects;

    /** @var array<string, true> */
    private array $existing = [];
    private int $free = -3;
    private ?\Throwable $writeFails = null;

    /** @var list<string> */
    private array $written = [];

    protected function setUp(): void
    {
        $this->setUpServices(['mode' => 'link']);
        $this->register();
        $this->connect('ada', 'access-ada');
        $this->signIn($this->person('ada'));

        $this->projects = $this->createMock(Folder::class);
        $this->projects->method('nodeExists')->willReturnCallback(fn (string $name): bool => isset($this->existing[$name]));
        $this->projects->method('getFreeSpace')->willReturnCallback(fn (): int => $this->free);
        $this->projects->method('newFile')->willReturnCallback(function (string $name): File {
            if ($this->writeFails !== null) {
                throw $this->writeFails;
            }
            $this->written[] = $name;
            $file = $this->createMock(File::class);
            $file->method('getName')->willReturn($name);
            $file->method('getPath')->willReturn('/ada/files/Projects/' . $name);

            return $file;
        });
        $this->userFolder = $this->createMock(Folder::class);
        $this->userFolder->method('nodeExists')->willReturnCallback(static fn (string $name): bool => $name === 'Projects');
        $this->userFolder->method('get')->with('Projects')->willReturn($this->projects);
        $this->userFolder->method('getRelativePath')->willReturnCallback(
            static fn (string $path): string => substr($path, strlen('/ada/files')),
        );
    }

    public function testThePickerShowsGeneratedFilesWithThePersonsOwnCredential(): void
    {
        $this->http->answer(static fn (): array => [
            'files' => [
                ['id' => 12, 'display_name' => 'Q3 plan.docx', 'filename' => 'stored_12.docx', 'generated' => true, 'uploaded_at' => 1_800_000_000],
                ['id' => 13, 'filename' => 'notes/../../report.pdf', 'source' => 'generated'],
                ['id' => 14, 'display_name' => '..'],
                ['display_name' => 'no id.pdf'],
            ],
            'pagination' => ['page' => 1, 'pages' => 3],
        ]);

        $listed = $this->import()->list();

        self::assertSame([
            'files' => [
                ['id' => 12, 'name' => 'Q3 plan.docx', 'generated' => true, 'uploaded_at' => 1_800_000_000],
                ['id' => 13, 'name' => 'report.pdf', 'generated' => true, 'uploaded_at' => 0],
            ],
            'page' => 1,
            'pages' => 3,
        ], $listed);
        $call = $this->http->to('/api/v1/files')[0];
        parse_str((string) parse_url($call['url'], PHP_URL_QUERY), $query);
        self::assertSame(['page' => '1', 'limit' => '50', 'source' => 'generated'], $query);
        self::assertSame('Bearer access-ada', $call['options']['headers']['Authorization']);
    }

    public function testAllFilesAndASearchAreAskedAsSuch(): void
    {
        $this->http->answer(static fn (): array => ['files' => []]);

        $listed = $this->import()->list(0, 'all', '  ' . str_repeat('q', 250) . ' ');

        self::assertSame(['files' => [], 'page' => 1, 'pages' => 1], $listed);
        parse_str((string) parse_url($this->http->calls[0]['url'], PHP_URL_QUERY), $query);
        self::assertSame(['page' => '1', 'limit' => '50', 'search' => str_repeat('q', 200)], $query);
    }

    public function testACopyLandsInTheOpenFolderUnderItsOwnName(): void
    {
        $this->http->answer(static fn (): IResponse => RecordingHttp::response(200, 'bytes'));
        $this->existing = ['Q3 plan.docx' => true];

        $saved = $this->import()->copy('ada', 12, '/Projects/', 'Q3 plan.docx');

        self::assertSame(['name' => 'Q3 plan (2).docx', 'folder' => 'Projects'], $saved);
        self::assertSame(['Q3 plan (2).docx'], $this->written);
        $call = $this->http->to('/api/v1/files/12/download')[0];
        self::assertSame('Bearer access-ada', $call['options']['headers']['Authorization']);
    }

    public function testANameFromSynaplanCannotLeaveTheFolder(): void
    {
        $this->http->answer(static fn (): IResponse => RecordingHttp::response(200, 'bytes'));

        $saved = $this->import()->copy('ada', 12, 'Projects', '../../secret.txt');

        self::assertSame(['secret.txt'], $this->written);
        self::assertSame('Projects', $saved['folder']);
    }

    public function testAFolderOutsideThePersonsFilesIsRefusedBeforeDownloading(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        try {
            $this->import()->copy('ada', 12, 'Projects/../..', 'a.txt');
        } finally {
            self::assertSame([], $this->http->calls);
        }
    }

    public function testAFileLargerThanACopyMayBeIsRefusedFromItsAnnouncedLength(): void
    {
        $this->http->answer(static function (string $method, string $path, array $options): \Throwable {
            try {
                $options['on_headers'](new class () {
                    public function getHeaderLine(string $name): string
                    {
                        return $name === 'Content-Length' ? (string) (CopyDelivery::MAX_BYTES + 1) : '';
                    }
                });
            } catch (\Throwable $e) {
                return new \RuntimeException('An error was encountered during the on_headers event', 0, $e);
            }

            return new \LogicException('on_headers let a file over the cap through');
        });

        $failure = $this->failure(fn () => $this->import()->copy('ada', 12, 'Projects', 'video.mp4'));

        self::assertSame(Failure::COPY_TOO_LARGE, $failure->code);
        self::assertSame([], $this->written);
    }

    public function testAFullStorageAndAReadOnlyFolderAreSaidAsSuch(): void
    {
        $this->http->answer(static fn (): IResponse => RecordingHttp::response(200, 'bytes'));

        $this->free = 2;
        self::assertSame(Failure::QUOTA_EXCEEDED, $this->failure(fn () => $this->import()->copy('ada', 12, 'Projects', 'a.txt'))->code);

        $this->free = -3;
        $this->writeFails = new NotPermittedException('read-only share');
        self::assertSame(Failure::FOLDER_READ_ONLY, $this->failure(fn () => $this->import()->copy('ada', 12, 'Projects', 'a.txt'))->code);

        $this->writeFails = null;
        $this->existing = ['a.txt' => true];
        for ($n = 2; $n <= 20; $n++) {
            $this->existing['a (' . $n . ').txt'] = true;
        }
        self::assertSame(Failure::NAME_TAKEN, $this->failure(fn () => $this->import()->copy('ada', 12, 'Projects', 'a.txt'))->code);
    }

    public function testAQuotaTheStorageReportsItselfIsTheSameFailure(): void
    {
        $this->http->answer(static fn (): IResponse => RecordingHttp::response(200, 'bytes'));
        $this->writeFails = new NotEnoughSpaceException('quota');

        self::assertSame(Failure::QUOTA_EXCEEDED, $this->failure(fn () => $this->import()->copy('ada', 12, 'Projects', 'a.txt'))->code);
    }

    private function import(): LibraryImport
    {
        $root = $this->createStub(IRootFolder::class);
        $root->method('getUserFolder')->willReturn($this->userFolder);

        return new LibraryImport($this->client(), $root, $this->logger());
    }

    /**
     * @param callable(): mixed $call
     */
    private function failure(callable $call): Failure
    {
        try {
            $call();
        } catch (FailureException $e) {
            return $e->failure;
        }
        self::fail('Expected a failure');
    }
}
