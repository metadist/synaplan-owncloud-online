<?php

declare(strict_types=1);

namespace OCA\OcoSynaplan\Tests\Unit\Service;

use OCA\OcoSynaplan\Service\SynaplanFolders;
use OCA\OcoSynaplan\Tests\Unit\Support\MemoryConfig;
use OCP\Files\AlreadyExistsException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotEnoughSpaceException;
use OCP\Files\NotFoundException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class SynaplanFoldersTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function kinds(): iterable
    {
        yield 'a document' => ['Q3 plan.docx', 'Synaplan/Documents'];
        yield 'an image' => ['logo.PNG', 'Synaplan/Images'];
        yield 'a video' => ['clip.mp4', 'Synaplan/Video'];
        yield 'a calendar file' => ['meeting.ics', 'Synaplan/Calendar'];
        yield 'anything else' => ['archive.zip', 'Synaplan'];
        yield 'no extension' => ['README', 'Synaplan'];
    }

    #[DataProvider('kinds')]
    public function testEachKindHasItsFolder(string $name, string $path): void
    {
        self::assertSame($path, (new SynaplanFolders(new MemoryConfig()))->kindPath('ada', $name));
    }

    public function testTheChosenFolderTakesThePlaceOfTheSynaplanFolder(): void
    {
        $config = new MemoryConfig();
        $config->setUserValue('ada', 'oco_synaplan', SynaplanFolders::COPY_FOLDER, 'Projects/Q3');
        $config->setUserValue('bob', 'oco_synaplan', SynaplanFolders::COPY_FOLDER, '../../elsewhere');
        $folders = new SynaplanFolders($config);

        self::assertSame('Projects/Q3/Documents', $folders->kindPath('ada', 'plan.docx'));
        self::assertSame('Synaplan/Documents', $folders->kindPath('bob', 'plan.docx'), 'a stored folder that leaves the files is ignored');
    }

    public function testAFolderIsCleanedOrRefused(): void
    {
        self::assertSame('', SynaplanFolders::cleanFolder(' / '));
        self::assertSame('Projects/Q3', SynaplanFolders::cleanFolder('/Projects/Q3/'));
        self::assertSame('Projects/Q3', SynaplanFolders::cleanFolder('\\Projects\\Q3'));
        foreach (['Projects/../..', './Projects', 'Projects//Q3', 'Projects/ /Q3'] as $folder) {
            try {
                SynaplanFolders::cleanFolder($folder);
                self::fail($folder . ' must be refused');
            } catch (\InvalidArgumentException) {
            }
        }
    }

    public function testAFileNameIsItsLastSegment(): void
    {
        self::assertSame('report.pdf', SynaplanFolders::fileName('../../report.pdf'));
        self::assertSame('report.pdf', SynaplanFolders::fileName('C:\\Users\\ada\\report.pdf'));
        self::assertSame('file', SynaplanFolders::fileName('..'));
        self::assertSame('file', SynaplanFolders::fileName(''));
    }

    public function testAMissingFolderIsMadeAndAFileInTheWayIsNotAFolder(): void
    {
        $inner = $this->createMock(Folder::class);
        $root = $this->createMock(Folder::class);
        $root->method('nodeExists')->willReturnCallback(static fn (string $name): bool => $name === 'Synaplan');
        $root->method('get')->with('Synaplan')->willReturn($inner);
        $inner->method('nodeExists')->willReturn(false);
        $made = $this->createMock(Folder::class);
        $inner->expects($this->once())->method('newFolder')->with('Images')->willReturn($made);

        self::assertSame($made, SynaplanFolders::ensure($root, 'Synaplan/Images'));

        $blocked = $this->createMock(Folder::class);
        $blocked->method('nodeExists')->willReturn(true);
        $blocked->method('get')->willReturn($this->createMock(File::class));
        $this->expectException(NotFoundException::class);
        SynaplanFolders::ensure($blocked, 'Synaplan');
    }

    public function testATakenNameGetsANumberUntilTwentyFilesHaveIt(): void
    {
        $taken = ['plan.docx' => true];
        $folder = $this->createMock(Folder::class);
        $folder->method('nodeExists')->willReturnCallback(static function (string $name) use (&$taken): bool {
            return isset($taken[$name]);
        });

        self::assertSame('plan (2).docx', SynaplanFolders::freeName($folder, 'plan.docx', SynaplanFolders::MAX_SAME_NAME));
        for ($n = 2; $n <= 19; $n++) {
            $taken['plan (' . $n . ').docx'] = true;
        }
        self::assertSame('plan (20).docx', SynaplanFolders::freeName($folder, 'plan.docx', SynaplanFolders::MAX_SAME_NAME));
        $taken['plan (20).docx'] = true;
        $this->expectException(AlreadyExistsException::class);
        SynaplanFolders::freeName($folder, 'plan.docx', SynaplanFolders::MAX_SAME_NAME);
    }

    public function testAWriteThatFailsLeavesNoEmptyFile(): void
    {
        $file = $this->createMock(File::class);
        $file->method('putContent')->willThrowException(new \RuntimeException('storage offline'));
        $file->expects($this->once())->method('delete');
        $folder = $this->createMock(Folder::class);
        $folder->method('nodeExists')->willReturn(false);
        $folder->method('getFreeSpace')->willReturn(-2);
        $folder->method('newFile')->willReturn($file);

        $this->expectException(\RuntimeException::class);
        SynaplanFolders::write($folder, 'plan.docx', 'bytes');
    }

    public function testAFileThatDoesNotFitIsRefusedBeforeItIsMade(): void
    {
        $folder = $this->createMock(Folder::class);
        $folder->method('getFreeSpace')->willReturn(4);
        $folder->expects($this->never())->method('newFile');

        $this->expectException(NotEnoughSpaceException::class);
        SynaplanFolders::write($folder, 'plan.docx', 'bytes');
    }
}
