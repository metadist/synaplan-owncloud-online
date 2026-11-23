<?php

declare(strict_types=1);

namespace OCA\OcoSynaplan\Tests\Unit\Listener;

use OCA\OcoSynaplan\Listener\WatchEventListener;
use OCA\OcoSynaplan\Service\OwnWrites;
use OCA\OcoSynaplan\Service\SynaplanConfig;
use OCA\OcoSynaplan\Service\WatchIndex;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

final class WatchEventListenerTest extends TestCase
{
    private const NOW = 1700000000;
    private const FOLDER_WATCH = ['id' => 5, 'uid' => 'ada', 'kind' => 'folder', 'target' => 30, 'state' => 'active', 'since' => 0, 'reconciled' => 0];

    private WatchIndex&MockObject $index;
    private OwnWrites&Stub $ownWrites;
    private bool $registered = true;
    private string $muted = '0';
    private Folder&Stub $home;

    protected function setUp(): void
    {
        $this->index = $this->createMock(WatchIndex::class);
        $this->ownWrites = $this->createStub(OwnWrites::class);
        $this->home = $this->createStub(Folder::class);
    }

    public function testAFileWrittenIntoAWatchedFolderIsQueuedAsChangedNow(): void
    {
        $this->index->method('queueingOn')->with([7, 30])->willReturn([self::FOLDER_WATCH]);
        $this->index->expects($this->once())->method('enqueue')->with(5, 7, '', self::NOW);

        $this->listener()->written($this->file(7, 30));
    }

    public function testAFileArrivingWhileTheWatchIsPausedWaitsForTheOwnersAnswer(): void
    {
        $paused = ['state' => 'paused'] + self::FOLDER_WATCH;
        $this->index->method('queueingOn')->with([7, 30])->willReturn([$paused]);
        $this->index->expects($this->once())->method('enqueue')->with(5, 7, '', self::NOW);

        $this->listener()->written($this->file(7, 30));
    }

    public function testAFileWatchTakesNewVersionsOfItsFileOnly(): void
    {
        $fileWatch = ['id' => 6, 'kind' => 'file', 'target' => 7] + self::FOLDER_WATCH;
        $this->index->method('queueingOn')->willReturn([$fileWatch, ['id' => 9, 'target' => 7] + self::FOLDER_WATCH]);
        $this->index->expects($this->once())->method('enqueue')->with(6, 7, '', self::NOW);

        $this->listener()->written($this->file(7, 30));
    }

    public function testAFileMovedIntoAWatchedFolderArrivesThere(): void
    {
        $this->index->method('queueingOn')->with([7, 30])->willReturn([self::FOLDER_WATCH]);
        $this->index->method('on')->willReturn([]);
        $this->index->expects($this->once())->method('enqueue')->with(5, 7, '', self::NOW);

        $this->listener()->renamed($this->file(7, 30));
    }

    public function testTheAppsOwnWriteIsIgnored(): void
    {
        $this->ownWrites->method('isOwnVersion')->willReturnCallback(static fn (int $id, string $version): bool => $id === 7 && $version === 'e7');
        $this->index->expects($this->never())->method('queueingOn');
        $this->index->expects($this->never())->method('enqueue');

        $this->listener()->written($this->file(7, 30));
    }

    public function testAPersonsEditOfAFileThisAppWroteIsQueued(): void
    {
        $this->ownWrites->method('isOwnVersion')->willReturnCallback(static fn (int $id, string $version): bool => $version === 'e6');
        $this->index->method('queueingOn')->with([7, 30])->willReturn([self::FOLDER_WATCH]);
        $this->index->expects($this->once())->method('enqueue')->with(5, 7, '', self::NOW);

        $this->listener()->written($this->file(7, 30));
    }

    public function testADeletedWatchedFolderIsReportedAtOnce(): void
    {
        $folder = $this->createStub(Folder::class);
        $folder->method('getId')->willReturn(30);
        $folder->method('getPath')->willReturn('/ada/files/Incoming');
        $gone = $this->createStub(Folder::class);
        $gone->method('getId')->willThrowException(new NotFoundException());
        $gone->method('getPath')->willReturn('/ada/files/Incoming');
        $this->index->method('on')->with([30])->willReturn([self::FOLDER_WATCH, ['id' => 8, 'state' => 'paused'] + self::FOLDER_WATCH]);
        $this->index->expects($this->once())->method('enqueue')->with(5, 30, 'deleted', self::NOW);

        $listener = $this->listener();
        $listener->deleting($folder);
        $listener->deleted($gone);
    }

    public function testADeletionThatNeverWentThroughIsNotReported(): void
    {
        $folder = $this->createStub(Folder::class);
        $folder->method('getId')->willReturn(30);
        $folder->method('getPath')->willReturn('/ada/files/Incoming');
        $other = $this->createStub(Folder::class);
        $other->method('getPath')->willReturn('/ada/files/Other');
        $this->index->expects($this->never())->method('enqueue');

        $listener = $this->listener();
        $listener->deleting($folder);
        $listener->deleted($other);
    }

    public function testAFolderBackFromTheTrashIsReportedToTheWatchThatLostIt(): void
    {
        $folder = $this->createStub(Folder::class);
        $folder->method('getId')->willReturn(30);
        $this->home->method('get')->willReturnCallback(static fn (string $path): Folder => $path === 'Incoming' ? $folder : throw new NotFoundException($path));
        $this->index->method('on')->with([30])->willReturn([['id' => 8, 'state' => WatchIndex::TARGET_GONE] + self::FOLDER_WATCH]);
        $this->index->expects($this->once())->method('enqueue')->with(8, 30, 'restored', self::NOW);

        $this->listener()->restored(['filePath' => '/Incoming']);
    }

    public function testARestoredFolderIsReportedOnlyToAWatchThatLostIt(): void
    {
        $folder = $this->createStub(Folder::class);
        $folder->method('getId')->willReturn(30);
        $this->index->method('on')->with([30])->willReturn([self::FOLDER_WATCH, ['id' => 8, 'state' => WatchIndex::TARGET_GONE] + self::FOLDER_WATCH]);
        $this->index->expects($this->once())->method('enqueue')->with(8, 30, 'restored', self::NOW);

        $this->listener()->renamed($folder);
    }

    public function testAFolderMovedOntoThePlaceOfAPausedOrStuckWatchIsNoRestore(): void
    {
        $folder = $this->createStub(Folder::class);
        $folder->method('getId')->willReturn(30);
        $this->index->method('on')->with([30])->willReturn([
            ['id' => 8, 'state' => 'paused'] + self::FOLDER_WATCH,
            ['id' => 9, 'state' => 'attention'] + self::FOLDER_WATCH,
        ]);
        $this->index->expects($this->never())->method('enqueue');

        $this->listener()->renamed($folder);
    }

    public function testAFolderRestoredBeforeItsDeletionWentOutTakesTheDeletionBack(): void
    {
        $folder = $this->createStub(Folder::class);
        $folder->method('getId')->willReturn(30);
        $this->index->method('on')->with([30])->willReturn([self::FOLDER_WATCH]);
        $this->index->expects($this->never())->method('enqueue');
        $this->index->expects($this->once())->method('cancelEvent')->with(5, 30, 'deleted');

        $this->listener()->renamed($folder);
    }

    public function testNothingIsQueuedWhileMutedOrUnregistered(): void
    {
        $this->index->expects($this->never())->method('queueingOn');
        $this->muted = '1';
        $this->listener()->written($this->file(7, 30));
        $this->muted = '0';
        $this->registered = false;
        $this->listener()->written($this->file(7, 30));
    }

    public function testAFailingIndexNeverFailsTheWrite(): void
    {
        $this->index->method('queueingOn')->willThrowException(new \RuntimeException('no table'));

        $this->listener()->written($this->file(7, 30));
        $this->addToAssertionCount(1);
    }

    private function file(int $id, int $parentId): File
    {
        $parent = $this->createStub(Folder::class);
        $parent->method('getId')->willReturn($parentId);
        $file = $this->createStub(File::class);
        $file->method('getId')->willReturn($id);
        $file->method('getEtag')->willReturn('e' . $id);
        $file->method('getParent')->willReturn($parent);

        return $file;
    }

    private function listener(): WatchEventListener
    {
        $synaplan = $this->createStub(SynaplanConfig::class);
        $synaplan->method('isRegistered')->willReturn($this->registered);
        $config = $this->createStub(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(
            fn (string $app, string $key, string $default = ''): string => $key === 'dev_mute_events' ? $this->muted : $default,
        );
        $time = $this->createStub(ITimeFactory::class);
        $time->method('getTime')->willReturn(self::NOW);

        $root = $this->createStub(IRootFolder::class);
        $root->method('getUserFolder')->willReturnCallback(fn (string $uid): Folder => $uid === 'ada' ? $this->home : throw new NotFoundException($uid));
        $user = $this->createStub(IUser::class);
        $user->method('getUID')->willReturn('ada');
        $session = $this->createStub(IUserSession::class);
        $session->method('getUser')->willReturn($user);

        return new WatchEventListener($synaplan, $config, $this->index, $this->ownWrites, $time, $root, $session);
    }
}
