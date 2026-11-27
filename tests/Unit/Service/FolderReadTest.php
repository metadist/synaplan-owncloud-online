<?php

declare(strict_types=1);

namespace OCA\OcoSynaplan\Tests\Unit\Service;

use OCA\OcoSynaplan\BackgroundJob\PickupJob;
use OCA\OcoSynaplan\Service\FolderRead;
use OCA\OcoSynaplan\Service\PersonFiles;
use OCA\OcoSynaplan\Service\PlatformLinkService;
use OCA\OcoSynaplan\Service\ReadScope;
use OCA\OcoSynaplan\Tests\Unit\Support\BuildsServices;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class FolderReadTest extends TestCase
{
    use BuildsServices;

    private PlatformLinkService&MockObject $links;
    private ReadScope&MockObject $scope;
    private IJobList&MockObject $jobs;
    private FolderRead $reads;
    private string $etag = 'etag-1';
    private int $etagReads = 0;
    /** @var list<Node> what Invoices holds */
    private array $listing = [];
    /** @var list<Node> what Invoices/Private holds */
    private array $private = [];
    private int $now = 1_800_000_000;
    /** How long listing Invoices/Private takes. */
    private int $privateTakes = 0;
    /** Whether Ada's files can be opened. */
    private bool $homeAway = false;
    /** How often Invoices was listed. */
    private int $invoicesListed = 0;

    protected function setUp(): void
    {
        $this->setUpServices();
        $this->links = $this->createMock(PlatformLinkService::class);
        $this->links->method('canActFor')->willReturnCallback(static fn (string $uid): bool => $uid === 'ada');
        $this->scope = $this->createMock(ReadScope::class);
        $this->jobs = $this->createMock(IJobList::class);
        $users = $this->createStub(IUserManager::class);
        $users->method('get')->willReturnCallback(
            fn (string $uid): ?IUser => $uid === 'ada' ? $this->person('ada') : null,
        );

        $file = $this->createStub(File::class);
        $file->method('getId')->willReturn(7);
        $file->method('getName')->willReturn('a.txt');
        $file->method('getSize')->willReturn(5);
        $file->method('getMimeType')->willReturn('text/plain');
        $file->method('getEtag')->willReturnCallback(function (): string {
            $this->etagReads++;

            return $this->etagReads === 1 ? 'etag-1' : $this->etag;
        });
        $file->method('getMTime')->willReturn(time());
        $file->method('getContent')->willReturn('hello');
        $file->method('getPath')->willReturn('/ada/files/Invoices/a.txt');

        $secret = $this->createStub(File::class);
        $secret->method('getId')->willReturn(8);
        $secret->method('getName')->willReturn('b.txt');
        $secret->method('getMTime')->willReturn(time());
        $secret->method('getPath')->willReturn('/ada/files/Invoices/Private/b.txt');
        $this->private = [$secret];

        $invoices = $this->createStub(Folder::class);
        $invoices->method('getId')->willReturn(30);
        $invoices->method('getPath')->willReturn('/ada/files/Invoices');
        $invoices->method('getName')->willReturn('Invoices');
        $invoices->method('getDirectoryListing')->willReturnCallback(function (): array {
            $this->invoicesListed++;

            return $this->listing;
        });
        $this->listing = [$file];

        $home = $this->createStub(Folder::class);
        $home->method('getId')->willReturn(5);
        $home->method('getPath')->willReturn('/ada/files');
        $home->method('get')->willReturnCallback(static function (string $path) use ($invoices): Node {
            if ($path !== 'Invoices') {
                throw new NotFoundException($path);
            }

            return $invoices;
        });
        // ownCloud finds the home folder by its own id too.
        $home->method('getById')->willReturnCallback(static fn (int $id): array => match ($id) {
            5 => [$home],
            7 => [$file],
            9 => throw new \RuntimeException('The storage is not available'),
            30 => [$invoices],
            default => [],
        });
        $home->method('getRelativePath')->willReturnCallback(
            static fn (string $path): string => substr($path, strlen('/ada/files')) ?: '/',
        );
        $files = $this->createStub(PersonFiles::class);
        $files->method('folderOf')->willReturnCallback(function (string $uid) use ($home): Folder {
            if ($uid !== 'ada' || $this->homeAway) {
                throw new NotFoundException($uid);
            }

            return $home;
        });
        $time = $this->createStub(ITimeFactory::class);
        $time->method('getTime')->willReturnCallback(fn (): int => $this->now);

        $this->reads = new FolderRead(
            $this->links,
            $this->scope,
            $files,
            $users,
            $this->jobs,
            $this->logger(),
            $this->config,
            $this->db(),
            $time,
        );
    }

    public function testAFolderOutsideTheSettingIsRefusedBeforeAnyListing(): void
    {
        $this->scope->method('mayRead')->with('ada', 'Invoices')->willReturn(false);
        $this->links->method('claimPickup')->willReturn($this->claim());
        $this->links->expects($this->never())->method('submitPickupListing');
        $this->links->expects($this->once())->method('reportPickupResult')->with('15', 'ada', $this->callback(
            static fn (array $body): bool => $body['status'] === 'failed' && $body['error_code'] === 'outside_scope',
        ));

        $this->reads->handle('15', 'ada');
    }

    public function testAReadIsNotClaimedWithoutThePersonsKey(): void
    {
        $this->links->expects($this->never())->method('claimPickup');

        $this->reads->handle('15', 'bob');
    }

    public function testAClaimNamingSomeoneElseIsRefusedBeforeAnyListing(): void
    {
        $claim = $this->claim();
        $claim['external_id'] = 'bob';
        $this->links->method('claimPickup')->willReturn($claim);
        $this->links->expects($this->never())->method('submitPickupListing');
        $this->links->expects($this->once())->method('reportPickupResult')->with('15', 'ada', $this->callback(
            static fn (array $body): bool => $body['status'] === 'failed' && $body['error_code'] === 'not_found',
        ));

        $this->reads->handle('15', 'ada');
    }

    public function testAClaimSynaplanRefusesEndsWithoutAResult(): void
    {
        $this->links->method('claimPickup')->willReturn(['status_code' => 409, 'error' => 'already_claimed']);
        $this->links->expects($this->never())->method('submitPickupListing');
        $this->links->expects($this->never())->method('reportPickupResult');

        $this->reads->handle('15', 'ada');
    }

    public function testAnAllowedFolderIsListedAndTheAskedFileIsSent(): void
    {
        $this->scope->method('mayRead')->willReturn(true);
        $this->links->expects($this->once())->method('claimPickup')->with('15', 'ada', FolderRead::BUDGET_SECONDS)->willReturn($this->claim());
        $this->links->expects($this->once())->method('submitPickupListing')->with('15', 'ada', [[
            'id' => '7',
            'version' => 'etag-1',
            'name' => 'a.txt',
            'folder' => 'Invoices',
            'size' => 5,
            'mime' => 'text/plain',
        ]], FolderRead::BUDGET_SECONDS)->willReturn(['upload' => ['7'], 'reuse' => [], 'skip' => []]);
        $this->links->expects($this->once())->method('uploadPickupFile')->with('15', 'ada', '7', 'etag-1', 'hello', 'a.txt', 'text/plain', FolderRead::BUDGET_SECONDS)
            ->willReturn(['status_code' => 200]);
        $this->links->expects($this->once())->method('reportPickupResult')->with('15', 'ada', [
            'status' => 'done',
            'listed' => 1,
            'sent' => 1,
            'skipped' => [],
        ]);
        $this->jobs->expects($this->never())->method('add');

        $this->reads->handle('15', 'ada');
    }

    public function testAnIdCarryingAPrefixBeforeAColonNamesThePersonAfterIt(): void
    {
        $this->scope->method('mayRead')->willReturn(true);
        $claim = $this->claim();
        $claim['external_id'] = 'cloud-1:ada';
        $this->links->expects($this->once())->method('claimPickup')->with('15', 'ada')->willReturn($claim);
        $this->links->method('submitPickupListing')->willReturn(['upload' => [], 'reuse' => [], 'skip' => []]);
        $this->links->expects($this->once())->method('reportPickupResult')->with('15', 'ada', $this->callback(
            static fn (array $body): bool => $body['status'] === 'done' && $body['listed'] === 1,
        ));

        $this->reads->handle('15', 'cloud-1:ada');
    }

    public function testAnAllowedFolderNamedByItsIdIsReadWhereverItIsNow(): void
    {
        $this->scope->method('mayRead')->with('ada', 'Invoices')->willReturn(true);
        $claim = $this->claim();
        $claim['selector'] = ['folder_id' => '30', 'folder_path' => 'Old name', 'recursive' => false];
        $this->links->method('claimPickup')->willReturn($claim);
        $this->links->expects($this->once())->method('submitPickupListing')->with('15', 'ada', $this->callback(
            static fn (array $items): bool => array_column($items, 'id') === ['7'],
        ))->willReturn(['upload' => [], 'reuse' => [], 'skip' => []]);

        $this->reads->handle('15', 'ada');
    }

    public function testARecursiveReadEntersOnlyTheSubFoldersTheSettingAllows(): void
    {
        $this->listing[] = $this->privateFolder();
        $this->scope->method('mayRead')->willReturnCallback(
            static fn (string $uid, string $folder): bool => $folder === 'Invoices',
        );
        $claim = $this->claim();
        $claim['selector'] = ['folder_path' => 'Invoices', 'recursive' => true];
        $this->links->method('claimPickup')->willReturn($claim);
        $this->links->expects($this->once())->method('submitPickupListing')->with('15', 'ada', $this->callback(
            static fn (array $items): bool => array_column($items, 'name') === ['a.txt'],
        ))->willReturn(['upload' => [], 'reuse' => [], 'skip' => []]);

        $this->reads->handle('15', 'ada');
    }

    public function testARecursiveReadListsTheSubFoldersTheSettingAllows(): void
    {
        $this->listing[] = $this->privateFolder();
        $this->scope->method('mayRead')->willReturn(true);
        $claim = $this->claim();
        $claim['selector'] = ['folder_path' => 'Invoices', 'recursive' => true];
        $this->links->method('claimPickup')->willReturn($claim);
        $this->links->expects($this->once())->method('submitPickupListing')->with('15', 'ada', $this->callback(
            static fn (array $items): bool => array_column($items, 'folder') === ['Invoices', 'Invoices/Private'],
        ))->willReturn(['upload' => [], 'reuse' => [], 'skip' => []]);

        $this->reads->handle('15', 'ada');
    }

    public function testAFolderOfMoreThanFiveHundredFilesIsListedInFullInCallsOfFiveHundred(): void
    {
        $this->listing = $this->manyFiles(1201);
        $this->scope->method('mayRead')->willReturn(true);
        $this->links->method('claimPickup')->willReturn($this->claim());
        $sizes = [];
        $this->links->expects($this->exactly(3))->method('submitPickupListing')->willReturnCallback(
            function (string $id, string $uid, array $items) use (&$sizes): array {
                $sizes[] = count($items);
                $last = $items[count($items) - 1]['id'];

                return ['upload' => [], 'reuse' => [], 'skip' => [['id' => $last, 'reason' => 'over_limit']]];
            },
        );
        $this->links->expects($this->once())->method('reportPickupResult')->with('15', 'ada', $this->callback(
            static fn (array $body): bool => $body['listed'] === 1201
                && array_column($body['skipped'], 'id') === ['1500', '2000', '2201'],
        ));

        $this->reads->handle('15', 'ada');

        self::assertSame([500, 500, 201], $sizes);
    }

    public function testAListingSynaplanRefusesEndsTheRead(): void
    {
        $this->scope->method('mayRead')->willReturn(true);
        $this->links->method('claimPickup')->willReturn($this->claim());
        $this->links->method('submitPickupListing')->willReturn(['status_code' => 500]);
        $this->links->expects($this->never())->method('uploadPickupFile');
        $this->links->expects($this->once())->method('reportPickupResult')->with('15', 'ada', $this->callback(
            static fn (array $body): bool => $body['status'] === 'failed' && $body['error_code'] === 'unreachable',
        ));

        $this->reads->handle('15', 'ada');
    }

    public function testAClaimedReadThatBreaksStillReportsAFailure(): void
    {
        $this->scope->method('mayRead')->willReturn(true);
        $this->links->method('claimPickup')->willReturn($this->claim());
        $this->links->method('submitPickupListing')->willThrowException(new \RuntimeException('cURL error 28: timed out'));
        $this->links->expects($this->once())->method('reportPickupResult')->with('15', 'ada', $this->callback(
            static fn (array $body): bool => $body['status'] === 'failed' && $body['error_code'] === 'unreachable',
        ));

        $this->reads->handle('15', 'ada');
    }

    public function testAReadThatCannotBeClaimedReportsNothing(): void
    {
        $this->links->method('claimPickup')->willThrowException(new \RuntimeException('cURL error 7'));
        $this->links->expects($this->never())->method('reportPickupResult');

        $this->reads->handle('15', 'ada');
    }

    public function testAFileChangedSinceTheListingIsNotSentUnderItsOldVersion(): void
    {
        $this->etag = 'etag-2';
        $this->scope->method('mayRead')->willReturn(true);
        $this->links->method('claimPickup')->willReturn($this->claim());
        $this->links->method('submitPickupListing')->willReturn(['upload' => ['7'], 'reuse' => [], 'skip' => []]);
        $this->links->expects($this->never())->method('uploadPickupFile');
        $this->links->expects($this->once())->method('reportPickupResult')->with('15', 'ada', $this->callback(
            static fn (array $body): bool => $body['status'] === 'partial'
                && ($body['skipped'][0]['reason'] ?? '') === 'changed_since_listing',
        ));

        $this->reads->handle('15', 'ada');
    }

    public function testASelectorThatEscapesItsFolderIsRefused(): void
    {
        $claim = $this->claim();
        $claim['selector'] = ['folder_path' => 'Invoices/../HR', 'recursive' => false];
        $this->links->method('claimPickup')->willReturn($claim);
        $this->links->expects($this->never())->method('submitPickupListing');
        $this->links->expects($this->once())->method('reportPickupResult')->with('15', 'ada', $this->callback(
            static fn (array $body): bool => $body['status'] === 'failed' && $body['error_code'] === 'not_found',
        ));

        $this->reads->handle('15', 'ada');
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function selectorsNamingNoFolder(): iterable
    {
        yield 'nothing' => [['recursive' => true]];
        yield 'an empty path' => [['folder_path' => '', 'recursive' => true]];
        yield 'blanks' => [['folder_id' => '', 'folder_path' => '  ', 'recursive' => true]];
        yield 'the root' => [['folder_path' => '/', 'recursive' => true]];
        yield 'the root by its id' => [['folder_id' => '5', 'folder_path' => 'Invoices', 'recursive' => true]];
        yield 'an id of a file' => [['folder_id' => '7', 'recursive' => true]];
        yield 'an id of nothing' => [['folder_id' => '99', 'folder_path' => 'Invoices', 'recursive' => true]];
    }

    /**
     * @param array<string, mixed> $selector
     */
    #[DataProvider('selectorsNamingNoFolder')]
    public function testASelectorNamingNoFolderIsRefusedEvenWhenAllFilesMayBeRead(array $selector): void
    {
        $this->scope->method('mayRead')->willReturn(true);
        $claim = $this->claim();
        $claim['selector'] = $selector;
        $claim['read_scope'] = ['mode' => 'all', 'folders' => []];
        $this->links->method('claimPickup')->willReturn($claim);
        $this->links->expects($this->never())->method('submitPickupListing');
        $this->links->expects($this->once())->method('reportPickupResult')->with('15', 'ada', $this->callback(
            static fn (array $body): bool => $body['status'] === 'failed' && $body['error_code'] === 'not_found',
        ));

        $this->reads->handle('15', 'ada');
    }

    public function testAQueuedUploadRechecksTheSettingBeforeReading(): void
    {
        $this->scope->method('mayRead')->willReturnOnConsecutiveCalls(true, false);
        $this->links->method('claimPickup')->willReturn($this->claim());
        $this->links->method('submitPickupListing')->willReturnCallback($this->answeredLate(['upload' => ['7'], 'reuse' => [], 'skip' => []]));
        $this->reads->handle('15', 'ada');
        $this->links->expects($this->never())->method('uploadPickupFile');
        $this->links->expects($this->once())->method('reportPickupResult')->with('15', 'ada', $this->callback(
            static fn (array $body): bool => $body['status'] === 'partial'
                && ($body['skipped'][0]['reason'] ?? '') === 'outside_scope',
        ));

        $this->reads->finish('15', 'ada');
    }

    public function testUploadsLeftOverAreKeptOutOfTheJobArgumentAndFinishedLater(): void
    {
        $this->scope->method('mayRead')->willReturn(true);
        $this->links->method('claimPickup')->willReturn($this->claim());
        $this->links->method('submitPickupListing')->willReturnCallback($this->answeredLate(['upload' => ['7'], 'reuse' => [], 'skip' => []]));
        $this->jobs->expects($this->once())->method('add')->with(PickupJob::class, ['id' => '15', 'uid' => 'ada']);
        $this->links->expects($this->once())->method('uploadPickupFile')->with('15', 'ada', '7', 'etag-1', 'hello', 'a.txt', 'text/plain', 120)
            ->willReturn(['status_code' => 200]);
        $this->links->expects($this->once())->method('reportPickupResult')->with('15', 'ada', [
            'status' => 'done',
            'listed' => 1,
            'sent' => 1,
            'skipped' => [],
        ]);

        $this->reads->handle('15', 'ada');
        self::assertCount(1, $this->leftOver());

        $this->reads->finish('15', 'ada');
        self::assertSame([], $this->leftOver());

        $this->reads->finish('15', 'ada');
    }

    public function testAFileThatTakesTheRestOfTheTimeToReadIsSentByTheJob(): void
    {
        $checks = 0;
        $this->scope->method('mayRead')->willReturnCallback(function () use (&$checks): bool {
            if (++$checks === 2) {
                $this->now += FolderRead::BUDGET_SECONDS - 1;
            }

            return true;
        });
        $this->links->method('claimPickup')->willReturn($this->claim());
        $this->links->method('submitPickupListing')->willReturn(['upload' => ['7'], 'reuse' => [], 'skip' => []]);
        $uploadSeconds = [];
        $this->links->method('uploadPickupFile')->willReturnCallback(
            function (string $id, string $uid, string $remoteId, string $version, string $bytes, string $name, string $mime, int $seconds) use (&$uploadSeconds): array {
                $uploadSeconds[] = $seconds;

                return ['status_code' => 200];
            },
        );
        $this->jobs->expects($this->once())->method('add')->with(PickupJob::class, ['id' => '15', 'uid' => 'ada']);

        $this->reads->handle('15', 'ada');
        self::assertSame([], $uploadSeconds);

        $this->reads->finish('15', 'ada');
        self::assertSame([120], $uploadSeconds);
    }

    public function testAResultTheRequestHasNoTimeLeftForIsSentByTheJob(): void
    {
        $this->scope->method('mayRead')->willReturn(true);
        $this->links->method('claimPickup')->willReturn($this->claim());
        $this->links->method('submitPickupListing')->willReturn(['upload' => ['7'], 'reuse' => [], 'skip' => []]);
        $this->links->expects($this->once())->method('uploadPickupFile')->willReturnCallback(function (): array {
            $this->now += FolderRead::BUDGET_SECONDS;

            return ['status_code' => 200];
        });
        $reports = [];
        $this->links->method('reportPickupResult')->willReturnCallback(
            function (string $id, string $uid, array $body, int $seconds) use (&$reports): void {
                $reports[] = [$body['status'], $body['sent'], $seconds];
            },
        );
        $this->jobs->expects($this->once())->method('add')->with(PickupJob::class, ['id' => '15', 'uid' => 'ada']);

        $this->reads->handle('15', 'ada');
        self::assertSame([], $reports);

        $this->reads->finish('15', 'ada');
        self::assertSame([['done', 1, 30]], $reports);
        self::assertSame([], $this->leftOver());
    }

    public function testARefusalTheRequestHasNoTimeLeftForIsSentByTheJob(): void
    {
        $this->scope->method('mayRead')->willReturn(false);
        $this->links->method('claimPickup')->willReturnCallback(function (): array {
            $this->now += FolderRead::BUDGET_SECONDS;

            return $this->claim();
        });
        $reports = [];
        $this->links->method('reportPickupResult')->willReturnCallback(
            function (string $id, string $uid, array $body, int $seconds) use (&$reports): void {
                $reports[] = [$body['error_code'] ?? '', $seconds];
            },
        );
        $this->jobs->expects($this->once())->method('add')->with(PickupJob::class, ['id' => '15', 'uid' => 'ada']);

        $this->reads->handle('15', 'ada');
        self::assertSame([], $reports);

        $this->reads->finish('15', 'ada');
        self::assertSame([['outside_scope', 30]], $reports);
    }

    public function testAQueuedFileThatBreaksIsSkippedAndTheRestIsStillSent(): void
    {
        $broken = $this->createStub(File::class);
        $broken->method('getId')->willReturn(9);
        $broken->method('getName')->willReturn('c.txt');
        $broken->method('getMTime')->willReturn(time());
        $broken->method('getPath')->willReturn('/ada/files/Invoices/c.txt');
        $this->listing = [$broken, ...$this->listing];
        $this->scope->method('mayRead')->willReturn(true);
        $this->links->method('claimPickup')->willReturn($this->claim());
        $this->links->method('submitPickupListing')->willReturnCallback($this->answeredLate(['upload' => ['9', '7'], 'reuse' => [], 'skip' => []]));
        $this->reads->handle('15', 'ada');
        $this->links->expects($this->once())->method('uploadPickupFile')->with('15', 'ada', '7')->willReturn(['status_code' => 200]);
        $this->links->expects($this->once())->method('reportPickupResult')->with('15', 'ada', [
            'status' => 'partial',
            'listed' => 2,
            'sent' => 1,
            'skipped' => [['id' => '9', 'name' => '9', 'reason' => 'unreadable']],
        ]);

        $this->reads->finish('15', 'ada');
    }

    public function testAJobThatCannotOpenThePersonsFilesReportsWhatTheReadHad(): void
    {
        $this->scope->method('mayRead')->willReturn(true);
        $this->links->method('claimPickup')->willReturn($this->claim());
        $this->links->method('submitPickupListing')->willReturnCallback($this->answeredLate(['upload' => ['7'], 'reuse' => [], 'skip' => []]));
        $this->reads->handle('15', 'ada');
        $this->homeAway = true;
        $this->links->expects($this->never())->method('uploadPickupFile');
        $this->links->expects($this->once())->method('reportPickupResult')->with('15', 'ada', [
            'status' => 'failed',
            'listed' => 1,
            'sent' => 0,
            'skipped' => [],
            'error_code' => 'unreachable',
        ]);

        $this->reads->finish('15', 'ada');
    }

    public function testAReadThatRunsOutOfTimeWhileGoingThroughTheFolderIsReadAgainByTheJob(): void
    {
        $opened = 0;
        $later = $this->createStub(Folder::class);
        $later->method('getPath')->willReturn('/ada/files/Invoices/Later');
        $later->method('getName')->willReturn('Later');
        $later->method('getDirectoryListing')->willReturnCallback(static function () use (&$opened): array {
            $opened++;

            return [];
        });
        $this->listing[] = $this->privateFolder();
        $this->listing[] = $later;
        $this->privateTakes = FolderRead::BUDGET_SECONDS;
        $this->scope->method('mayRead')->willReturn(true);
        $claim = $this->claim();
        $claim['selector'] = ['folder_path' => 'Invoices', 'recursive' => true];
        $this->links->method('claimPickup')->willReturn($claim);
        $this->jobs->expects($this->once())->method('add')->with(PickupJob::class, ['id' => '15', 'uid' => 'ada']);
        $this->links->expects($this->once())->method('submitPickupListing')->with('15', 'ada', $this->callback(
            static fn (array $items): bool => array_column($items, 'name') === ['a.txt', 'b.txt'],
        ), 30)->willReturn(['upload' => [], 'reuse' => [], 'skip' => []]);
        $this->links->expects($this->once())->method('reportPickupResult')->with('15', 'ada', $this->callback(
            static fn (array $body): bool => $body['status'] === 'done' && $body['listed'] === 2,
        ));

        $this->reads->handle('15', 'ada');
        self::assertCount(1, $this->leftOver(), 'Synaplan heard of no file yet');
        self::assertSame(0, $opened, 'the request stops going through the folder once its time is up');

        $this->reads->finish('15', 'ada');
        self::assertSame([], $this->leftOver());
        self::assertSame(1, $opened);
    }

    public function testAFolderIsNotListedOnceAClaimTookTheRequestsTime(): void
    {
        $this->scope->method('mayRead')->willReturn(true);
        $this->links->method('claimPickup')->willReturnCallback(function (): array {
            $this->now += FolderRead::BUDGET_SECONDS;

            return $this->claim();
        });
        $this->links->expects($this->once())->method('submitPickupListing')->willReturn(['upload' => [], 'reuse' => [], 'skip' => []]);
        $this->jobs->expects($this->once())->method('add')->with(PickupJob::class, ['id' => '15', 'uid' => 'ada']);

        $this->reads->handle('15', 'ada');
        self::assertSame(0, $this->invoicesListed);
        self::assertCount(1, $this->leftOver());

        $this->reads->finish('15', 'ada');
        self::assertSame(1, $this->invoicesListed);
    }

    public function testNoListingCallOutlastsTheRequestAndTheJobListsAgain(): void
    {
        $this->listing = $this->manyFiles(1201);
        $this->scope->method('mayRead')->willReturn(true);
        $this->links->method('claimPickup')->willReturn($this->claim());
        $timeouts = [];
        $this->links->expects($this->exactly(5))->method('submitPickupListing')->willReturnCallback(
            function (string $id, string $uid, array $items, int $timeout) use (&$timeouts): array {
                $timeouts[] = $timeout;
                $this->now += 10;

                return ['upload' => [], 'reuse' => [], 'skip' => []];
            },
        );
        $this->links->expects($this->once())->method('reportPickupResult')->with('15', 'ada', $this->callback(
            static fn (array $body): bool => $body['status'] === 'done' && $body['listed'] === 1201,
        ));

        $this->reads->handle('15', 'ada');
        self::assertSame([20, 10], $timeouts, 'the third call would have no time left');

        $this->reads->finish('15', 'ada');
        self::assertSame([20, 10, 30, 30, 30], $timeouts);
    }

    public function testALongRunningJobFindsUploadsLeftAfterItFirstReadThePersonsSettings(): void
    {
        $this->scope->method('mayRead')->willReturn(true);
        $this->links->method('claimPickup')->willReturn($this->claim());
        $this->links->method('submitPickupListing')->willReturnCallback($this->answeredLate(['upload' => ['7'], 'reuse' => [], 'skip' => []]));
        $this->reads->handle('15', 'ada');
        $left = $this->leftOver();
        $key = (string) array_key_first($left);
        unset($this->config->user['ada']['oco_synaplan'][$key]);
        // The job's process read Ada's settings before the request stored what was left.
        $this->config->keepWhatThisRequestRead();
        $this->config->getUserValue('ada', 'oco_synaplan', $key);
        $this->config->user['ada']['oco_synaplan'][$key] = $left[$key];
        $this->links->expects($this->once())->method('uploadPickupFile')->willReturn(['status_code' => 200]);

        $this->reads->finish('15', 'ada');
    }

    public function testAFailedUploadClosesTheReadAsPartial(): void
    {
        $this->scope->method('mayRead')->willReturn(true);
        $this->links->method('claimPickup')->willReturn($this->claim());
        $this->links->method('submitPickupListing')->willReturn(['upload' => ['7'], 'reuse' => [], 'skip' => []]);
        $this->links->method('uploadPickupFile')->willThrowException(new \RuntimeException('timed out'));
        $this->links->expects($this->once())->method('reportPickupResult')->with('15', 'ada', $this->callback(
            static fn (array $body): bool => $body['status'] === 'partial'
                && ($body['skipped'][0]['reason'] ?? '') === 'unreachable',
        ));

        $this->reads->handle('15', 'ada');
    }

    public function testAFileSynaplanDoesNotReadLeavesTheReadDone(): void
    {
        $this->scope->method('mayRead')->willReturn(true);
        $this->links->method('claimPickup')->willReturn($this->claim());
        $this->links->method('submitPickupListing')->willReturn([
            'upload' => [],
            'reuse' => [],
            'skip' => [['id' => '7', 'reason' => 'type_not_read']],
        ]);
        $this->links->expects($this->once())->method('reportPickupResult')->with('15', 'ada', [
            'status' => 'done',
            'listed' => 1,
            'sent' => 0,
            'skipped' => [['id' => '7', 'name' => 'a.txt', 'reason' => 'type_not_read']],
        ]);

        $this->reads->handle('15', 'ada');
    }

    public function testOnlyFilesChangedWithinTheAskedTimeAreListed(): void
    {
        self::assertSame(0, FolderRead::since(null));
        self::assertSame(0, FolderRead::since('7 days'));
        self::assertEqualsWithDelta(time() - 7 * 86400, FolderRead::since('7d'), 2);
        self::assertEqualsWithDelta(time() - 12 * 3600, FolderRead::since('12h'), 2);
        self::assertEqualsWithDelta(time() - 2 * 604800, FolderRead::since('2w'), 2);
    }

    private function privateFolder(): Folder
    {
        $private = $this->createStub(Folder::class);
        $private->method('getPath')->willReturn('/ada/files/Invoices/Private');
        $private->method('getName')->willReturn('Private');
        $private->method('getDirectoryListing')->willReturnCallback(function (): array {
            $this->now += $this->privateTakes;

            return $this->private;
        });

        return $private;
    }

    /**
     * @return list<File>
     */
    private function manyFiles(int $count): array
    {
        $files = [];
        for ($i = 1; $i <= $count; $i++) {
            $file = $this->createStub(File::class);
            $file->method('getId')->willReturn(1000 + $i);
            $file->method('getName')->willReturn('f' . $i . '.txt');
            $file->method('getMTime')->willReturn(time());
            $file->method('getPath')->willReturn('/ada/files/Invoices/f' . $i . '.txt');
            $files[] = $file;
        }

        return $files;
    }

    /**
     * Synaplan's answer to a listing, coming when the request has no time
     * left to send a file.
     *
     * @param array<string, mixed> $answer
     * @return \Closure(): array<string, mixed>
     */
    private function answeredLate(array $answer): \Closure
    {
        return function () use ($answer): array {
            $this->now += FolderRead::BUDGET_SECONDS - 1;

            return $answer;
        };
    }

    /**
     * What a read left for its job in Ada's settings.
     *
     * @return array<string, string>
     */
    private function leftOver(): array
    {
        return array_filter(
            $this->config->user['ada']['oco_synaplan'] ?? [],
            static fn (string $key): bool => str_starts_with($key, 'pickup_pending_'),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function claim(): array
    {
        return [
            'external_id' => 'ada',
            'mode' => 'fetch',
            'selector' => ['folder_path' => 'Invoices', 'recursive' => false],
        ];
    }
}
