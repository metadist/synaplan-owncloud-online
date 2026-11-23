<?php

declare(strict_types=1);

namespace OCA\OcoSynaplan\Tests\Unit\Service;

use OCA\OcoSynaplan\BackgroundJob\DeliveryResultJob;
use OCA\OcoSynaplan\BackgroundJob\DeliveryRetryJob;
use OCA\OcoSynaplan\Service\CopyDelivery;
use OCA\OcoSynaplan\Service\Notices;
use OCA\OcoSynaplan\Service\PersonFiles;
use OCA\OcoSynaplan\Service\PlatformLinkService;
use OCA\OcoSynaplan\Service\SynaplanConfig;
use OCA\OcoSynaplan\Service\SynaplanFolders;
use OCA\OcoSynaplan\Tests\Unit\Support\MemoryCache;
use OCA\OcoSynaplan\Tests\Unit\Support\MemoryConfig;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotPermittedException;
use OCP\ILogger;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class CopyDeliveryTest extends TestCase
{
    private PlatformLinkService&MockObject $links;
    private IJobList&MockObject $jobs;
    private Notices&MockObject $notices;
    private Folder&MockObject $userFolder;
    private Folder&MockObject $dir;
    private MemoryCache $cache;
    private MemoryConfig $config;
    private bool $held = false;
    private int $now = 1_800_000_000;

    /** @var array<string, File&MockObject> files already in the target folder, by name */
    private array $existing = [];
    private int $free = -3;
    private ?\Throwable $newFileFails = null;
    private int $nextId = 42;

    /** @var array<string, mixed>|null */
    private ?array $reported = null;

    protected function setUp(): void
    {
        $this->config = new MemoryConfig();
        $this->cache = new MemoryCache();
        $this->jobs = $this->createMock(IJobList::class);
        $this->notices = $this->createMock(Notices::class);
        $this->links = $this->createMock(PlatformLinkService::class);
        $this->links->method('reportDelivery')->willReturnCallback(
            function (string $id, string $uid, array $result): void {
                $this->reported = $result;
            },
        );
        $this->links->method('openUrl')->willReturnCallback(static fn (int $fileId): string => 'https://cloud.example/index.php/f/' . $fileId);
        $this->dir = $this->createMock(Folder::class);
        $this->dir->method('nodeExists')->willReturnCallback(fn (string $name): bool => isset($this->existing[$name]));
        $this->dir->method('get')->willReturnCallback(fn (string $name): File => $this->existing[$name]);
        $this->dir->method('newFolder')->willReturnSelf();
        $this->dir->method('getFreeSpace')->willReturnCallback(fn (): int => $this->free);
        $this->dir->method('newFile')->willReturnCallback(function (string $name): File {
            if ($this->newFileFails !== null) {
                throw $this->newFileFails;
            }

            return $this->existing[$name] = $this->file($name, $this->nextId++);
        });
        $this->userFolder = $this->createMock(Folder::class);
        $this->userFolder->method('nodeExists')->willReturn(false);
        $this->userFolder->method('newFolder')->willReturn($this->dir);
        $this->userFolder->method('getRelativePath')->willReturnCallback(
            static fn (string $path): string => substr($path, strlen('/ada/files')),
        );
    }

    public function testACopyIsWrittenReportedAndTold(): void
    {
        $this->claim();
        $this->notices->expects($this->once())->method('copied')
            ->with('ada', 'Q3 plan.docx', 'Synaplan/Documents', 42);

        $answer = $this->delivery()->handle('9', 'ada');

        self::assertSame(200, $answer['status']);
        self::assertSame([
            'status' => 'delivered',
            'remote_path' => '/Synaplan/Documents/Q3 plan.docx',
            'remote_id' => '42',
            'open_url' => 'https://cloud.example/index.php/f/42',
        ], $answer['body']);
        self::assertSame($answer['body'], $this->reported);
    }

    public function testACopyLandsInTheFolderThePersonChose(): void
    {
        $this->claim();
        $this->config->setUserValue('ada', 'oco_synaplan', 'copy_folder', 'Projects/Q3');
        $made = [];
        $this->userFolder = $this->createMock(Folder::class);
        $this->userFolder->method('nodeExists')->willReturn(false);
        $this->userFolder->method('newFolder')->willReturnCallback(function (string $name) use (&$made): Folder {
            $made[] = $name;

            return $this->dir;
        });
        $this->userFolder->method('getRelativePath')->willReturn('/Projects/Q3/Documents/Q3 plan.docx');

        $answer = $this->delivery()->handle('9', 'ada');

        self::assertSame(['Projects'], $made, 'the rest is made inside the first new folder');
        self::assertSame('/Projects/Q3/Documents/Q3 plan.docx', $answer['body']['remote_path']);
    }

    public function testAFolderSynaplanNamedIsUsed(): void
    {
        $this->claim(['target' => ['kind' => 'folder_id', 'ref' => '77']]);
        $this->userFolder->method('getById')->with(77)->willReturn([$this->dir]);
        $this->userFolder->expects($this->never())->method('newFolder');

        self::assertSame('delivered', $this->delivery()->handle('9', 'ada')['body']['status']);
    }

    public function testACopyNextToAFileGoesIntoThatFilesFolder(): void
    {
        $this->claim(['target' => ['kind' => 'next_to', 'ref' => '78']]);
        $beside = $this->createMock(File::class);
        $beside->method('getParent')->willReturn($this->dir);
        $this->userFolder->method('getById')->with(78)->willReturn([$beside]);

        self::assertSame('delivered', $this->delivery()->handle('9', 'ada')['body']['status']);
    }

    public function testAFullStorageIsQuotaExceeded(): void
    {
        $this->claim();
        $this->free = 3;

        self::assertSame('quota_exceeded', $this->failure());
    }

    public function testAFolderThePersonMayNotWriteIsForbidden(): void
    {
        $this->claim();
        $this->newFileFails = new NotPermittedException('read-only share');

        self::assertSame('forbidden', $this->failure());
    }

    public function testATargetFolderThatIsGoneIsNotFound(): void
    {
        $this->claim(['target' => ['kind' => 'folder_id', 'ref' => '77']]);
        $this->userFolder->method('getById')->willReturn([]);

        self::assertSame('not_found', $this->failure());
    }

    public function testTwentyFilesWithTheSameNameAreAConflict(): void
    {
        $this->claim();
        $this->sameNames(20);

        self::assertSame('conflict', $this->failure());
    }

    public function testNineteenFilesWithTheSameNameStillLeaveRoom(): void
    {
        $this->claim();
        $this->sameNames(19);

        $answer = $this->delivery()->handle('9', 'ada');

        self::assertSame('/Synaplan/Documents/Q3 plan (20).docx', $answer['body']['remote_path']);
    }

    public function testACopyMayReplaceOnlyAFileThisAppWrote(): void
    {
        $this->claim(['on_conflict' => 'replace_own']);
        $first = $this->delivery()->handle('9', 'ada');
        $written = $this->existing['Q3 plan.docx'];
        $written->expects($this->once())->method('putContent')->with('hello');

        $again = $this->delivery()->handle('10', 'ada');

        self::assertSame($first['body']['remote_path'], $again['body']['remote_path']);

        $this->cache->values = [];
        $other = $this->delivery()->handle('11', 'ada');
        self::assertSame('/Synaplan/Documents/Q3 plan (2).docx', $other['body']['remote_path'], 'a file this app did not write is kept');
    }

    public function testAFileOverTheCapIsTooLargeAndNotDownloaded(): void
    {
        $this->claim(['size' => CopyDelivery::MAX_BYTES + 1]);
        $this->links->expects($this->never())->method('downloadDelivery');

        self::assertSame('too_large', $this->failure());
    }

    public function testADownloadThatFailsIsUnreachable(): void
    {
        $this->claim(download: null);

        self::assertSame('unreachable', $this->failure());
    }

    public function testAnyOtherWriteErrorIsUnreachable(): void
    {
        $this->claim();
        $this->newFileFails = new \RuntimeException('storage offline');

        self::assertSame('unreachable', $this->failure());
    }

    public function testACopyForSomeoneElseIsNotWritten(): void
    {
        $this->claim(['external_id' => 'bob']);
        $this->links->expects($this->never())->method('downloadDelivery');

        self::assertSame('not_found', $this->failure());
    }

    public function testACopySomeoneElseClaimedStaysWithThem(): void
    {
        $this->links->method('claimDelivery')->willReturn(['status_code' => 409, 'error' => 'already_claimed']);
        $this->links->expects($this->never())->method('reportDelivery');

        $answer = $this->delivery()->handle('9', 'ada');

        self::assertSame([202, ['state' => 'pending']], [$answer['status'], $answer['body']]);
    }

    public function testWhileInboundIsHeldTheResultWaitsForTheBackground(): void
    {
        $this->claim();
        $this->held = true;
        $this->links->expects($this->never())->method('reportDelivery');
        $this->jobs->expects($this->once())->method('add')->with(
            DeliveryResultJob::class,
            $this->callback(static fn (array $argument): bool => $argument['id'] === '9'
                && $argument['uid'] === 'ada'
                && $argument['result']['status'] === 'delivered'),
        );

        $answer = $this->delivery()->handle('9', 'ada');

        self::assertSame([202, ['state' => 'pending']], [$answer['status'], $answer['body']]);
    }

    public function testAResultTooLongToHoldIsReportedAtOnce(): void
    {
        $this->claim();
        $this->held = true;
        $this->jobs->method('add')->willThrowException(new \InvalidArgumentException('Background job arguments can\'t exceed 4000 characters'));

        $answer = $this->delivery()->handle('9', 'ada');

        self::assertSame(200, $answer['status']);
        self::assertSame('delivered', $this->reported['status'] ?? null);
    }

    public function testAFailedClaimIsTriedAgainInTheBackground(): void
    {
        $this->links->method('claimDelivery')->willThrowException(new \RuntimeException('connection refused'));
        $this->links->expects($this->never())->method('reportDelivery');
        $this->jobs->expects($this->once())->method('add')
            ->with(DeliveryRetryJob::class, ['id' => '9', 'uid' => 'ada', 'since' => $this->now]);

        $answer = $this->delivery()->handle('9', 'ada');

        self::assertSame([202, ['state' => 'pending']], [$answer['status'], $answer['body']]);
    }

    public function testTheBackgroundTryWritesTheCopyAndReportsIt(): void
    {
        $this->claim();
        $this->jobs->expects($this->never())->method('add');

        $this->delivery()->retry('9', 'ada', $this->now - 240);

        self::assertSame('delivered', $this->reported['status'] ?? null);
    }

    public function testTheBackgroundTryIsTheLastOne(): void
    {
        $this->links->expects($this->once())->method('claimDelivery')->willThrowException(new \RuntimeException('connection refused'));
        $this->jobs->expects($this->never())->method('add');

        $this->delivery()->retry('9', 'ada', $this->now - 60);
    }

    public function testAnExpiredCopyIsNotClaimedAgain(): void
    {
        $this->links->expects($this->never())->method('claimDelivery');

        $this->delivery()->retry('9', 'ada', $this->now - CopyDelivery::TICKET_TTL - 1);
    }

    public function testAHeldResultIsSentWhenTheJobRuns(): void
    {
        $this->links->expects($this->once())->method('reportDelivery')->with('9', 'ada', ['status' => 'failed', 'error_code' => 'conflict']);

        $this->delivery()->sendResult('9', 'ada', ['status' => 'failed', 'error_code' => 'conflict']);
    }

    /**
     * @param array<string, mixed> $override
     */
    private function claim(array $override = [], ?string $download = 'hello'): void
    {
        $this->links->method('claimDelivery')->willReturn($override + [
            'id' => '9',
            'external_id' => 'ada',
            'name' => 'Q3 plan.docx',
            'size' => 5,
            'target' => ['kind' => 'kind_folder', 'ref' => ''],
            'on_conflict' => 'rename',
            'download_path' => '/api/v1/platform-links/deliveries/9/file?ticket=abc',
        ]);
        $this->links->method('downloadDelivery')->willReturn($download);
    }

    private function sameNames(int $count): void
    {
        $this->existing = ['Q3 plan.docx' => $this->file('Q3 plan.docx', 1)];
        for ($n = 2; $n <= $count; $n++) {
            $this->existing['Q3 plan (' . $n . ').docx'] = $this->file('Q3 plan (' . $n . ').docx', $n);
        }
    }

    private function file(string $name, int $id): File&MockObject
    {
        $file = $this->createMock(File::class);
        $file->method('getId')->willReturn($id);
        $file->method('getName')->willReturn($name);
        $file->method('getPath')->willReturn('/ada/files/Synaplan/Documents/' . $name);

        return $file;
    }

    private function failure(): string
    {
        $answer = $this->delivery()->handle('9', 'ada');
        self::assertSame('failed', $answer['body']['status']);
        self::assertSame($answer['body'], $this->reported);

        return (string) $answer['body']['error_code'];
    }

    private function delivery(): CopyDelivery
    {
        $files = $this->createStub(PersonFiles::class);
        $files->method('folderOf')->willReturnCallback(fn (): Folder => $this->userFolder);
        $users = $this->createStub(IUserManager::class);
        $users->method('get')->willReturnCallback(fn (string $uid): ?IUser => in_array($uid, ['ada', 'bob'], true) ? $this->createStub(IUser::class) : null);
        $time = $this->createStub(ITimeFactory::class);
        $time->method('getTime')->willReturnCallback(fn (): int => $this->now);
        $synaplanConfig = $this->createStub(SynaplanConfig::class);
        $synaplanConfig->method('isDevSwitchOn')->willReturnCallback(
            fn (string $name): bool => $this->held && $name === SynaplanConfig::DEV_HOLD_INBOUND,
        );

        return new CopyDelivery(
            $this->links,
            $files,
            new SynaplanFolders($this->config),
            $users,
            $this->cache,
            $this->notices,
            $this->createStub(ILogger::class),
            $this->jobs,
            $time,
            $synaplanConfig,
        );
    }
}
