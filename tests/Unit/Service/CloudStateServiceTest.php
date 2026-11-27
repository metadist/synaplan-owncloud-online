<?php

declare(strict_types=1);

namespace OCA\OcoSynaplan\Tests\Unit\Service;

use OCA\OcoSynaplan\Service\KeyStorage;
use OCA\OcoSynaplan\Tests\Unit\Support\BuildsServices;
use OCA\OcoSynaplan\Tests\Unit\Support\FakeCrypto;
use OCA\OcoSynaplan\Tests\Unit\Support\RecordingHttp;
use OCP\Http\Client\IResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CloudStateServiceTest extends TestCase
{
    use BuildsServices;

    private const ROW = [
        'id' => '7',
        'state' => 'searchable',
        'knowledge_folder' => ['key' => 'Contracts', 'name' => 'Contracts'],
        'file_id' => 42,
        'kept_up_to_date' => true,
    ];

    protected function setUp(): void
    {
        $this->setUpServices(['mode' => 'provision']);
        $this->signIn($this->person('ada'));
        $this->config->setUserValue('ada', 'oco_synaplan', KeyStorage::USER_KEY, FakeCrypto::stored('sk_ada'));
    }

    /**
     * @return array<string, array{0: string, 1: list<string>|null, 2: bool}>
     */
    public static function availability(): array
    {
        return [
            'cloud files on, provisioned' => ['provision', ['deliveries', 'cloud_status'], true],
            'cloud files on, connected' => ['link', ['deliveries', 'cloud_status'], true],
            'cloud files on, one shared key' => ['shared', ['deliveries', 'cloud_status'], false],
            'cloud files off' => ['provision', ['deliveries', 'pickups', 'read_scope'], false],
            'platform links off' => ['provision', null, false],
        ];
    }

    /**
     * @param list<string>|null $capabilities null while Synaplan has platform links off
     */
    #[DataProvider('availability')]
    public function testFilesShowTheirStateOnlyWhereSynaplanTellsItForEachPerson(string $mode, ?array $capabilities, bool $available): void
    {
        $this->config->setAppValue('oco_synaplan', 'mode', $mode);
        $this->http->answer(static fn (): array|IResponse => $capabilities === null
            ? RecordingHttp::response(404, '{"error":"not_found"}')
            : ['capabilities' => $capabilities]);

        self::assertSame($available, $this->cloudState()->available());
    }

    public function testTheStateOfAFolderViewIsAskedWithThePersonsKeyInOneRequest(): void
    {
        $this->http->answer(static fn (): array => ['items' => [self::ROW, ['id' => '8', 'state' => 'none']], 'watches' => []]);

        $answer = $this->cloudState()->status([['id' => '7', 'version' => '"e1"'], ['id' => '8', 'version' => '']]);

        self::assertSame(['enabled' => true, 'items' => [self::ROW, ['id' => '8', 'state' => 'none']], 'watches' => []], $answer);
        $call = $this->http->to('/api/v1/cloud/status')[0];
        self::assertSame('POST', $call['method']);
        self::assertSame(['items' => [['id' => '7', 'version' => '"e1"'], ['id' => '8', 'version' => '']]], $call['body']);
        self::assertSame('sk_ada', $call['options']['headers']['X-API-Key']);
        self::assertFalse($call['options']['http_errors']);
    }

    public function testAJobAsksWithTheCredentialItWasGiven(): void
    {
        $this->signIn(null);
        $this->http->answer(static fn (): array => ['items' => [self::ROW]]);

        $this->cloudState()->status([['id' => '7', 'version' => 'e1']], 'access-ada');

        self::assertSame('Bearer access-ada', $this->http->to('/api/v1/cloud/status')[0]['options']['headers']['Authorization']);
    }

    public function testAtMostFiveHundredFilesAreAskedAtOnce(): void
    {
        $this->http->answer(static fn (): array => ['items' => []]);
        $items = array_map(static fn (int $id): array => ['id' => (string) $id, 'version' => 'e'], range(1, 501));

        $this->cloudState()->status($items);

        self::assertCount(500, $this->http->to('/api/v1/cloud/status')[0]['body']['items']);
    }

    public function testCloudFilesTurnedOffInSynaplanShowNothing(): void
    {
        $this->http->answer(static fn (): IResponse => RecordingHttp::response(404, '{"error":"not_found"}'));

        self::assertSame(['enabled' => false, 'items' => [], 'watches' => []], $this->cloudState()->status([['id' => '7', 'version' => 'e1']]));
    }

    /**
     * @return array<string, array{0: IResponse|\Throwable}>
     */
    public static function failures(): array
    {
        return [
            'server error' => [RecordingHttp::response(500, '{"error":"oops"}')],
            'refused key' => [RecordingHttp::response(401, '{"error":"unauthorized"}')],
            'no answer' => [new \RuntimeException('timed out')],
        ];
    }

    #[DataProvider('failures')]
    public function testASynaplanThatDoesNotAnswerIsShownAsUnreachable(IResponse|\Throwable $failure): void
    {
        $this->http->answer(static fn (): IResponse|\Throwable => $failure);

        self::assertSame(
            ['enabled' => true, 'unreachable' => true, 'items' => [], 'watches' => []],
            $this->cloudState()->status([['id' => '7', 'version' => 'e1']]),
        );
    }

    public function testNothingIsAskedWithoutAPersonsCredential(): void
    {
        $this->config->setUserValue('ada', 'oco_synaplan', KeyStorage::USER_KEY, '');

        self::assertSame(['enabled' => false, 'items' => [], 'watches' => []], $this->cloudState()->status([['id' => '7', 'version' => 'e1']]));
        self::assertSame([], $this->http->calls);
    }

    public function testNothingIsAskedWithTheSharedKey(): void
    {
        $this->config->setAppValue('oco_synaplan', 'mode', 'shared');
        $this->config->setAppValue('oco_synaplan', 'api_key', FakeCrypto::stored('sk_shared'));

        self::assertFalse($this->cloudState()->status([['id' => '7', 'version' => 'e1']])['enabled']);
        self::assertNull($this->cloudState()->detail(42));
        self::assertSame([], $this->http->calls);
    }

    public function testOneFileIsAskedByItsSynaplanId(): void
    {
        $this->http->answer(static fn (): array => self::ROW);

        self::assertSame(['status_code' => 200] + self::ROW, $this->cloudState()->detail(42));
        self::assertSame('GET', $this->http->to('/api/v1/cloud/files/42')[0]['method']);
    }

    public function testAFileSynaplanDoesNotKnowHasNoDetail(): void
    {
        $this->http->answer(static fn (): IResponse => RecordingHttp::response(404, '{"error":"not_found"}'));

        self::assertNull($this->cloudState()->detail(42));
        self::assertNull($this->cloudState()->detail(0));
        self::assertCount(1, $this->http->calls);
    }

    public function testTheGlyphsSynaplanSetsAreKeptForFiveMinutes(): void
    {
        $this->http->answer(static fn (): array => ['cloud_files' => ['glyphs' => ['bird' => 'https://synaplan.example.org/bird.svg', 'bird_eyes' => '']]]);

        $first = $this->cloudState()->glyphOverrides();
        $second = $this->cloudState()->glyphOverrides();

        self::assertSame(['bird' => 'https://synaplan.example.org/bird.svg', 'bird_eyes' => null], $first);
        self::assertSame($first, $second);
        self::assertCount(1, $this->http->to('/api/v1/config/runtime'));
        self::assertArrayNotHasKey('Authorization', $this->http->calls[0]['options']['headers']);
        self::assertSame([300], array_values($this->cache->ttls));
    }

    public function testNoGlyphsAreShownInsteadOfTheBirdWhenSynaplanSetsNone(): void
    {
        $this->http->answer(static fn (): array => ['cloud_files' => []]);

        self::assertSame(['bird' => null, 'bird_eyes' => null], $this->cloudState()->glyphOverrides());
        self::assertSame([300], array_values($this->cache->ttls));
    }

    public function testWithoutAnAnswerTheBirdIsShownAndSynaplanIsAskedAgainAfterAMinute(): void
    {
        foreach (['an error' => RecordingHttp::response(500, ''), 'no answer' => new \RuntimeException('Connection refused')] as $case => $answer) {
            $this->setUpServices(['mode' => 'provision']);
            $this->http->answer(static fn (): IResponse|\Throwable => $answer);

            $first = $this->cloudState()->glyphOverrides();
            $second = $this->cloudState()->glyphOverrides();

            self::assertSame(['bird' => null, 'bird_eyes' => null], $first, $case);
            self::assertSame($first, $second, $case);
            self::assertCount(1, $this->http->calls, $case);
            self::assertSame([60], array_values($this->cache->ttls), $case);
        }
    }
}
