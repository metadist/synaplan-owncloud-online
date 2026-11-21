<?php

declare(strict_types=1);

namespace OCA\OcoSynaplan\Tests\Unit\Controller;

use OCA\OcoSynaplan\Controller\ApiController;
use OCA\OcoSynaplan\Service\LanguageService;
use OCA\OcoSynaplan\Tests\Unit\Support\BuildsServices;
use OCP\Files\IRootFolder;
use OCP\IRequest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ApiControllerTest extends TestCase
{
    use BuildsServices;

    protected function setUp(): void
    {
        $this->setUpServices(['mode' => 'link']);
        $this->signIn($this->person('ada'));
    }

    /**
     * @return array<string, array{0: list<string>, 1: bool}>
     */
    public static function summaryTypes(): array
    {
        return [
            'translates' => [['bullet-points', 'translation'], true],
            'does not translate' => [['bullet-points', 'abstractive'], false],
        ];
    }

    /**
     * @param list<string> $types
     */
    #[DataProvider('summaryTypes')]
    public function testTheFilesAppIsToldWhetherSynaplanTranslates(array $types, bool $translation): void
    {
        $this->http->answer(static fn (): array => ['summary' => ['types' => $types]]);

        self::assertSame(['translation' => $translation], $this->controller()->features()->getData());
    }

    private function controller(): ApiController
    {
        return new ApiController(
            $this->createStub(IRequest::class),
            $this->client(),
            $this->createStub(IRootFolder::class),
            $this->session(),
            new LanguageService($this->config, $this->session()),
            $this->config,
            $this->appManager(),
            $this->logger(),
            $this->accounts(),
        );
    }
}
