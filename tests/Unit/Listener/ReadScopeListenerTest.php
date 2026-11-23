<?php

declare(strict_types=1);

namespace OCA\OcoSynaplan\Tests\Unit\Listener;

use OCA\OcoSynaplan\Listener\ReadScopeListener;
use OCA\OcoSynaplan\Service\ReadScope;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\IUser;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ReadScopeListenerTest extends TestCase
{
    private ReadScope&MockObject $readScope;

    protected function setUp(): void
    {
        $this->readScope = $this->createMock(ReadScope::class);
    }

    public function testAMovedFolderBringsThePathsOfThePersonWhoMovedItUpToDate(): void
    {
        $this->readScope->expects($this->once())->method('follow')->with('ada');

        (new ReadScopeListener($this->readScope))->handle($this->folder('/ada/files/Archive/Invoices', 'ada'));
    }

    public function testASharedFolderMovedByARecipientAlsoUpdatesTheOwner(): void
    {
        $people = [];
        $this->readScope->method('follow')->willReturnCallback(
            static function (string $uid) use (&$people): bool {
                $people[] = $uid;

                return false;
            },
        );

        (new ReadScopeListener($this->readScope))->handle($this->folder('/bob/files/From Ada/Invoices', 'ada'));

        self::assertSame(['bob', 'ada'], $people);
    }

    public function testARenamedFileChangesNoFolder(): void
    {
        $this->readScope->expects($this->never())->method('follow');
        $file = $this->createStub(File::class);
        $file->method('getPath')->willReturn('/ada/files/Invoices/a.txt');

        (new ReadScopeListener($this->readScope))->handle($file);
    }

    public function testAFailedReportNeverBreaksTheRename(): void
    {
        $this->readScope->method('follow')->willThrowException(new \RuntimeException('down'));

        (new ReadScopeListener($this->readScope))->handle($this->folder('/ada/files/Invoices', 'ada'));

        $this->addToAssertionCount(1);
    }

    public function testAFolderWhoseOwnerCannotBeToldStillUpdatesThePersonWhoMovedIt(): void
    {
        $this->readScope->expects($this->once())->method('follow')->with('ada');
        $folder = $this->createStub(Folder::class);
        $folder->method('getPath')->willReturn('/ada/files/Invoices');
        $folder->method('getOwner')->willThrowException(new \RuntimeException('storage gone'));

        (new ReadScopeListener($this->readScope))->handle($folder);
    }

    private function folder(string $path, string $owner): Folder
    {
        $user = $this->createStub(IUser::class);
        $user->method('getUID')->willReturn($owner);
        $folder = $this->createStub(Folder::class);
        $folder->method('getPath')->willReturn($path);
        $folder->method('getOwner')->willReturn($user);

        return $folder;
    }
}
