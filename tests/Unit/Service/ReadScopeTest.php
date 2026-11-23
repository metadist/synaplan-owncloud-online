<?php

declare(strict_types=1);

namespace OCA\OcoSynaplan\Tests\Unit\Service;

use OCA\OcoSynaplan\Service\PlatformLinkService;
use OCA\OcoSynaplan\Service\ReadScope;
use OCA\OcoSynaplan\Service\WatchIndex;
use OCA\OcoSynaplan\Tests\Unit\Support\BuildsServices;
use OCA\OcoSynaplan\Tests\Unit\Support\Locked;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\NotFoundException;
use OCP\Lock\ILockingProvider;
use PHPUnit\Framework\TestCase;

final class ReadScopeTest extends TestCase
{
    use BuildsServices;

    /** @var array<string, int> folder path => file id */
    private array $folders = [];

    /** @var list<array{mode: string, folders: list<array{id: string, path: string}>}> what Synaplan was told */
    private array $reports = [];

    /** Whether Synaplan takes a report. */
    private bool $taken = true;

    /** @var array<string, array{shared: int, exclusive: bool}> lock => its holders in every request */
    private array $held = [];

    /** @var (\Closure(): void)|null what another request does when this one has to wait */
    private ?\Closure $whileWaiting = null;

    /** @var (\Closure(): void)|null what another request does while a report is on its way */
    private ?\Closure $whileReporting = null;

    protected function setUp(): void
    {
        $this->setUpServices();
        $this->folders = [];
        $this->reports = [];
        $this->taken = true;
        $this->held = [];
        $this->whileWaiting = null;
        $this->whileReporting = null;
    }

    public function testOnlyWhatIWatchAllowsNothingWhileThisAppWatchesNoFolders(): void
    {
        $this->folders = ['HR' => 9];
        $scope = $this->scope();
        $scope->save('ada', ReadScope::MODE_WATCHED, [['id' => '9', 'path' => 'HR']]);

        self::assertFalse($scope->mayRead('ada', 'HR'), 'a named folder waits for "Also folders I allow"');
        self::assertFalse($scope->mayRead('ada', 'Incoming-DE'));
    }

    public function testWatchedAllowsTheFoldersThePersonWatchesAndNothingBelowOrBeside(): void
    {
        $this->folders = ['Incoming-DE' => 30, 'HR' => 9, 'Specs/brief.pdf' => 31];
        $scope = $this->scope(watches: $this->watching());
        $scope->save('ada', ReadScope::MODE_WATCHED, [['id' => '9', 'path' => 'HR']]);

        self::assertTrue($scope->mayRead('ada', 'Incoming-DE'));
        self::assertFalse($scope->mayRead('ada', 'Incoming-DE/2026'));
        self::assertFalse($scope->mayRead('ada', 'HR'));
        self::assertFalse($scope->mayRead('ada', 'Specs/brief.pdf'), 'a watched file opens no folder');
    }

    public function testAlsoFoldersIAllowReadsTheWatchedFoldersToo(): void
    {
        $this->folders = ['Incoming-DE' => 30, 'HR' => 9];
        $scope = $this->scope(watches: $this->watching());
        $scope->save('ada', ReadScope::MODE_ALLOWED, [['id' => '9', 'path' => 'HR']]);

        self::assertTrue($scope->mayRead('ada', 'Incoming-DE'));
        self::assertTrue($scope->mayRead('ada', 'HR/2026'));
        self::assertFalse($scope->mayRead('ada', 'Incoming-DE/2026'));
    }

    public function testNothingAllowedMeansNothingMayBeRead(): void
    {
        $scope = $this->scope();

        self::assertFalse($scope->mayRead('ada', 'Invoices'));
        self::assertSame(ReadScope::MODE_WATCHED, $scope->get('ada')['mode']);
    }

    public function testANamedFolderAndWhatIsInsideItMayBeRead(): void
    {
        $scope = $this->scope();
        self::assertNull($scope->allow('ada', 'Invoices'));

        self::assertTrue($scope->mayRead('ada', 'Invoices'));
        self::assertTrue($scope->mayRead('ada', 'Invoices/2026'));
        self::assertFalse($scope->mayRead('ada', 'Invoices 2025'), 'a folder whose name only starts the same');
        self::assertFalse($scope->mayRead('ada', 'HR'));
        self::assertFalse($scope->mayRead('ada', ''), 'the top of the files');
        self::assertSame(ReadScope::MODE_ALLOWED, $scope->get('ada')['mode']);
    }

    public function testTheAdministratorsCapStopsAnotherFolder(): void
    {
        $this->config->setAppValue('oco_synaplan', ReadScope::CAP, '1');
        $scope = $this->scope();
        self::assertNull($scope->allow('ada', 'Invoices'));

        self::assertSame('cap', $scope->allow('ada', 'HR'));
        self::assertCount(1, $scope->get('ada')['folders']);
    }

    public function testTheCapStaysBetweenOneAndFiveHundred(): void
    {
        self::assertSame(ReadScope::DEFAULT_CAP, $this->scope()->cap());
        foreach (['0' => 1, '-4' => 1, '9999' => 500, '7' => 7] as $stored => $cap) {
            $this->config->setAppValue('oco_synaplan', ReadScope::CAP, (string) $stored);

            self::assertSame($cap, $this->scope()->cap(), (string) $stored);
        }
    }

    public function testASaveKeepsNoMoreFoldersThanTheCap(): void
    {
        $this->config->setAppValue('oco_synaplan', ReadScope::CAP, '2');
        $this->scope()->save('ada', ReadScope::MODE_ALLOWED, [
            ['id' => '', 'path' => 'A'],
            ['id' => '', 'path' => '../B'],
            ['id' => '', 'path' => 'C'],
            ['id' => '', 'path' => 'D'],
        ]);

        self::assertSame(['A', 'C'], array_column($this->scope()->get('ada')['folders'], 'path'));
    }

    public function testANameOutsideThePersonsFilesCannotBeNamed(): void
    {
        $scope = $this->scope();

        foreach (['', '/', '..', 'HR/../../bob', 'HR//Contracts'] as $path) {
            self::assertSame('folder', $scope->allow('ada', $path), $path);
        }
        self::assertSame([], $scope->get('ada')['folders']);
    }

    public function testNamingAnAlreadyNamedFolderUnderWatchedOpensIt(): void
    {
        $scope = $this->scope();
        self::assertNull($scope->allow('ada', 'Invoices'));
        $scope->save('ada', ReadScope::MODE_WATCHED, [['id' => '3', 'path' => 'Invoices']]);
        self::assertFalse($scope->mayRead('ada', 'Invoices'));

        self::assertNull($scope->allow('ada', 'Invoices'));

        self::assertSame(ReadScope::MODE_ALLOWED, $scope->get('ada')['mode']);
        self::assertTrue($scope->mayRead('ada', 'Invoices'));
    }

    public function testSynaplanHearsOfTheNamedFoldersOnlyWhileTheyMayBeRead(): void
    {
        $this->config->setAppValue('oco_synaplan', ReadScope::CEILING, ReadScope::MODE_ALL);
        $scope = $this->scope();
        $scope->save('ada', ReadScope::MODE_ALLOWED, [['id' => '', 'path' => 'Invoices']]);
        $named = $scope->get('ada')['folders'];

        foreach ([ReadScope::MODE_WATCHED, ReadScope::MODE_ALL] as $mode) {
            $scope->save('ada', $mode, $named);

            self::assertSame(['mode' => $mode, 'folders' => []], end($this->reports), $mode);
            self::assertSame($named, $scope->get('ada')['folders'], 'kept for the next time');
        }

        $scope->save('ada', ReadScope::MODE_ALLOWED, $named);

        self::assertSame(['mode' => ReadScope::MODE_ALLOWED, 'folders' => $named], end($this->reports));
    }

    public function testAllFilesMayBeReadWhereTheAdministratorAllowsIt(): void
    {
        $this->config->setAppValue('oco_synaplan', ReadScope::CEILING, ReadScope::MODE_ALL);
        $scope = $this->scope();
        $scope->save('ada', ReadScope::MODE_ALL, []);

        self::assertTrue($scope->mayRead('ada', 'HR/Contracts'));
    }

    public function testTheSettingNeverReachesAboveTheAdministratorsCeiling(): void
    {
        $scope = $this->scope();
        self::assertSame(ReadScope::MODE_ALLOWED, $scope->ceiling(), 'folders people allow, by default');

        $scope->save('ada', ReadScope::MODE_ALL, []);

        self::assertSame(ReadScope::MODE_ALLOWED, $scope->get('ada')['mode']);
        self::assertFalse($scope->mayRead('ada', 'HR/Contracts'));

        $this->config->setAppValue('oco_synaplan', ReadScope::CEILING, ReadScope::MODE_WATCHED);
        self::assertSame(ReadScope::MODE_WATCHED, $this->scope()->get('ada')['mode']);
        self::assertSame('ceiling', $this->scope()->allow('ada', 'Invoices'));

        $this->config->setAppValue('oco_synaplan', ReadScope::CEILING, 'everything');
        self::assertSame(ReadScope::MODE_ALLOWED, $this->scope()->ceiling());
    }

    public function testLoweringTheCeilingNarrowsEveryoneAboveItAndRaisingItWidensNobody(): void
    {
        $this->config->setAppValue('oco_synaplan', ReadScope::CEILING, ReadScope::MODE_ALL);
        $scope = $this->scope();
        $scope->save('ada', ReadScope::MODE_ALL, []);
        $scope->save('bob', ReadScope::MODE_ALLOWED, [['id' => '', 'path' => 'Invoices']]);
        $this->reports = [];

        $scope->setCeiling(ReadScope::MODE_ALLOWED);

        self::assertSame(ReadScope::MODE_ALLOWED, $scope->get('ada')['mode']);
        self::assertSame('1', $this->config->userValue('ada', ReadScope::PENDING));
        self::assertNull($this->config->userValue('bob', ReadScope::PENDING), 'within the new ceiling');
        self::assertSame([], $this->reports, 'the hourly job tells Synaplan');

        $scope->setCeiling(ReadScope::MODE_ALL);

        self::assertSame(ReadScope::MODE_ALLOWED, $scope->get('ada')['mode']);
    }

    public function testLoweringTheCeilingWaitsForAChangeUnderWayAndKeepsIt(): void
    {
        $this->folders = ['HR' => 4];
        $this->locking = $this->heldLocks();
        $scope = $this->scope();
        $scope->setCeiling(ReadScope::MODE_ALL);
        $scope->save('ada', ReadScope::MODE_ALL, [['id' => '', 'path' => 'HR']]);

        // Ada's other request is switching to "Only what I watch" and
        // dropping HR when the administrator lowers the ceiling.
        $this->held = [
            'oco_synaplan/read-scope-ceiling' => ['shared' => 1, 'exclusive' => false],
            'oco_synaplan/read-scope/' . md5('ada') => ['shared' => 0, 'exclusive' => true],
        ];
        $this->whileWaiting = function (): void {
            $this->config->user['ada']['oco_synaplan'][ReadScope::PREF] = (string) json_encode(['mode' => ReadScope::MODE_WATCHED, 'folders' => []]);
            $this->held = [];
        };

        $this->scope()->setCeiling(ReadScope::MODE_ALLOWED);

        self::assertSame(
            ['mode' => ReadScope::MODE_WATCHED, 'folders' => []],
            json_decode((string) $this->config->userValue('ada', ReadScope::PREF), true),
        );
        self::assertNull($this->config->userValue('ada', ReadScope::PENDING));
    }

    public function testAChoiceSavedAfterTheCeilingWasLoweredIsHeldToIt(): void
    {
        $this->config->setAppValue('oco_synaplan', ReadScope::CEILING, ReadScope::MODE_ALL);
        $this->config->keepWhatThisRequestRead();
        $scope = $this->scope();
        self::assertSame(ReadScope::MODE_ALL, $scope->ceiling());

        // The administrator lowers it while Ada's request is running.
        $this->config->app['oco_synaplan'][ReadScope::CEILING] = ReadScope::MODE_ALLOWED;
        $scope->save('ada', ReadScope::MODE_ALL, []);

        self::assertSame(ReadScope::MODE_ALLOWED, json_decode((string) $this->config->userValue('ada', ReadScope::PREF), true)['mode']);
        self::assertSame(ReadScope::MODE_ALLOWED, end($this->reports)['mode']);
    }

    public function testAChoiceTheCeilingNarrowsWhileTheHourlyJobSendsItStaysPending(): void
    {
        $this->config->setAppValue('oco_synaplan', ReadScope::CEILING, ReadScope::MODE_ALL);
        $scope = $this->scope();
        $scope->save('ada', ReadScope::MODE_ALL, []);
        $this->config->setUserValue('ada', 'oco_synaplan', ReadScope::PENDING, '1');
        $this->whileReporting = function (): void {
            $this->scope()->setCeiling(ReadScope::MODE_ALLOWED);
        };

        self::assertFalse($scope->republish('ada'));
        self::assertSame(ReadScope::MODE_ALL, end($this->reports)['mode'], 'sent before the ceiling changed');
        self::assertSame('1', $this->config->userValue('ada', ReadScope::PENDING));

        self::assertTrue($this->scope()->republish('ada'));
        self::assertSame(ReadScope::MODE_ALLOWED, end($this->reports)['mode']);
        self::assertNull($this->config->userValue('ada', ReadScope::PENDING));
    }

    public function testTheHourlyJobLeavesTheCeilingFreeForTheRestOfItsCronRun(): void
    {
        $this->config->setAppValue('oco_synaplan', ReadScope::CEILING, ReadScope::MODE_ALL);
        $this->scope()->save('ada', ReadScope::MODE_ALL, []);
        $this->config->setUserValue('ada', 'oco_synaplan', ReadScope::PENDING, '1');

        // ownCloud keeps a shared lock until the process ends, and a cron run
        // goes on to other jobs after this one.
        self::assertTrue($this->scope($this->heldLocks(keepsShared: true))->republish('ada'));

        $this->scope($this->heldLocks())->setCeiling(ReadScope::MODE_ALLOWED);
        self::assertSame(ReadScope::MODE_ALLOWED, $this->scope()->ceiling());
    }

    public function testEveryLockFitsOwnCloudsLockTable(): void
    {
        $uid = str_repeat('a', 64);
        $this->locking = $this->heldLocks();
        $scope = $this->scope();

        $scope->setCeiling(ReadScope::MODE_ALL);
        $scope->save($uid, ReadScope::MODE_ALL, []);
        self::assertNull($scope->allow($uid, 'Invoices'));
        $scope->follow($uid);
        $this->taken = false;
        $scope->save($uid, ReadScope::MODE_ALLOWED, []);
        $this->taken = true;

        self::assertTrue($scope->republish($uid));
    }

    public function testAReportSynaplanDidNotTakeIsSentAgainLater(): void
    {
        $this->taken = false;
        $scope = $this->scope();
        $scope->allow('ada', 'Invoices');

        self::assertSame('1', $this->config->userValue('ada', ReadScope::PENDING));
        self::assertTrue($scope->mayRead('ada', 'Invoices'), 'the setting here applies at once');
        self::assertFalse($scope->republish('ada'));
        self::assertSame('1', $this->config->userValue('ada', ReadScope::PENDING));

        $this->taken = true;

        self::assertTrue($scope->republish('ada'));
        self::assertNull($this->config->userValue('ada', ReadScope::PENDING));
        self::assertSame(['mode' => ReadScope::MODE_ALLOWED, 'folders' => [['id' => '', 'path' => 'Invoices']]], end($this->reports));
    }

    public function testAnAllowedFolderKeepsItsPermissionWhenRenamed(): void
    {
        $this->folders = ['Invoices' => 3];
        $scope = $this->scope();
        $scope->save('ada', ReadScope::MODE_ALLOWED, [['id' => '', 'path' => 'Invoices']]);
        self::assertSame('3', $scope->get('ada')['folders'][0]['id']);

        $this->folders = ['Archive/Invoices' => 3];
        $renamed = $this->scope();

        self::assertTrue($renamed->mayRead('ada', 'Archive/Invoices/2026'));
        self::assertFalse($renamed->mayRead('ada', 'Invoices'));
    }

    public function testANewFolderAtAnAllowedNameIsNotAllowed(): void
    {
        $this->folders = ['Invoices' => 3];
        self::assertNull($this->scope()->allow('ada', 'Invoices'));
        self::assertSame('3', $this->scope()->get('ada')['folders'][0]['id']);

        $this->folders = ['Invoices' => 8];

        self::assertFalse($this->scope()->mayRead('ada', 'Invoices'));
    }

    public function testSavingTheSettingsKeepsEachFolderAllowedAndGivesNoneToItsReplacement(): void
    {
        $this->folders = ['Invoices' => 3, 'HR' => 4];
        $this->scope()->allow('ada', 'Invoices');
        // Ada moves Invoices away and makes a new folder under its name,
        // then adds HR on the settings page before the hourly job ran.
        $this->folders = ['Archive/Invoices' => 3, 'Invoices' => 8, 'HR' => 4];
        $page = [...$this->scope()->get('ada')['folders'], ['id' => '', 'path' => 'HR']];

        $this->scope()->save('ada', ReadScope::MODE_ALLOWED, $page);

        self::assertSame([['id' => '3', 'path' => 'Invoices'], ['id' => '4', 'path' => 'HR']], $this->scope()->get('ada')['folders']);
        self::assertFalse($this->scope()->mayRead('ada', 'Invoices'));
        self::assertTrue($this->scope()->mayRead('ada', 'Archive/Invoices'));
    }

    public function testAFolderThatCannotBeFoundWhenSavedKeepsItsId(): void
    {
        $this->folders = ['Invoices' => 3];
        $this->scope()->allow('ada', 'Invoices');
        // In the trash, or on a storage that is away.
        $this->folders = [];

        $this->scope()->save('ada', ReadScope::MODE_ALLOWED, $this->scope()->get('ada')['folders']);

        self::assertSame([['id' => '3', 'path' => 'Invoices']], $this->scope()->get('ada')['folders']);
        $this->folders = ['Invoices' => 8];
        self::assertFalse($this->scope()->mayRead('ada', 'Invoices'), 'never matched by its name instead');
        $this->folders = ['Invoices' => 3];
        self::assertTrue($this->scope()->mayRead('ada', 'Invoices'));
    }

    public function testAnIdTheSettingsPageSendsNeverNamesAnotherFolder(): void
    {
        $this->folders = ['Invoices' => 3, 'HR' => 4];
        $this->scope()->save('ada', ReadScope::MODE_ALLOWED, [['id' => '4', 'path' => 'Invoices']]);

        self::assertSame([['id' => '3', 'path' => 'Invoices']], $this->scope()->get('ada')['folders']);

        $this->scope()->save('ada', ReadScope::MODE_ALLOWED, [['id' => '3', 'path' => 'HR']]);

        self::assertSame([['id' => '3', 'path' => 'Invoices']], $this->scope()->get('ada')['folders'], 'a saved folder keeps its own path');
        self::assertFalse($this->scope()->mayRead('ada', 'HR'));
    }

    public function testAllowingTheFolderThatReplacedAnAllowedOneTakesItsPlace(): void
    {
        $this->config->setAppValue('oco_synaplan', ReadScope::CAP, '1');
        $this->folders = ['Invoices' => 3];
        $this->scope()->allow('ada', 'Invoices');
        // Ada deletes Invoices and makes a new one under the same name.
        $this->folders = ['Invoices' => 8];
        self::assertFalse($this->scope()->mayRead('ada', 'Invoices'));

        self::assertNull($this->scope()->allow('ada', 'Invoices'), 'the folder that is gone takes no place under the cap');

        self::assertSame([['id' => '8', 'path' => 'Invoices']], $this->scope()->get('ada')['folders']);
        self::assertTrue($this->scope()->mayRead('ada', 'Invoices'));
    }

    public function testAllowingANewFolderUnderTheNameOfAMovedOneKeepsBoth(): void
    {
        $this->folders = ['Invoices' => 3];
        $this->scope()->allow('ada', 'Invoices');
        $this->folders = ['Archive/Invoices' => 3, 'Invoices' => 8];

        self::assertNull($this->scope()->allow('ada', 'Invoices'));

        self::assertSame(
            [['id' => '3', 'path' => 'Archive/Invoices'], ['id' => '8', 'path' => 'Invoices']],
            $this->scope()->get('ada')['folders'],
        );
    }

    public function testAllowingAnAllowedFolderAtItsNewNameTakesNoOtherPlace(): void
    {
        $this->config->setAppValue('oco_synaplan', ReadScope::CAP, '1');
        $this->folders = ['Invoices' => 3];
        $this->scope()->allow('ada', 'Invoices');
        $this->folders = ['Archive/Invoices' => 3];
        $this->reports = [];

        self::assertNull($this->scope()->allow('ada', 'Archive/Invoices'));

        self::assertSame([['id' => '3', 'path' => 'Archive/Invoices']], $this->scope()->get('ada')['folders']);
        self::assertCount(1, $this->reports);
        self::assertNull($this->scope()->allow('ada', 'Archive/Invoices'));
        self::assertCount(1, $this->reports, 'nothing changed, so nothing is sent');
    }

    public function testALongBackgroundRunSeesAFolderTakenBackAndALoweredCeiling(): void
    {
        $this->config->setAppValue('oco_synaplan', ReadScope::CEILING, ReadScope::MODE_ALL);
        $this->folders = ['Invoices' => 3];
        $this->scope()->allow('ada', 'Invoices');
        $this->config->keepWhatThisRequestRead();
        $scope = $this->scope();
        self::assertTrue($scope->mayRead('ada', 'Invoices'));

        // Ada takes the folder back in another request.
        $this->config->user['ada']['oco_synaplan'][ReadScope::PREF] = (string) json_encode(['mode' => ReadScope::MODE_ALLOWED, 'folders' => []]);
        self::assertFalse($scope->mayRead('ada', 'Invoices'));

        $this->config->user['ada']['oco_synaplan'][ReadScope::PREF] = (string) json_encode(['mode' => ReadScope::MODE_ALL, 'folders' => []]);
        self::assertTrue($scope->mayRead('ada', 'HR'));
        // The administrator lowers the ceiling before the sweep reaches Ada.
        $this->config->app['oco_synaplan'][ReadScope::CEILING] = ReadScope::MODE_ALLOWED;
        self::assertFalse($scope->mayRead('ada', 'HR'));
    }

    public function testEachCheckFindsTheAllowedFolderWhereItIsNow(): void
    {
        $this->folders = ['Invoices' => 3];
        $scope = $this->scope();
        $scope->allow('ada', 'Invoices');
        self::assertTrue($scope->mayRead('ada', 'Invoices'));
        // Ada moves it and makes a new folder under its old name.
        $this->folders = ['Archive/Invoices' => 3, 'Invoices' => 8];

        self::assertFalse($scope->mayRead('ada', 'Invoices'));
        self::assertTrue($scope->mayRead('ada', 'Archive/Invoices'));
    }

    public function testAFolderSavedWithoutAnIdIsMatchedByPath(): void
    {
        $this->config->setUserValue('ada', 'oco_synaplan', ReadScope::PREF, (string) json_encode([
            'mode' => ReadScope::MODE_ALLOWED,
            'folders' => [['id' => '', 'path' => 'Invoices']],
        ]));

        self::assertTrue($this->scope()->mayRead('ada', 'Invoices/2026'));
    }

    public function testAMovedAllowedFolderIsReportedAtItsNewPath(): void
    {
        $this->folders = ['Invoices' => 3, 'HR' => 4];
        $this->scope()->save('ada', ReadScope::MODE_ALLOWED, [
            ['id' => '', 'path' => 'Invoices'],
            ['id' => '', 'path' => 'HR'],
        ]);
        $this->reports = [];
        $this->folders = ['Archive/Invoices 2026' => 3, 'HR' => 4];

        self::assertTrue($this->scope()->follow('ada'));

        $expected = [['id' => '3', 'path' => 'Archive/Invoices 2026'], ['id' => '4', 'path' => 'HR']];
        self::assertSame([['mode' => ReadScope::MODE_ALLOWED, 'folders' => $expected]], $this->reports);
        self::assertSame($expected, $this->scope()->get('ada')['folders']);
    }

    public function testNothingIsReportedWhenNoAllowedFolderMoved(): void
    {
        $this->folders = ['Invoices' => 3];
        $this->scope()->save('ada', ReadScope::MODE_ALLOWED, [['id' => '', 'path' => 'Invoices']]);
        $this->reports = [];
        $this->folders = ['Invoices' => 3, 'Other' => 9];

        self::assertFalse($this->scope()->follow('ada'));
        self::assertSame([], $this->reports);
    }

    public function testAFolderThatIsGoneOrHasNoIdKeepsItsPath(): void
    {
        $this->config->setUserValue('ada', 'oco_synaplan', ReadScope::PREF, (string) json_encode([
            'mode' => ReadScope::MODE_ALLOWED,
            'folders' => [['id' => '3', 'path' => 'Invoices'], ['id' => '', 'path' => 'Old']],
        ]));

        self::assertFalse($this->scope()->follow('ada'));
        self::assertSame([], $this->reports);
        self::assertSame('Invoices', $this->scope()->get('ada')['folders'][0]['path']);
    }

    public function testAFollowSeesAMoveMadeAfterAnEarlierReadInTheSameRequest(): void
    {
        $this->folders = ['Invoices' => 3];
        $scope = $this->scope();
        $scope->save('ada', ReadScope::MODE_ALLOWED, [['id' => '', 'path' => 'Invoices']]);
        self::assertTrue($scope->mayRead('ada', 'Invoices'));
        $this->folders = ['Archive/Invoices' => 3];

        self::assertTrue($scope->follow('ada'));
        self::assertSame('Archive/Invoices', $scope->get('ada')['folders'][0]['path']);
    }

    private function watching(): WatchIndex
    {
        $watches = $this->createStub(WatchIndex::class);
        $watches->method('rowsOf')->willReturn([
            ['id' => 5, 'uid' => 'ada', 'kind' => 'folder', 'target' => 30, 'state' => 'active', 'since' => 0, 'reconciled' => 0],
            ['id' => 6, 'uid' => 'ada', 'kind' => 'file', 'target' => 31, 'state' => 'active', 'since' => 0, 'reconciled' => 0],
        ]);

        return $watches;
    }

    private function scope(?ILockingProvider $locks = null, ?WatchIndex $watches = null): ReadScope
    {
        $links = $this->createStub(PlatformLinkService::class);
        $links->method('reportReadScope')->willReturnCallback(
            function (string $uid, string $mode, array $folders): bool {
                $this->reports[] = ['mode' => $mode, 'folders' => $folders];
                $other = $this->whileReporting;
                $this->whileReporting = null;
                if ($other !== null) {
                    $other();
                }

                return $this->taken;
            },
        );

        $userFolder = $this->createStub(Folder::class);
        $userFolder->method('get')->willReturnCallback(function (string $path): Folder {
            if (!isset($this->folders[$path])) {
                throw new NotFoundException($path);
            }

            return $this->folder($this->folders[$path], $path);
        });
        $userFolder->method('getById')->willReturnCallback(function (int $id): array {
            $path = array_search($id, $this->folders, true);

            return $path === false ? [] : [$this->folder($id, $path)];
        });
        $userFolder->method('getRelativePath')->willReturnCallback(
            static fn (string $path): string => (string) substr($path, strlen('/ada/files')),
        );
        $root = $this->createStub(IRootFolder::class);
        $root->method('getUserFolder')->willReturn($userFolder);

        return new ReadScope($this->config, $this->db(), $links, $root, $locks ?? $this->locks(), $watches ?? new WatchIndex($this->db()));
    }

    /**
     * One request's view of the locks every request shares, as ownCloud's
     * database provider gives them: keys of up to 64 characters, and with
     * $keepsShared a shared lock stays taken until the request ends.
     */
    private function heldLocks(bool $keepsShared = false): ILockingProvider
    {
        $locks = $this->createStub(ILockingProvider::class);
        $locks->method('acquireLock')->willReturnCallback(function (string $path, int $type): void {
            if (strlen($path) > 64) {
                throw new \InvalidArgumentException('Lock key length too long');
            }
            $held = $this->held[$path] ?? ['shared' => 0, 'exclusive' => false];
            $free = $type === ILockingProvider::LOCK_SHARED
                ? !$held['exclusive']
                : !$held['exclusive'] && $held['shared'] === 0;
            if (!$free) {
                $other = $this->whileWaiting;
                $this->whileWaiting = null;
                if ($other !== null) {
                    $other();
                }
                throw new Locked($path);
            }
            if ($type === ILockingProvider::LOCK_SHARED) {
                $held['shared']++;
            } else {
                $held['exclusive'] = true;
            }
            $this->held[$path] = $held;
        });
        $locks->method('releaseLock')->willReturnCallback(function (string $path, int $type) use ($keepsShared): void {
            $held = $this->held[$path] ?? ['shared' => 0, 'exclusive' => false];
            if ($type === ILockingProvider::LOCK_EXCLUSIVE) {
                $held['exclusive'] = false;
            } elseif (!$keepsShared) {
                $held['shared'] = max(0, $held['shared'] - 1);
            }
            $this->held[$path] = $held;
        });

        return $locks;
    }

    private function folder(int $id, string $path): Folder
    {
        $folder = $this->createStub(Folder::class);
        $folder->method('getId')->willReturn($id);
        $folder->method('getPath')->willReturn('/ada/files/' . $path);

        return $folder;
    }
}
