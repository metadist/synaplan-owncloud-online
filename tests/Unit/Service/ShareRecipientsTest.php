<?php

declare(strict_types=1);

namespace OCA\OcoSynaplan\Tests\Unit\Service;

use OCA\OcoSynaplan\Service\ShareRecipients;
use OCA\OcoSynaplan\Service\UserAccountService;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\IUser;
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

    /**
     * @param \Closure(): array<string, mixed> $access
     * @param (\Closure(string): bool)|null $holds
     */
    private function recipients(\Closure $access, ?\Closure $holds = null): ShareRecipients
    {
        $accounts = $this->createStub(UserAccountService::class);
        $accounts->method('holdsCredential')->willReturnCallback($holds ?? static fn (): bool => true);
        $mine = $this->createStub(Node::class);
        $mine->method('getPath')->willReturn('/ada/files/Specs/spec.pdf');
        $home = $this->createStub(Folder::class);
        $home->method('getById')->willReturnCallback(static fn (int $id): array => $id === 42 ? [$mine] : []);
        $root = $this->createStub(IRootFolder::class);
        $root->method('getUserFolder')->willReturnCallback(static fn (string $uid): Folder => $home);

        return new ShareRecipients($accounts, $root, function (string $path, string $owner) use ($access): array {
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
