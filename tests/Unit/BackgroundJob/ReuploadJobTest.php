<?php

declare(strict_types=1);

namespace OCA\OcoSynaplan\Tests\Unit\BackgroundJob;

use OCA\OcoSynaplan\BackgroundJob\ReuploadJob;
use OCA\OcoSynaplan\Tests\Unit\Support\BuildsServices;
use OCP\BackgroundJob\IJobList;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use PHPUnit\Framework\TestCase;

final class ReuploadJobTest extends TestCase
{
    use BuildsServices;

    /** @var array<string, mixed> what Synaplan answers for the file's state */
    private array $state = [
        'id' => '7',
        'state' => 'updating',
        'knowledge_folder' => ['key' => 'Contracts', 'name' => 'Contracts'],
        'file_id' => 42,
        'kept_up_to_date' => true,
    ];

    private ?Node $node = null;

    protected function setUp(): void
    {
        $this->setUpServices(['mode' => 'link']);
        $this->node = $this->file(7, 'plan.docx', 12, '"e2"');
        $this->http->answer(fn (string $method, string $path): array => match ($path) {
            '/api/v1/cloud/status' => ['items' => [$this->state]],
            default => ['success' => true, 'file' => ['id' => 42]],
        });
    }

    public function testAKeptFileIsUploadedAgainWithTheAccessTokenOfAPersonConnectedThroughOAuth(): void
    {
        $this->register();
        $this->connect('ada', 'access-ada');

        $this->runReuploadJob(['uid' => 'ada', 'id' => '7']);

        $status = $this->http->to('/api/v1/cloud/status')[0];
        self::assertSame(['items' => [['id' => '7', 'version' => '"e2"']]], $status['body']);
        self::assertSame('Bearer access-ada', $status['options']['headers']['Authorization']);
        $upload = $this->http->to('/api/v1/files/upload')[0];
        self::assertSame('Bearer access-ada', $upload['options']['headers']['Authorization']);
        self::assertSame([
            'group_key' => 'Contracts',
            'original_name' => 'Projects/plan.docx',
            'source_id' => '7',
            'source_etag' => '"e2"',
            'overwrite' => '1',
            'keep_synced' => '1',
        ], array_intersect_key($upload['body'], array_flip(['group_key', 'original_name', 'source_id', 'source_etag', 'overwrite', 'keep_synced'])));
    }

    public function testACopyWithoutAKnowledgeFolderGoesBackToTheDefaultOne(): void
    {
        $this->register();
        $this->connect('ada', 'access-ada');
        $this->state['knowledge_folder'] = null;

        $this->runReuploadJob(['uid' => 'ada', 'id' => '7']);

        self::assertSame('DEFAULT', $this->http->to('/api/v1/files/upload')[0]['body']['group_key']);
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function notKept(): array
    {
        return [
            'no longer kept up to date' => [['kept_up_to_date' => false]],
            'removed from Synaplan' => [['state' => 'none']],
        ];
    }

    /**
     * @param array<string, mixed> $state
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('notKept')]
    public function testACopyNoLongerKeptUpToDateIsNotUploaded(array $state): void
    {
        $this->register();
        $this->connect('ada', 'access-ada');
        $this->state = $state + $this->state;

        $this->runReuploadJob(['uid' => 'ada', 'id' => '7']);

        self::assertCount(1, $this->http->to('/api/v1/cloud/status'));
        self::assertSame([], $this->http->to('/api/v1/files/upload'));
    }

    public function testNothingIsUploadedForAFileThatIsGoneTooLargeOrOfSomeoneWithoutACredential(): void
    {
        $this->register();
        $this->connect('ada', 'access-ada');

        $this->runReuploadJob(['uid' => 'bob', 'id' => '7']);
        $this->runReuploadJob(['uid' => 'ada', 'id' => 'seven']);
        $this->node = null;
        $this->runReuploadJob(['uid' => 'ada', 'id' => '7']);
        $this->node = $this->file(7, 'film.mp4', 100 * 1024 * 1024 + 1, '"e2"');
        $this->runReuploadJob(['uid' => 'ada', 'id' => '7']);
        $this->node = $this->createStub(Folder::class);
        $this->runReuploadJob(['uid' => 'ada', 'id' => '7']);

        self::assertSame([], $this->http->calls);
    }

    /**
     * @param array<string, mixed> $argument
     */
    private function runReuploadJob(array $argument): void
    {
        $userFolder = $this->createStub(Folder::class);
        $userFolder->method('getById')->willReturnCallback(fn (int $id): array => $this->node !== null && $id === 7 ? [$this->node] : []);
        $userFolder->method('getRelativePath')->willReturn('/Projects/plan.docx');
        $root = $this->createStub(IRootFolder::class);
        $root->method('getUserFolder')->willReturn($userFolder);

        $job = new ReuploadJob($this->accounts(), $root, $this->cloudState(), $this->client(), $this->logger());
        $job->setArgument($argument);
        $job->execute($this->createStub(IJobList::class));
    }

    private function file(int $id, string $name, int $size, string $etag): File
    {
        $file = $this->createStub(File::class);
        $file->method('getId')->willReturn($id);
        $file->method('getName')->willReturn($name);
        $file->method('getPath')->willReturn('/ada/files/Projects/' . $name);
        $file->method('getSize')->willReturn($size);
        $file->method('getEtag')->willReturn($etag);
        $file->method('getContent')->willReturn('content');

        return $file;
    }
}
