<?php

declare(strict_types=1);

namespace OCA\OcoSynaplan\Tests\Unit\Service;

use OCA\OcoSynaplan\Service\WatchPolicy;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IUser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class WatchPolicyTest extends TestCase
{
    /** @var array<string, array<string, mixed>> who can reach each path below ada's files */
    private array $access = [];
    /** @var array<int, Folder> the folder stubs by their own id */
    private array $byId = [];

    public function testWatchingSharedFoldersIsAllowedUntilTheAdministratorTurnsItOff(): void
    {
        self::assertTrue($this->policy(null)->sharedAllowed());
        self::assertFalse($this->policy('no')->sharedAllowed());
    }

    public function testWatchingFoldersIsAllowedUntilTheAdministratorTurnsItOff(): void
    {
        self::assertTrue($this->policy(null)->watchingAllowed());
        self::assertFalse($this->policy(null, watching: 'no')->watchingAllowed());
    }

    public function testWithWatchingOffEveryTargetIsHeldAndEveryWatchReadsStopped(): void
    {
        $own = $this->folder(received: false, access: ['users' => ['ada']]);
        $shared = $this->folder(received: true, access: []);
        $policy = $this->policy('yes', [31 => $own], 'no');

        self::assertSame(WatchPolicy::OFF_WATCHES, $policy->heldBy($own));
        self::assertSame(WatchPolicy::OFF_WATCHES, $this->policy('no', [], 'no')->heldBy($shared));
        self::assertTrue($policy->blocks($own));
        self::assertSame(
            [['policy_off' => WatchPolicy::OFF_WATCHES, 'id' => 2, 'target_id' => '31'], ['policy_off' => WatchPolicy::OFF_WATCHES, 'id' => 3, 'target_id' => '99']],
            $policy->markStopped('ada', [['id' => 2, 'target_id' => '31'], ['id' => 3, 'target_id' => '99']]),
        );
    }

    /**
     * @return iterable<string, array{?string, bool}>
     */
    public static function jobModes(): iterable
    {
        yield 'never set, which ownCloud.online reads as AJAX' => [null, false];
        yield 'AJAX' => ['ajax', false];
        yield 'webcron' => ['webcron', true];
        yield 'cron' => ['cron', true];
    }

    #[DataProvider('jobModes')]
    public function testOnlyAjaxLeavesBackgroundJobsWithoutASchedule(?string $mode, bool $scheduled): void
    {
        $config = $this->createStub(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(
            static fn (string $app, string $key, string $default): string => $app === 'core' && $key === 'backgroundjobs_mode' && $mode !== null ? $mode : $default,
        );
        $policy = new WatchPolicy($config, $this->createStub(IRootFolder::class), static fn (): array => []);

        self::assertSame($scheduled, $policy->jobsOnSchedule());
        self::assertSame($mode ?? 'ajax', WatchPolicy::jobsModeIn($config));
        self::assertSame($scheduled, WatchPolicy::jobsOnScheduleIn($config));
    }

    public function testAFolderSharedWithThePersonIsShared(): void
    {
        $folder = $this->folder(received: true, access: ['users' => ['bob']]);

        self::assertTrue($this->policy('no')->isShared($folder));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, bool}>
     */
    public static function accessLists(): iterable
    {
        yield 'nobody' => [['users' => [], 'remote' => false, 'public' => false], false];
        yield 'only the owner' => [['users' => ['ada']], false];
        yield 'another person' => [['users' => ['ada', 'bob']], true];
        yield 'the owner twice' => [['users' => ['ada', 'ada']], false];
        yield 'a public link' => [['users' => [], 'public' => true], true];
        yield 'another server' => [['users' => [], 'remote' => true], true];
    }

    /**
     * @param array<string, mixed> $access
     */
    #[DataProvider('accessLists')]
    public function testAFolderThePersonSharesIsShared(array $access, bool $shared): void
    {
        self::assertSame($shared, $this->policy('no')->isShared($this->folder(received: false, access: $access)));
    }

    public function testAFolderTheShareApiCannotTellAboutIsNotShared(): void
    {
        $folder = $this->folder(received: false, access: []);
        $config = $this->createStub(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(static fn (string $app, string $key, string $default = ''): string => $default);
        $policy = new WatchPolicy($config, $this->root([]), static fn (): array => throw new \RuntimeException('no share backend'));

        self::assertFalse($policy->isShared($folder));
    }

    public function testOnlyASharedTargetIsBlockedAndOnlyWhileTheSettingIsOff(): void
    {
        $shared = $this->folder(received: true, access: []);
        $own = $this->folder(received: false, access: ['users' => ['ada']]);

        self::assertTrue($this->policy('no')->blocks($shared));
        self::assertFalse($this->policy('no')->blocks($own));
        self::assertFalse($this->policy('yes')->blocks($shared));
        self::assertSame(WatchPolicy::OFF_SHARED, $this->policy('no')->heldBy($shared));
        self::assertNull($this->policy('no')->heldBy($own));
    }

    public function testTheWatchesOnSharedTargetsAreMarkedStopped(): void
    {
        $nodes = [
            30 => $this->folder(received: true, access: []),
            31 => $this->folder(received: false, access: ['users' => ['ada']]),
        ];
        $watches = [
            ['id' => 1, 'target_id' => '30'],
            ['id' => 2, 'target_id' => '31'],
            ['id' => 3, 'target_id' => '99'],
        ];

        $marked = $this->policy('no', $nodes)->markStopped('ada', $watches);

        self::assertSame(WatchPolicy::OFF_SHARED, $marked[0]['policy_off'] ?? null);
        self::assertArrayNotHasKey('policy_off', $marked[1]);
        self::assertArrayNotHasKey('policy_off', $marked[2]);
        self::assertSame($watches, $this->policy('yes', $nodes)->markStopped('ada', $watches));
    }

    /**
     * @param array<string, mixed> $access
     */
    private function folder(bool $received, array $access): Folder
    {
        $id = 1000 + count($this->byId);
        $owner = $this->createStub(IUser::class);
        $owner->method('getUID')->willReturn('ada');
        $folder = $this->createStub(Folder::class);
        $folder->method('isShared')->willReturn($received);
        $folder->method('getId')->willReturn($id);
        $folder->method('getOwner')->willReturn($owner);
        $folder->method('getPath')->willReturn('/ada/files/Folder ' . $id);
        $this->byId[$id] = $folder;
        $this->access['Folder ' . $id] = $access;

        return $folder;
    }

    /**
     * @param array<int, Folder> $nodes
     */
    private function root(array $nodes): IRootFolder
    {
        $home = $this->createStub(Folder::class);
        $home->method('getById')->willReturnCallback(function (int $id) use ($nodes): array {
            $node = $nodes[$id] ?? $this->byId[$id] ?? null;

            return $node !== null ? [$node] : [];
        });
        $root = $this->createStub(IRootFolder::class);
        $root->method('getUserFolder')->willReturn($home);

        return $root;
    }

    /**
     * @param array<int, Folder> $nodes
     */
    private function policy(?string $value, array $nodes = [], ?string $watching = null): WatchPolicy
    {
        $config = $this->createStub(IConfig::class);
        $config->method('getAppValue')->willReturnCallback(
            static fn (string $app, string $key, string $default = ''): string => match ($key) {
                WatchPolicy::SHARED_KEY => $value ?? $default,
                WatchPolicy::WATCHES_KEY => $watching ?? $default,
                default => $default,
            },
        );
        $sharing = function (string $path, string $owner): array {
            self::assertSame('ada', $owner);

            return $this->access[$path] ?? [];
        };

        return new WatchPolicy($config, $this->root($nodes), $sharing);
    }
}
