<?php

declare(strict_types=1);

namespace OCA\OcoSynaplan\Tests\Unit\Controller;

use OCA\OcoSynaplan\Controller\MediaController;
use OCA\OcoSynaplan\Tests\Unit\Support\BuildsServices;
use OCA\OcoSynaplan\Tests\Unit\Support\FakeCrypto;
use OCA\OcoSynaplan\Tests\Unit\Support\RecordingHttp;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\Files\IRootFolder;
use OCP\Http\Client\IResponse;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

final class MediaControllerTest extends TestCase
{
    use BuildsServices;

    protected function setUp(): void
    {
        $this->setUpServices(['mode' => 'shared', 'api_key' => FakeCrypto::stored('sk_admin')]);
        $this->signIn($this->person('ada'));
    }

    public function testOnlyAPathOnTheSynaplanHostIsFetched(): void
    {
        foreach (['', 'http://evil.example.org/x.png', '//evil.example.org/x.png', '@evil.example.org/x.png'] as $url) {
            self::assertSame(400, $this->controller()->proxy($url)->getStatus(), $url);
        }
        self::assertSame([], $this->http->calls, 'the key never leaves for another host');
    }

    public function testASynaplanImageIsPassedOnWithItsType(): void
    {
        $this->http->answer(static fn (): IResponse => RecordingHttp::response(200, 'PNG-BYTES'));

        $response = $this->controller()->proxy('/api/v1/files/uploads/1/sunrise.png');

        self::assertInstanceOf(DataDownloadResponse::class, $response);
        self::assertSame('image/png', $response->getHeaders()['Content-Type']);
        self::assertSame(self::SYNAPLAN . '/api/v1/files/uploads/1/sunrise.png', $this->http->calls[0]['url']);
    }

    private function controller(): MediaController
    {
        return new MediaController($this->createStub(IRequest::class), $this->client(), $this->createStub(IRootFolder::class), $this->session(), $this->logger());
    }
}
