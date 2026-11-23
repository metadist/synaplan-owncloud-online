<?php

declare(strict_types=1);

/**
 * Synaplan Integration for owncloud.online
 *
 * @copyright Copyright (c) 2026, Synaplan
 * @license Apache-2.0
 */

namespace OCA\OcoSynaplan\Service;

use OCP\Files\Folder;
use OCP\Files\IRootFolder;

/**
 * A person's files when nobody is signed in: a call from Synaplan or a
 * background job. ownCloud.online applies its storage rules (quota,
 * read-only groups) when it sets up a person's filesystem, which
 * IRootFolder::getUserFolder() alone does not do, so a copy written without
 * this would ignore the person's quota.
 */
class PersonFiles
{
    public function __construct(
        private IRootFolder $rootFolder,
    ) {
    }

    public function folderOf(string $uid): Folder
    {
        \OC_Util::tearDownFS();
        \OC_Util::setupFS($uid);

        return $this->rootFolder->getUserFolder($uid);
    }
}
