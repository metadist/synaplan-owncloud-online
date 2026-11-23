<?php

declare(strict_types=1);

namespace OCA\OcoSynaplan\Tests\Unit\Controller;

use OCA\OcoSynaplan\Controller\ApiController;
use OCA\OcoSynaplan\Service\LanguageService;
use OCA\OcoSynaplan\Service\ReadScope;
use OCA\OcoSynaplan\Tests\Unit\Support\BuildsServices;
use OCA\OcoSynaplan\Tests\Unit\Support\RecordingHttp;
use OCP\Files\IRootFolder;
use OCP\Http\Client\IResponse;
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
        $this->synaplanOffers($types, ['deliveries', 'pickups', 'read_scope']);

        self::assertSame(['translation' => $translation, 'read_folders' => true], $this->controller()->features()->getData());
    }

    public function testTheFolderMenuOffersReadingOnlyWhereTheAdministratorLetsPeopleNameFolders(): void
    {
        $this->synaplanOffers(['bullet-points'], ['deliveries', 'pickups', 'read_scope']);

        foreach ([ReadScope::MODE_WATCHED => false, ReadScope::MODE_ALLOWED => true, ReadScope::MODE_ALL => true] as $ceiling => $offered) {
            $this->config->setAppValue('oco_synaplan', ReadScope::CEILING, $ceiling);

            self::assertSame($offered, $this->controller()->features()->getData()['read_folders'], $ceiling);
        }
    }

    /**
     * @return array<string, array{0: list<string>|null, 1: bool}>
     */
    public static function configurations(): array
    {
        return [
            'reads folders' => [['deliveries', 'pickups', 'read_scope'], true],
            'takes no read setting' => [['deliveries', 'pickups'], false],
            'reads no folders' => [['deliveries', 'read_scope'], false],
            'has platform links off' => [null, false],
        ];
    }

    /**
     * @param list<string>|null $capabilities
     */
    #[DataProvider('configurations')]
    public function testTheFolderMenuOffersReadingOnlyWhereSynaplanReadsFolders(?array $capabilities, bool $offered): void
    {
        $this->config->setAppValue('oco_synaplan', ReadScope::CEILING, ReadScope::MODE_ALL);
        $this->synaplanOffers(['bullet-points'], $capabilities);

        self::assertSame($offered, $this->controller()->features()->getData()['read_folders']);
    }

    /**
     * @param list<string> $types
     * @param list<string>|null $capabilities null while Synaplan has platform links off
     */
    private function synaplanOffers(array $types, ?array $capabilities): void
    {
        $this->http->answer(static fn (string $method, string $path): array|IResponse => match (true) {
            $path !== '/.well-known/synaplan-configuration' => ['summary' => ['types' => $types]],
            $capabilities === null => RecordingHttp::response(404, '{"error":"not_found"}'),
            default => [
                'issuer' => 'https://synaplan.example',
                'jwks_uri' => 'https://synaplan.example/.well-known/jwks.json',
                'capabilities' => $capabilities,
            ],
        });
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
            $this->readScope(),
            $this->links(),
        );
    }
}
