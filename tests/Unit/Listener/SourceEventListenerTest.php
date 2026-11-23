<?php

declare(strict_types=1);

namespace OCA\OcoSynaplan\Tests\Unit\Listener;

use OCA\OcoSynaplan\BackgroundJob\SourceEventsJob;
use OCA\OcoSynaplan\Listener\SourceEventListener;
use OCA\OcoSynaplan\Service\ShareRecipients;
use OCA\OcoSynaplan\Tests\Unit\Support\ArraySourceEventQueue;
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

    private ArraySourceEventQueue $events;

    /** @var array<int, list<string>> who else holds each file id, with a credential */
    private array $sharedWith = [];

    protected function setUp(): void
    {
        $this->setUpServices(['mode' => 'link', 'link_instance_id' => 'client-1']);
        $this->signIn($this->person('ada'));
        $this->queued = [];
        $this->userFolders = [];
        $this->sharedWith = [];
        $this->events = new ArraySourceEventQueue();
    }

    public function testAChangedFileIsReportedForThePersonInWhoseFilesItChanged(): void
    {
        $this->listener()->written($this->file(7, '/ada/files/Projects/plan.docx', 'ada', '"e2"'));

        self::assertSame([[
            'uid' => 'ada',
            'id' => '7',
            'event' => 'changed',
            'version' => '"e2"',
            'path' => 'Projects/plan.docx',
        ]], $this->events->events());
        self::assertSame([[SourceEventsJob::class, ['uid' => 'ada']]], $this->queued);
    }

    public function testASharedFileChangedByARecipientIsReportedForItsOwnerToo(): void
    {
        $this->userFolders['ada'] = $this->userFolder([7 => $this->file(7, '/ada/files/Projects/plan.docx', 'ada')]);

        $this->listener()->written($this->file(7, '/bob/files/From Ada/plan.docx', 'ada', '"e2"'));

        self::assertSame(
            [['bob', 'From Ada/plan.docx'], ['ada', 'Projects/plan.docx']],
            array_map(static fn (array $event): array => [$event['uid'], $event['path']], $this->events->events()),
        );
        self::assertSame([[SourceEventsJob::class, ['uid' => 'bob']], [SourceEventsJob::class, ['uid' => 'ada']]], $this->queued);
    }

    public function testANewFolderIsNoChangeOfAFile(): void
    {
        $this->listener()->written($this->folder(3, '/ada/files/New', 'ada', []));

        self::assertSame([], $this->events->rows);
        self::assertSame([], $this->queued);
    }

    public function testAChangeLeavesThePeopleTheFileIsSharedWithToTheJob(): void
    {
        $this->listener()->written($this->file(42, '/ada/files/Specs/spec.pdf', 'ada', '"e2"'));

        self::assertSame(['ada share 42', 'ada send 42'], $this->events->steps());
    }

    public function testADeletedFileIsReportedForThePeopleItWasSharedWithToo(): void
    {
        $this->sharedWith[42] = ['bob'];
        $listener = $this->listener();
        $listener->deleting($this->file(42, '/ada/files/Specs/spec.pdf', 'ada', '"e2"'));

        self::assertSame(['bob held 42'], $this->events->steps(), 'read while the shares are still there');

        $listener->deleted($this->gone('/ada/files/Specs/spec.pdf'));

        self::assertSame([
            ['uid' => 'bob', 'id' => '42', 'event' => 'deleted', 'version' => '"e2"', 'path' => ''],
            ['uid' => 'ada', 'id' => '42', 'event' => 'deleted', 'version' => '"e2"', 'path' => 'Specs/spec.pdf'],
        ], $this->events->events());
        self::assertSame([[SourceEventsJob::class, ['uid' => 'ada']], [SourceEventsJob::class, ['uid' => 'bob']]], $this->queued);
    }

    public function testADeletedSharedFolderTakesItsFilesFromThePeopleItWasSharedWith(): void
    {
        $this->sharedWith[50] = ['bob'];
        $listener = $this->listener();
        $listener->deleting($this->specsFolder());
        $listener->deleted($this->gone('/ada/files/Specs'));

        self::assertSame(['ada send 51', 'ada send 52', 'bob send 51', 'bob send 52', 'bob send 50', 'ada send 50'], $this->events->steps());
    }

    public function testAFolderInsideAShareTheRecipientDeletesQueuesItsFiles(): void
    {
        $listener = $this->listener();
        $listener->deleting($this->oldFolderSeenByBob());
        $listener->deleted($this->gone('/bob/files/Specs/Old'));

        self::assertSame(['bob send 52', 'ada send 52', 'bob send 53', 'ada send 53'], $this->events->steps());
    }

    public function testASharedFolderTheRecipientRenamesIsWalkedFromItsOwnPath(): void
    {
        $this->listener()->renamed($this->specsFolder('/bob/files/Specs 2026', '', 'ada'));

        self::assertSame(['bob send 50', 'bob walk 50'], $this->events->steps(), 'the folder keeps its path for Ada and everyone else');
        self::assertSame('Specs 2026', array_values($this->events->rows)[1]['path']);
        self::assertSame(0, $this->events->cacheReads);
        self::assertSame([[SourceEventsJob::class, ['uid' => 'bob']]], $this->queued);
    }

    public function testAFolderInsideAShareTheRecipientMovesIsWalkedFromItsOwnPath(): void
    {
        $this->listener()->renamed($this->oldFolderSeenByBob());

        self::assertSame(['bob share 53', 'bob send 53', 'bob walk 53', 'ada send 53', 'ada walk 53'], $this->events->steps());
        self::assertSame('Specs/Old', array_values($this->events->rows)[2]['path']);
    }

    public function testAMovedFolderLeavesItsFilesToTheJob(): void
    {
        $this->listener()->renamed($this->specsFolder());

        self::assertSame(['ada share 50', 'ada send 50', 'ada walk 50'], $this->events->steps());
        self::assertSame('Specs', array_values($this->events->rows)[2]['path']);
        self::assertCount(1, array_unique(array_map(static fn (array $row): int => $row['ord'], $this->events->rows)), 'one change, one order');
        self::assertSame(0, $this->events->cacheReads);
        self::assertSame([[SourceEventsJob::class, ['uid' => 'ada']]], $this->queued);
    }

    public function testALaterChangeOfAPersonIsQueuedBehindAnEarlierOne(): void
    {
        $listener = $this->listener();
        $listener->written($this->file(7, '/ada/files/a.txt', 'ada', '"e1"'));
        usleep(2);
        $listener->written($this->file(7, '/ada/files/a.txt', 'ada', '"e2"'));

        self::assertSame(['"e1"', '"e2"'], array_map(static fn (array $event): string => $event['version'], $this->events->events()));
        $sends = array_values(array_filter($this->events->rows, static fn (array $row): bool => $row['step'] === 'send'));
        self::assertLessThan($sends[1]['ord'], $sends[0]['ord']);
    }

    public function testADeletedFolderQueuesItsFilesFromTheFileCacheOnceTheDeletionHappened(): void
    {
        $listener = $this->listener();
        $listener->deleting($this->specsFolder());

        self::assertSame(['ada held 51', 'ada held 52'], $this->events->steps(), 'a delete can still fail');
        self::assertSame([], $this->queued);

        $listener->deleted($this->gone('/ada/files/Specs'));

        self::assertSame([
            ['uid' => 'ada', 'id' => '51', 'event' => 'deleted', 'version' => 'ea', 'path' => ''],
            ['uid' => 'ada', 'id' => '52', 'event' => 'deleted', 'version' => 'eb', 'path' => ''],
            ['uid' => 'ada', 'id' => '50', 'event' => 'deleted', 'version' => 'ef', 'path' => 'Specs'],
        ], $this->events->events());
        self::assertSame(['ada send 51', 'ada send 52', 'ada send 50'], $this->events->steps());
        self::assertSame(1, $this->events->cacheReads);
        self::assertSame([[SourceEventsJob::class, ['uid' => 'ada']]], $this->queued);
    }

    public function testAFolderDeletedThroughAShareQueuesTheFilesTheOwnerKeepsInItForBoth(): void
    {
        $this->userFolders['ada'] = $this->userFolder([50 => $this->folder(50, '/ada/files/Specs', 'ada', [])]);
        $listener = $this->listener();
        $listener->deleting($this->specsFolder('/bob/files/From Ada/Specs'));
        $listener->deleted($this->gone('/bob/files/From Ada/Specs'));

        self::assertSame(
            ['bob 51', 'bob 52', 'ada 51', 'ada 52', 'bob 50 From Ada/Specs', 'ada 50 Specs'],
            array_map(static fn (array $event): string => trim($event['uid'] . ' ' . $event['id'] . ' ' . $event['path']), $this->events->events()),
        );
    }

    public function testADeleteThatWasNotSeenStartingReportsNothing(): void
    {
        $this->listener()->deleted($this->file(4, '/ada/files/a.txt', 'ada'));

        self::assertSame([], $this->events->rows);
        self::assertSame([], $this->queued);
    }

    public function testARestoredFolderQueuesItsFilesFromTheFileCache(): void
    {
        $this->userFolders['ada'] = $this->userFolder([], ['Specs' => $this->specsFolder()]);

        $this->listener()->restored(['filePath' => '/Specs', 'trashPath' => '/Specs.d1800000000']);

        self::assertSame(
            [['ada', '50', 'restored', 'Specs'], ['ada', '51', 'restored', ''], ['ada', '52', 'restored', '']],
            array_map(static fn (array $event): array => [$event['uid'], $event['id'], $event['event'], $event['path']], $this->events->events()),
        );
        self::assertSame(1, $this->events->cacheReads);
        self::assertSame([[SourceEventsJob::class, ['uid' => 'ada']]], $this->queued);
    }

    public function testARestoreWithoutAPersonOrAPathReportsNothing(): void
    {
        $this->userFolders['ada'] = $this->userFolder([]);
        $this->listener()->restored(['filePath' => '/']);
        $this->listener()->restored(['filePath' => '/gone.txt']);
        $this->signIn(null);
        $this->listener()->restored(['filePath' => '/a.txt']);

        self::assertSame([], $this->events->rows);
        self::assertSame([], $this->queued);
    }

    public function testAPathLongerThanSynaplanKeepsIsSentEmptyAndTheVersionIsCut(): void
    {
        $name = str_repeat('ä', 128) . '.txt';

        $this->listener()->written($this->file(7, '/ada/files/' . $name, 'ada', str_repeat('e', 300)));

        self::assertSame('', $this->events->events()[0]['path']);
        self::assertSame(255, strlen($this->events->events()[0]['version']));
    }

    public function testOnlyThePersonsOwnFilesAreReported(): void
    {
        foreach (['/ada/files_trashbin/files/a.txt.d1800000000', '/ada/files_versions/a.txt.v1', '/ada/uploads/123/1', '/ada/files/', '/appdata_oc/preview/7'] as $path) {
            $this->listener()->written($this->file(7, $path, 'ada'));
        }

        self::assertSame([], $this->events->rows);
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

        self::assertSame(['ada'], array_map(static fn (array $event): string => $event['uid'], $this->events->events()));
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

        self::assertSame([], $this->events->rows);
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
        $recipients = $this->createStub(ShareRecipients::class);
        $recipients->method('of')->willReturnCallback(fn (Node $node): array => $this->sharedWith[(int) $node->getId()] ?? []);

        return new SourceEventListener($this->synaplanConfig(), $this->config, $jobs, $root, $this->session(), $this->events, $recipients);
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
     * Ada's folder `Specs` on storage 3, with two files below it in the file
     * cache, as seen at `$path`. Listing it fails the test.
     */
    private function specsFolder(string $path = '/ada/files/Specs', string $internalPath = 'files/Specs', string $owner = 'ada'): Folder
    {
        $this->events->cache(3, 'files/Specs', 50, 'ef');
        $this->events->cache(3, 'files/Specs/a.pdf', 51, 'ea');
        $this->events->cache(3, 'files/Specs/Old/b.pdf', 52, 'eb');
        $this->events->cache(3, 'files/Specsheet.pdf', 60);
        $folder = $this->createMock(Folder::class);
        $folder->method('getType')->willReturn(FileInfo::TYPE_FOLDER);
        $folder->method('getId')->willReturn(50);
        $folder->method('getPath')->willReturn($path);
        $folder->method('getInternalPath')->willReturn($internalPath);
        $folder->method('getEtag')->willReturn('ef');
        if ($owner !== '') {
            $folder->method('getOwner')->willReturn($this->owner($owner));
        }
        $folder->expects($this->never())->method('getDirectoryListing');

        return $folder;
    }

    /**
     * Ada's `Specs/Old` (id 53), as Bob sees it through the share of `Specs`:
     * its internal path is below the share, the file cache keeps Ada's.
     */
    private function oldFolderSeenByBob(): Folder
    {
        $this->specsFolder();
        $this->events->cache(3, 'files/Specs/Old', 53, 'eo');
        $this->userFolders['ada'] = $this->userFolder([53 => $this->folder(53, '/ada/files/Specs/Old', 'ada', [])]);
        $folder = $this->createMock(Folder::class);
        $folder->method('getType')->willReturn(FileInfo::TYPE_FOLDER);
        $folder->method('getId')->willReturn(53);
        $folder->method('getPath')->willReturn('/bob/files/Specs/Old');
        $folder->method('getInternalPath')->willReturn('Old');
        $folder->method('getEtag')->willReturn('eo');
        $folder->method('getOwner')->willReturn($this->owner('ada'));
        $folder->expects($this->never())->method('getDirectoryListing');

        return $folder;
    }

    /**
     * What the hook after a delete gets: the node is gone.
     */
    private function gone(string $path): Folder
    {
        $gone = $this->createStub(Folder::class);
        $gone->method('getPath')->willReturn($path);
        $gone->method('getId')->willThrowException(new NotFoundException());
        $gone->method('getDirectoryListing')->willThrowException(new NotFoundException());

        return $gone;
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
