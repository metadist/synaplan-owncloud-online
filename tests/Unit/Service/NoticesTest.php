<?php

declare(strict_types=1);

namespace OCA\OcoSynaplan\Tests\Unit\Service;

use OCA\OcoSynaplan\Notification\CopyNotifier;
use OCA\OcoSynaplan\Service\Notices;
use OCA\OcoSynaplan\Tests\Unit\Support\MemoryConfig;
use OCP\App\IAppManager;
use OCP\IL10N;
use OCP\ILogger;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use OCP\Notification\IManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;

final class NoticesTest extends TestCase
{
    private MemoryConfig $config;
    private bool $notificationsApp = false;

    protected function setUp(): void
    {
        $this->config = new MemoryConfig();
    }

    public function testWithoutTheNotificationsAppTheNextPageShowsTheCopy(): void
    {
        $notices = $this->notices($this->createMock(IManager::class));

        $notices->copied('ada', 'plan.docx', 'Synaplan/Documents', 42);

        self::assertTrue($notices->hasPending('ada'));
        self::assertFalse($notices->hasPending('bob'));
        self::assertSame(
            [['subject' => 'copied', 'name' => 'plan.docx', 'folder' => 'Synaplan/Documents', 'file_id' => 42, 'link' => '/index.php/f/42']],
            $notices->pending('ada'),
            'the link is made for the page that asks, not for the address Synaplan called',
        );
    }

    public function testAPageForgetsOnlyTheNoticesItShowed(): void
    {
        $notices = $this->notices($this->createMock(IManager::class));
        $notices->copied('ada', 'a.pdf', 'Synaplan', 1);
        $notices->copied('ada', 'b.pdf', 'Synaplan', 2);
        $notices->copied('ada', 'c.pdf', 'Synaplan', 3);

        $notices->forget('ada', 2);
        self::assertSame(['c.pdf'], array_column($notices->pending('ada'), 'name'));

        $notices->forget('ada', 1);
        self::assertFalse($notices->hasPending('ada'));
        self::assertSame([], $notices->pending('ada'));
    }

    public function testOnlyTheLatestTwentyAreKept(): void
    {
        $notices = $this->notices($this->createMock(IManager::class));
        for ($n = 1; $n <= 25; $n++) {
            $notices->copied('ada', $n . '.pdf', 'Synaplan', $n);
        }

        $pending = $notices->pending('ada');
        self::assertCount(20, $pending);
        self::assertSame('6.pdf', $pending[0]['name']);
    }

    public function testWithTheNotificationsAppTheCopyIsANotification(): void
    {
        $this->notificationsApp = true;
        $notification = $this->createMock(INotification::class);
        foreach (['setApp', 'setUser', 'setDateTime', 'setObject', 'setSubject'] as $setter) {
            $notification->method($setter)->willReturnSelf();
        }
        $notification->expects($this->once())->method('setObject')->with('file', '42');
        $notification->expects($this->once())->method('setSubject')
            ->with('copied', ['name' => 'plan.docx', 'folder' => 'Synaplan/Documents']);
        $notification->expects($this->never())->method('setLink');
        $manager = $this->createMock(IManager::class);
        $manager->method('createNotification')->willReturn($notification);
        $manager->expects($this->once())->method('notify')->with($notification);
        $notices = $this->notices($manager);

        $notices->copied('ada', 'plan.docx', 'Synaplan/Documents', 42);

        self::assertFalse($notices->hasPending('ada'));
    }

    public function testANotificationThatFailsIsShownOnTheNextPageInstead(): void
    {
        $this->notificationsApp = true;
        $manager = $this->createMock(IManager::class);
        $manager->method('createNotification')->willThrowException(new \RuntimeException('no notifier'));

        $this->notices($manager)->copied('ada', 'plan.docx', 'Synaplan', 42);

        self::assertTrue($this->notices($manager)->hasPending('ada'));
    }

    public function testTheNotificationSaysWhatWasCopiedWhere(): void
    {
        $l = $this->createStub(IL10N::class);
        $l->method('t')->willReturnCallback(static fn (string $text): string => $text);
        $factory = $this->createStub(IFactory::class);
        $factory->method('get')->willReturn($l);
        $urls = $this->createStub(IURLGenerator::class);
        $urls->method('imagePath')->willReturn('/apps/oco_synaplan/img/app.svg');
        $urls->method('getAbsoluteURL')->willReturnCallback(static fn (string $path): string => 'https://cloud.example' . $path);
        $urls->method('linkToRouteAbsolute')->willReturnCallback(
            static fn (string $route, array $parameters): string => $route === 'files.viewcontroller.showFile' ? 'https://cloud.example/index.php/f/' . $parameters['fileId'] : '',
        );
        $notifier = new CopyNotifier($factory, $urls);

        foreach (['Synaplan/Documents' => 'Synaplan copied plan.docx to Synaplan/Documents.', '' => 'Synaplan copied plan.docx to Files.'] as $folder => $sentence) {
            $notification = $this->createMock(INotification::class);
            $notification->method('getApp')->willReturn('oco_synaplan');
            $notification->method('getSubject')->willReturn('copied');
            $notification->method('getSubjectParameters')->willReturn(['name' => 'plan.docx', 'folder' => $folder]);
            $notification->method('getObjectId')->willReturn('42');
            $notification->expects($this->once())->method('setParsedSubject')->with($sentence)->willReturnSelf();
            $notification->expects($this->once())->method('setIcon')->with('https://cloud.example/apps/oco_synaplan/img/app.svg')->willReturnSelf();
            $notification->expects($this->once())->method('setLink')->with('https://cloud.example/index.php/f/42')->willReturnSelf();

            $notifier->prepare($notification, 'en');
        }
    }

    public function testAnotherAppsNotificationIsLeftAlone(): void
    {
        $notification = $this->createStub(INotification::class);
        $notification->method('getApp')->willReturn('files_sharing');

        $this->expectException(\InvalidArgumentException::class);
        (new CopyNotifier($this->createStub(IFactory::class), $this->createStub(IURLGenerator::class)))->prepare($notification, 'en');
    }

    private function notices(IManager $manager): Notices
    {
        $apps = $this->createStub(IAppManager::class);
        $apps->method('isEnabledForUser')->willReturnCallback(fn (): bool => $this->notificationsApp);
        $users = $this->createStub(IUserManager::class);
        $users->method('get')->willReturn($this->createStub(IUser::class));
        $urls = $this->createStub(IURLGenerator::class);
        $urls->method('linkToRoute')->willReturnCallback(
            static fn (string $route, array $parameters): string => $route === 'files.viewcontroller.showFile' ? '/index.php/f/' . $parameters['fileId'] : '',
        );

        return new Notices($this->config, $manager, $apps, $users, $urls, $this->createStub(ILogger::class));
    }
}
