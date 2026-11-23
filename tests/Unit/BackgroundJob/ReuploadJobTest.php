<?php

declare(strict_types=1);

namespace OCA\OcoSynaplan\Tests\Unit\BackgroundJob;

use OCA\OcoSynaplan\BackgroundJob\ReuploadJob;
use OCA\OcoSynaplan\Exception\FailureException;
use OCA\OcoSynaplan\Http\Failure;
use OCA\OcoSynaplan\Tests\Unit\Support\BuildsServices;
use OCA\OcoSynaplan\Tests\Unit\Support\RecordingHttp;
use OCP\AppFramework\Http;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Http\Client\IResponse;
use OCP\ILogger;
use PHPUnit\Framework\Attributes\DataProvider;
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
    private int $now = 1_000;
    /** @var list<array{string, mixed}> what the job list was asked, as `add` or `removeById` and the job */
    private array $list = [];
    /** @var list<string> the warnings the job logged */
    private array $warnings = [];

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
        self::assertSame([['removeById', 3]], $this->list, 'done, the job leaves the list');
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
    #[DataProvider('notKept')]
    public function testACopyNoLongerKeptUpToDateIsNotUploaded(array $state): void
    {
        $this->register();
        $this->connect('ada', 'access-ada');
        $this->state = $state + $this->state;

        $this->runReuploadJob(['uid' => 'ada', 'id' => '7']);

        self::assertCount(1, $this->http->to('/api/v1/cloud/status'));
        self::assertSame([], $this->http->to('/api/v1/files/upload'));
        self::assertSame([], $this->retries());
    }

    public function testNothingIsUploadedOrTriedAgainForAFileThatIsGoneTooLargeOrOfSomeoneWithoutACredential(): void
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
        self::assertSame([], $this->retries());
    }

    public function testAHeldGrantWhoseTokenCannotBeRenewedNowIsTriedAgainInAMinute(): void
    {
        $this->register();
        $this->connect('ada', 'access-ada', -60);
        $this->http->answer(static fn (string $method, string $path): array|\Throwable => $path === '/oauth/token'
            ? new \RuntimeException('cURL error 7: Failed to connect', 0)
            : ['items' => []]);

        $this->runReuploadJob(['uid' => 'ada', 'id' => '7']);

        self::assertSame([['uid' => 'ada', 'id' => '7', 'attempt' => 1, 'due' => 1_060]], $this->retries());
        self::assertSame([], $this->http->to('/api/v1/cloud/status'));
    }

    public function testAnUnreachableSynaplanIsAskedAgainLaterEachTime(): void
    {
        $this->register();
        $this->connect('ada', 'access-ada');
        $this->http->answer(static fn (string $method, string $path): array|\Throwable => $path === '/api/v1/cloud/status'
            ? new \RuntimeException('cURL error 28: Operation timed out', 0)
            : ['success' => true]);

        $this->runReuploadJob(['uid' => 'ada', 'id' => '7', 'attempt' => 2, 'due' => 1_000]);

        self::assertSame([['uid' => 'ada', 'id' => '7', 'attempt' => 3, 'due' => 1_900]], $this->retries());
        self::assertSame([], $this->http->to('/api/v1/files/upload'));
    }

    /**
     * @return iterable<string, array{\Throwable|IResponse, bool}>
     */
    public static function uploadFailures(): iterable
    {
        yield 'no answer' => [new \RuntimeException('cURL error 28: Operation timed out', 0), true];
        yield 'busy' => [new \RuntimeException('Too Many Requests', 429), true];
        yield 'failing' => [new \RuntimeException('Bad Gateway', 502), true];
        yield 'file refused' => [new \RuntimeException('Payload Too Large', 413), false];
        yield 'key refused' => [new \RuntimeException('Unauthorized', 401), false];
        yield 'answer unreadable' => [RecordingHttp::response(200, '<html>Bad Gateway</html>'), true];
        yield 'key refused for a provisioned account' => [
            new FailureException(new Failure(Http::STATUS_BAD_GATEWAY, Failure::SYNAPLAN_ERROR), 'Synaplan refused the key'),
            false,
        ];
    }

    #[DataProvider('uploadFailures')]
    public function testAnUploadThatFailsForNowIsTriedAgain(\Throwable|IResponse $failure, bool $again): void
    {
        $this->register();
        $this->connect('ada', 'access-ada');
        $this->http->answer(fn (string $method, string $path): array|\Throwable|IResponse => match ($path) {
            '/api/v1/cloud/status' => ['items' => [$this->state]],
            '/api/v1/files/upload' => $failure,
            default => ['success' => true],
        });

        $this->runReuploadJob(['uid' => 'ada', 'id' => '7']);

        self::assertSame($again ? [['uid' => 'ada', 'id' => '7', 'attempt' => 1, 'due' => 1_060]] : [], $this->retries());
        self::assertCount(1, $this->http->to('/api/v1/files/upload'));
    }

    public function testTheLastRetryIsTheLast(): void
    {
        $this->register();
        $this->connect('ada', 'access-ada');
        $this->http->answer(fn (string $method, string $path): array|\Throwable => match ($path) {
            '/api/v1/cloud/status' => ['items' => [$this->state]],
            '/api/v1/files/upload' => new \RuntimeException('cURL error 7: Failed to connect', 0),
            default => ['success' => true],
        });

        $this->runReuploadJob(['uid' => 'ada', 'id' => '7', 'attempt' => count(ReuploadJob::RETRY_DELAYS)]);

        self::assertSame([], $this->retries());
        self::assertContains('Gave up uploading file {id} of {uid} again', $this->warnings);
    }

    public function testARetryWaitsInTheListUntilItIsDue(): void
    {
        $this->register();
        $this->connect('ada', 'access-ada');
        $retry = ['uid' => 'ada', 'id' => '7', 'attempt' => 1, 'due' => 1_060];

        $this->now = 1_059;
        $this->runReuploadJob($retry);

        self::assertSame([], $this->http->calls);
        self::assertSame([], $this->list, 'it stays in the list');

        $this->now = 1_060;
        $this->runReuploadJob($retry);

        self::assertCount(1, $this->http->to('/api/v1/files/upload'));
        self::assertSame([['removeById', 3]], $this->list);
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
        $jobs = $this->createStub(IJobList::class);
        $jobs->method('add')->willReturnCallback(function (mixed $job, mixed $argument = null): void {
            $this->list[] = ['add', [$job, $argument]];
        });
        $jobs->method('removeById')->willReturnCallback(function (mixed $id): void {
            $this->list[] = ['removeById', $id];
        });
        $time = $this->createStub(ITimeFactory::class);
        $time->method('getTime')->willReturnCallback(fn (): int => $this->now);
        $logger = $this->createStub(ILogger::class);
        $logger->method('warning')->willReturnCallback(function (string $message): void {
            $this->warnings[] = $message;
        });

        $job = new ReuploadJob($this->accounts(), $root, $this->cloudState(), $this->client(), $logger, $jobs, $time);
        $job->setId(3);
        $job->setArgument($argument);
        $job->execute($this->createStub(IJobList::class));
    }

    /**
     * @return list<mixed> the argument of each job of this kind added to the list
     */
    private function retries(): array
    {
        $out = [];
        foreach ($this->list as [$call, $job]) {
            if ($call === 'add' && is_array($job) && $job[0] === ReuploadJob::class) {
                $out[] = $job[1];
            }
        }

        return $out;
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
