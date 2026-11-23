<?php

declare(strict_types=1);

namespace OCA\OcoSynaplan\Tests\Unit\Listener;

use OCA\OcoSynaplan\BackgroundJob\SourceEventsJob;
use OCA\OcoSynaplan\Listener\SourceEventListener;
use OCA\OcoSynaplan\Tests\Unit\Support\BuildsServices;
use OCP\BackgroundJob\IJobList;
use OCP\Files\File;
use OCP\Files\FileInfo;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\IUser;
use PHPUnit\Framework\TestCase;

final class SourceEventListenerTest extends TestCase
{
    use BuildsServices;

    /** @var list<array{0: string, 1: mixed}> */
    private array $queued = [];

    /** @var array<string, Folder> */
    private array $userFolders = [];

    protected function setUp(): void
    {
        $this->setUpServices(['mode' => 'link', 'link_instance_id' => 'client-1']);
        $this->signIn($this->person('ada'));
        $this->queued = [];
        $this->userFolders = [];
    }

    public function testAChangedFileIsReportedForThePersonInWhoseFilesItChanged(): void
    {
        $this->listener()->written($this->file(7, '/ada/files/Projects/plan.docx', 'ada', '"e2"'));

        self::assertSame([[SourceEventsJob::class, [
            'uid' => 'ada',
            'id' => '7',
            'event' => 'changed',
            'version' => '"e2"',
            'path' => 'Projects/plan.docx',
        ]]], $this->queued);
    }

    public function testASharedFileChangedByARecipientIsReportedForItsOwnerToo(): void
    {
        $this->userFolders['ada'] = $this->userFolder([7 => $this->file(7, '/ada/files/Projects/plan.docx', 'ada')]);

        $this->listener()->written($this->file(7, '/bob/files/From Ada/plan.docx', 'ada', '"e2"'));

        self::assertSame(
            [['bob', 'From Ada/plan.docx'], ['ada', 'Projects/plan.docx']],
            array_map(static fn (array $job): array => [$job[1]['uid'], $job[1]['path']], $this->queued),
        );
    }

    public function testANewFolderIsNoChangeOfAFile(): void
    {
        $this->listener()->written($this->folder(3, '/ada/files/New', 'ada', []));

        self::assertSame([], $this->queued);
    }

    public function testAMovedFolderReportsItselfAndEverythingInIt(): void
    {
        $folder = $this->folder(3, '/ada/files/Archive', 'ada', [
            $this->file(4, '/ada/files/Archive/a.txt', 'ada'),
            $this->folder(5, '/ada/files/Archive/Old', 'ada', [$this->file(6, '/ada/files/Archive/Old/b.txt', 'ada')]),
        ]);

        $this->listener()->renamed($folder);

        self::assertSame(
            [['3', 'moved', 'Archive'], ['4', 'moved', 'Archive/a.txt'], ['5', 'moved', 'Archive/Old'], ['6', 'moved', 'Archive/Old/b.txt']],
            array_map(static fn (array $job): array => [$job[1]['id'], $job[1]['event'], $job[1]['path']], $this->queued),
        );
    }

    public function testADeleteIsReportedOnceItWentThroughWithWhatTheFolderHeld(): void
    {
        $listener = $this->listener();
        $folder = $this->folder(3, '/ada/files/Archive', 'ada', [$this->file(4, '/ada/files/Archive/a.txt', 'ada')]);

        $listener->deleting($folder);
        self::assertSame([], $this->queued, 'a delete can still fail');
        $gone = $this->createStub(Folder::class);
        $gone->method('getPath')->willReturn('/ada/files/Archive');
        $gone->method('getDirectoryListing')->willThrowException(new NotFoundException());
        $listener->deleted($gone);

        self::assertSame(
            [['3', 'deleted'], ['4', 'deleted']],
            array_map(static fn (array $job): array => [$job[1]['id'], $job[1]['event']], $this->queued),
        );
    }

    public function testADeleteThatWasNotSeenStartingReportsNothing(): void
    {
        $this->listener()->deleted($this->file(4, '/ada/files/a.txt', 'ada'));

        self::assertSame([], $this->queued);
    }

    public function testAFolderRestoredFromTheTrashComesBackWithWhatItHolds(): void
    {
        $restored = $this->folder(3, '/ada/files/Archive', 'ada', [$this->file(4, '/ada/files/Archive/a.txt', 'ada', '"e1"')]);
        $this->userFolders['ada'] = $this->userFolder([], ['Archive' => $restored]);

        $this->listener()->restored(['filePath' => '/Archive', 'trashPath' => '/Archive.d1800000000']);

        self::assertSame(
            [['ada', '3', 'restored', 'Archive'], ['ada', '4', 'restored', 'Archive/a.txt']],
            array_map(static fn (array $job): array => [$job[1]['uid'], $job[1]['id'], $job[1]['event'], $job[1]['path']], $this->queued),
        );
    }

    public function testARestoreWithoutAPersonOrAPathReportsNothing(): void
    {
        $this->userFolders['ada'] = $this->userFolder([]);
        $this->listener()->restored(['filePath' => '/']);
        $this->listener()->restored(['filePath' => '/gone.txt']);
        $this->signIn(null);
        $this->listener()->restored(['filePath' => '/a.txt']);

        self::assertSame([], $this->queued);
    }

    public function testAPathLongerThanSynaplanKeepsIsSentEmptyAndTheVersionIsCut(): void
    {
        $name = str_repeat('ä', 128) . '.txt';

        $this->listener()->written($this->file(7, '/ada/files/' . $name, 'ada', str_repeat('e', 300)));

        self::assertSame('', $this->queued[0][1]['path']);
        self::assertSame(255, strlen($this->queued[0][1]['version']));
    }

    public function testOnlyThePersonsOwnFilesAreReported(): void
    {
        foreach (['/ada/files_trashbin/files/a.txt.d1800000000', '/ada/files_versions/a.txt.v1', '/ada/uploads/123/1', '/ada/files/', '/appdata_oc/preview/7'] as $path) {
            $this->listener()->written($this->file(7, $path, 'ada'));
        }

        self::assertSame([], $this->queued);
    }

    public function testAFileWithoutAnOwnerIsReportedForThePersonAlone(): void
    {
        $file = $this->createStub(File::class);
        $file->method('getType')->willReturn(FileInfo::TYPE_FILE);
        $file->method('getId')->willReturn(7);
        $file->method('getPath')->willReturn('/ada/files/a.txt');
        $file->method('getEtag')->willReturn('"e1"');
        $file->method('getOwner')->willThrowException(new \RuntimeException('storage gone'));

        $this->listener()->written($file);

        self::assertSame(['ada'], array_map(static fn (array $job): string => $job[1]['uid'], $this->queued));
    }

    /**
     * @return array<string, array{0: array<string, string>}>
     */
    public static function silences(): array
    {
        return [
            'muted for development' => [['dev_mute_events' => '1']],
            'one shared key' => [['mode' => 'shared']],
            'not registered' => [['link_instance_id' => '']],
        ];
    }

    /**
     * @param array<string, string> $values
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('silences')]
    public function testNothingIsQueuedWhereSynaplanCannotHearIt(array $values): void
    {
        foreach ($values as $key => $value) {
            $this->config->setAppValue('oco_synaplan', $key, $value);
        }
        $file = $this->file(7, '/ada/files/a.txt', 'ada');
        $this->userFolders['ada'] = $this->userFolder([], ['a.txt' => $file]);

        $listener = $this->listener();
        $listener->written($file);
        $listener->renamed($file);
        $listener->deleting($file);
        $listener->deleted($file);
        $listener->restored(['filePath' => '/a.txt']);

        self::assertSame([], $this->queued);
    }

    private function listener(): SourceEventListener
    {
        $jobs = $this->createMock(IJobList::class);
        $jobs->method('add')->willReturnCallback(function (mixed $job, mixed $argument = null): void {
            $this->queued[] = [is_string($job) ? $job : $job::class, $argument];
        });
        $root = $this->createStub(IRootFolder::class);
        $root->method('getUserFolder')->willReturnCallback(fn (string $uid): Folder => $this->userFolders[$uid] ?? $this->userFolder([]));

        return new SourceEventListener($this->synaplanConfig(), $this->config, $jobs, $root, $this->session());
    }

    private function owner(string $uid): IUser
    {
        $user = $this->createStub(IUser::class);
        $user->method('getUID')->willReturn($uid);

        return $user;
    }

    private function file(int $id, string $path, string $owner, string $etag = '"e1"'): File
    {
        $file = $this->createStub(File::class);
        $file->method('getType')->willReturn(FileInfo::TYPE_FILE);
        $file->method('getId')->willReturn($id);
        $file->method('getPath')->willReturn($path);
        $file->method('getEtag')->willReturn($etag);
        $file->method('getOwner')->willReturn($this->owner($owner));

        return $file;
    }

    /**
     * @param list<Node> $children
     */
    private function folder(int $id, string $path, string $owner, array $children): Folder
    {
        $folder = $this->createStub(Folder::class);
        $folder->method('getType')->willReturn(FileInfo::TYPE_FOLDER);
        $folder->method('getId')->willReturn($id);
        $folder->method('getPath')->willReturn($path);
        $folder->method('getEtag')->willReturn('"f' . $id . '"');
        $folder->method('getOwner')->willReturn($this->owner($owner));
        $folder->method('getDirectoryListing')->willReturn($children);

        return $folder;
    }

    /**
     * @param array<int, Node>    $byId
     * @param array<string, Node> $byPath
     */
    private function userFolder(array $byId, array $byPath = []): Folder
    {
        $folder = $this->createStub(Folder::class);
        $folder->method('getById')->willReturnCallback(static fn (int $id): array => isset($byId[$id]) ? [$byId[$id]] : []);
        $folder->method('get')->willReturnCallback(static function (string $path) use ($byPath): Node {
            return $byPath[$path] ?? throw new NotFoundException($path);
        });

        return $folder;
    }
}
