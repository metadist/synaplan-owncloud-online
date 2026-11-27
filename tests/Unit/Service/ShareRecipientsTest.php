<?php

declare(strict_types=1);

namespace OCA\OcoSynaplan\Tests\Unit\Service;

use OCA\OcoSynaplan\Service\ShareRecipients;
use OCA\OcoSynaplan\Service\UserAccountService;
use OCA\OcoSynaplan\Tests\Unit\Support\ArraySourceEventQueue;
use OCP\Files\Config\ICachedMountInfo;
use OCP\Files\Config\IUserMountCache;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

final class ShareRecipientsTest extends TestCase
{
    /** @var list<array{string, string}> the paths and owners the share API was asked about */
    private array $asked = [];

    public function testThePeopleWithAccessAndACredentialWhoWereNotToldYet(): void
    {
        $recipients = $this->recipients(
            static fn (): array => ['users' => ['ada', 'bob', 'carol', 'dan', 'bob'], 'public' => false, 'remote' => false],
            static fn (string $uid): bool => $uid !== 'dan',
        );

        self::assertSame(['bob', 'carol'], $recipients->of($this->node('/ada/files/Specs/spec.pdf'), ['ada']));
        self::assertSame([['Specs/spec.pdf', 'ada']], $this->asked);
    }

    public function testAFileSeenThroughAShareIsLookedUpAtTheOwnersPath(): void
    {
        $recipients = $this->recipients(static fn (): array => ['users' => ['carol']]);

        self::assertSame(['carol'], $recipients->of($this->node('/bob/files/From Ada/spec.pdf'), ['bob', 'ada']));
        self::assertSame([['Specs/spec.pdf', 'ada']], $this->asked);
    }

    public function testAShareApiThatFailsTellsNobodyElse(): void
    {
        $recipients = $this->recipients(static fn (): array => throw new \RuntimeException('no share backend'));

        self::assertSame([], $recipients->of($this->node('/ada/files/Specs/spec.pdf'), []));
    }

    public function testAFileWithoutAnOwnerTellsNobodyElse(): void
    {
        $node = $this->createStub(Node::class);
        $node->method('getId')->willReturn(42);

        self::assertSame([], $this->recipients(static fn (): array => ['users' => ['bob']])->of($node, []));
        self::assertSame([], $this->asked);
    }

    public function testSomeoneLosesANodeWhenTheirFilesNoLongerReachIt(): void
    {
        $recipients = $this->recipients(
            static fn (): array => ['users' => ['bob', 'carol', 'dan']],
            static fn (string $uid): bool => $uid !== 'dan',
            ['carol'],
        );
        $node = $this->node('/ada/files/Specs/spec.pdf');

        self::assertTrue($recipients->lost($node, 'bob'));
        self::assertFalse($recipients->lost($node, 'carol'), 'Carol still reaches it through another share');
        self::assertFalse($recipients->lost($node, 'dan'), 'Dan cannot tell Synaplan');
        self::assertFalse($recipients->lost($node, ''));
        self::assertSame([], $this->asked, 'the share API still names whoever removed or declined a share');
    }

    public function testNobodyLosesANodeWhenTheirFilesCannotBeRead(): void
    {
        self::assertFalse($this->recipients(static fn (): array => [])->lost($this->node('/ada/files/Specs/spec.pdf'), 'eve'));
    }

    public function testTheFoldersBelowAFolderSomeoneStillHasMountedOnTheirOwn(): void
    {
        $recipients = $this->recipients(static fn (): array => [], null, [], [
            [3, 53, 'files/Specs/Old'],
            [3, 50, 'files/Specs'],
            [3, 60, 'files/Specsheet'],
            [3, 99, null],
            [4, 70, 'files/Specs/New'],
            [7, 1, ''],
        ]);

        self::assertSame(['files/Specs/Old'], $recipients->keptBelow('bob', ['storage' => 3, 'path' => 'files/Specs']));
        self::assertSame([], $recipients->keptBelow('eve', ['storage' => 3, 'path' => 'files/Specs']));
    }

    public function testThePeopleWithOnlyAShareBelowAFolderAreFoundInTheMountCache(): void
    {
        $queue = new ArraySourceEventQueue();
        foreach ([1 => '', 50 => 'files/Specs', 51 => 'files/Specs/a.pdf', 53 => 'files/Specs/Old', 60 => 'files/Specsheet.pdf'] as $id => $path) {
            $queue->cache(3, $path, $id);
        }
        $mountCache = $this->createMock(IUserMountCache::class);
        $mountCache->method('getMountsForStorageId')->with(3)->willReturn(array_map(function (array $mount): ICachedMountInfo {
            $user = $this->createStub(IUser::class);
            $user->method('getUID')->willReturn($mount[0]);
            $info = $this->createStub(ICachedMountInfo::class);
            $info->method('getUser')->willReturn($user);
            $info->method('getRootId')->willReturn($mount[1]);

            return $info;
        }, [['ada', 1], ['bob', 53], ['bob', 53], ['carol', 50], ['dan', 53], ['erin', 60], ['frank', 51], ['gus', 99]]));
        $accounts = $this->createStub(UserAccountService::class);
        $accounts->method('holdsCredential')->willReturnCallback(static fn (string $uid): bool => $uid !== 'dan');
        $recipients = new ShareRecipients($accounts, $this->createStub(IRootFolder::class), $mountCache, $this->createStub(IUserManager::class), $queue);

        self::assertSame(
            [['uid' => 'bob', 'root' => 53], ['uid' => 'frank', 'root' => 51]],
            $recipients->mountedBelow(['storage' => 3, 'path' => 'files/Specs'], ['ada']),
        );
    }

    /**
     * @param \Closure(): array<string, mixed> $access
     * @param (\Closure(string): bool)|null $holds
     * @param list<string> $reaching who reaches file 42 besides Ada; Eve's files cannot be read
     * @param list<array{int, int, ?string}> $mounts Bob's mounts: storage, root id and the root's file cache path, if it has one
     */
    private function recipients(\Closure $access, ?\Closure $holds = null, array $reaching = [], array $mounts = []): ShareRecipients
    {
        $accounts = $this->createStub(UserAccountService::class);
        $accounts->method('holdsCredential')->willReturnCallback($holds ?? static fn (): bool => true);
        $mine = $this->createStub(Node::class);
        $mine->method('getPath')->willReturn('/ada/files/Specs/spec.pdf');
        $root = $this->createStub(IRootFolder::class);
        $root->method('getUserFolder')->willReturnCallback(function (string $uid) use ($mine, $reaching): Folder {
            if ($uid === 'eve') {
                throw new NotFoundException('eve');
            }
            $home = $this->createStub(Folder::class);
            $home->method('getById')->willReturnCallback(
                static fn (int $id): array => $id === 42 && ($uid === 'ada' || in_array($uid, $reaching, true)) ? [$mine] : [],
            );

            return $home;
        });
        $users = $this->createStub(IUserManager::class);
        $bob = $this->createStub(IUser::class);
        $users->method('get')->willReturnCallback(static fn (string $uid): ?IUser => $uid === 'bob' ? $bob : null);
        $queue = new ArraySourceEventQueue();
        $cached = [];
        foreach ($mounts as [$storage, $rootId, $path]) {
            if ($path !== null) {
                $queue->cache($storage, $path, $rootId);
            }
            $mount = $this->createStub(ICachedMountInfo::class);
            $mount->method('getStorageId')->willReturn($storage);
            $mount->method('getRootId')->willReturn($rootId);
            $cached[] = $mount;
        }
        $mountCache = $this->createStub(IUserMountCache::class);
        $mountCache->method('getMountsForUser')->willReturn($cached);

        return new ShareRecipients($accounts, $root, $mountCache, $users, $queue, function (string $path, string $owner) use ($access): array {
            $this->asked[] = [$path, $owner];

            return $access();
        });
    }

    private function node(string $path): Node
    {
        $owner = $this->createStub(IUser::class);
        $owner->method('getUID')->willReturn('ada');
        $node = $this->createStub(Node::class);
        $node->method('getId')->willReturn(42);
        $node->method('getPath')->willReturn($path);
        $node->method('getOwner')->willReturn($owner);

        return $node;
    }
}
