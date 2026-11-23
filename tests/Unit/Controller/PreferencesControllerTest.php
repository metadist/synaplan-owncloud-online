<?php

declare(strict_types=1);

namespace OCA\OcoSynaplan\Tests\Unit\Controller;

use OCA\OcoSynaplan\Controller\PreferencesController;
use OCA\OcoSynaplan\Service\SynaplanFolders;
use OCA\OcoSynaplan\Tests\Unit\Support\BuildsServices;
use OCP\AppFramework\Http;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;

final class PreferencesControllerTest extends TestCase
{
    use BuildsServices;

    protected function setUp(): void
    {
        $this->setUpServices();
        $this->signIn($this->person('ada'));
    }

    public function testTheFolderIsSavedForThePersonAlone(): void
    {
        $saved = $this->controller()->save('/Projects/Q3/');

        self::assertSame(['success' => true, 'copy_folder' => 'Projects/Q3'], $saved->getData());
        self::assertSame('Projects/Q3', $this->config->userValue('ada', SynaplanFolders::COPY_FOLDER));
        self::assertSame(['copy_folder' => 'Projects/Q3'], $this->controller()->get()->getData());

        $this->signIn($this->person('bob'));
        self::assertSame(['copy_folder' => ''], $this->controller()->get()->getData());
    }

    public function testTheTopOfTheFilesMeansTheSynaplanFolder(): void
    {
        $this->controller()->save('Projects');

        self::assertSame('', $this->controller()->save('/')->getData()['copy_folder']);
    }

    public function testAFolderOutsideThePersonsFilesIsRefused(): void
    {
        $refused = $this->controller()->save('Projects/../../other');

        self::assertSame(Http::STATUS_BAD_REQUEST, $refused->getStatus());
        self::assertNull($this->config->userValue('ada', SynaplanFolders::COPY_FOLDER));
    }

    public function testNobodySignedInHasNoFolder(): void
    {
        $this->signIn(null);

        self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller()->get()->getStatus());
        self::assertSame(Http::STATUS_UNAUTHORIZED, $this->controller()->save('Projects')->getStatus());
    }

    private function controller(): PreferencesController
    {
        return new PreferencesController($this->createStub(IRequest::class), $this->config, $this->session());
    }
}
