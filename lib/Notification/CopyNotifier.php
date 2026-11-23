<?php

declare(strict_types=1);

/**
 * Synaplan Integration for owncloud.online
 *
 * @copyright Copyright (c) 2026, Synaplan
 * @license Apache-2.0
 */

namespace OCA\OcoSynaplan\Notification;

use OCA\OcoSynaplan\AppInfo\Application;
use OCA\OcoSynaplan\Service\Notices;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;
use OCP\Notification\INotification;
use OCP\Notification\INotifier;

/**
 * The sentence of a copy's notification, for installations with the
 * notifications app.
 */
class CopyNotifier implements INotifier
{
    public function __construct(
        private IFactory $l10nFactory,
        private IURLGenerator $urls,
    ) {
    }

    /**
     * @param string $languageCode
     */
    public function prepare(INotification $notification, $languageCode)
    {
        if ($notification->getApp() !== Application::APP_ID || $notification->getSubject() !== Notices::SUBJECT_COPIED) {
            throw new \InvalidArgumentException('Not a notification of this app.');
        }
        $l = $this->l10nFactory->get(Application::APP_ID, $languageCode);
        $parameters = $notification->getSubjectParameters();
        $folder = (string) ($parameters['folder'] ?? '');
        $notification->setParsedSubject(strtr((string) $l->t('Synaplan copied {name} to {folder}.'), [
            '{name}' => (string) ($parameters['name'] ?? ''),
            '{folder}' => $folder !== '' ? $folder : (string) $l->t('Files'),
        ]));
        $notification->setIcon($this->urls->getAbsoluteURL($this->urls->imagePath(Application::APP_ID, 'app.svg')));
        $notification->setLink($this->urls->linkToRouteAbsolute('files.viewcontroller.showFile', [
            'fileId' => (int) $notification->getObjectId(),
        ]));

        return $notification;
    }
}
