<?php

declare(strict_types=1);

/**
 * Synaplan Integration for owncloud.online
 *
 * @copyright Copyright (c) 2026, Synaplan
 * @license Apache-2.0
 */

namespace OCA\OcoSynaplan\Service;

use OCA\OcoSynaplan\AppInfo\Application;
use OCP\Files\AlreadyExistsException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\NotEnoughSpaceException;
use OCP\Files\NotFoundException;
use OCP\IConfig;

/**
 * Where a file from Synaplan lands and under which name (`03` §3): the
 * person's own folder ("Save files from Synaplan to") or `Synaplan`, with a
 * sub-folder for its kind, and `name (2).ext` when the name is taken.
 */
class SynaplanFolders
{
    public const COPY_FOLDER = 'copy_folder';
    public const DEFAULT_FOLDER = 'Synaplan';
    /** `name.ext`, then `name (2).ext` up to `name (20).ext`; beyond that the file is not written. */
    public const MAX_SAME_NAME = 20;

    private const KIND_FOLDERS = [
        'docx' => 'Documents', 'doc' => 'Documents', 'pptx' => 'Documents',
        'ppt' => 'Documents', 'xlsx' => 'Documents', 'xls' => 'Documents',
        'csv' => 'Documents', 'pdf' => 'Documents', 'txt' => 'Documents',
        'md' => 'Documents', 'odt' => 'Documents', 'rtf' => 'Documents',
        'mp3' => 'Audio', 'wav' => 'Audio', 'ogg' => 'Audio',
        'm4a' => 'Audio', 'flac' => 'Audio', 'aac' => 'Audio',
        'ics' => 'Calendar',
        'png' => 'Images', 'jpg' => 'Images', 'jpeg' => 'Images',
        'gif' => 'Images', 'webp' => 'Images', 'svg' => 'Images',
        'mp4' => 'Video', 'webm' => 'Video', 'mov' => 'Video', 'mkv' => 'Video',
    ];

    public function __construct(
        private IConfig $config,
    ) {
    }

    /**
     * A folder a person typed or picked. Empty means the Synaplan folder;
     * `..` is refused.
     */
    public static function cleanFolder(string $folder): string
    {
        $folder = trim(str_replace('\\', '/', trim($folder)), '/');
        if ($folder === '') {
            return '';
        }
        foreach (explode('/', $folder) as $segment) {
            if (trim($segment) === '' || $segment === '.' || $segment === '..') {
                throw new \InvalidArgumentException('That folder cannot be used.');
            }
        }

        return $folder;
    }

    /**
     * The folder the person chose, '' for the Synaplan folder.
     */
    public function chosenFolder(string $uid): string
    {
        try {
            return self::cleanFolder((string) $this->config->getUserValue($uid, Application::APP_ID, self::COPY_FOLDER, ''));
        } catch (\InvalidArgumentException) {
            return '';
        }
    }

    /**
     * The folder a file called $name lands in, made when missing.
     */
    public function kindFolder(Folder $userFolder, string $uid, string $name): Folder
    {
        return self::ensure($userFolder, $this->kindPath($uid, $name));
    }

    /**
     * That folder's path in the person's files, without a leading slash.
     */
    public function kindPath(string $uid, string $name): string
    {
        $root = $this->chosenFolder($uid);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return trim(($root !== '' ? $root : self::DEFAULT_FOLDER) . '/' . (self::KIND_FOLDERS[$ext] ?? ''), '/');
    }

    /**
     * The folder at $path below $root, each missing level made.
     */
    public static function ensure(Folder $root, string $path): Folder
    {
        $current = $root;
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                continue;
            }
            if ($current->nodeExists($segment)) {
                $node = $current->get($segment);
                if (!$node instanceof Folder) {
                    throw new NotFoundException('A file is where a folder should be.');
                }
                $current = $node;
            } else {
                $current = $current->newFolder($segment);
            }
        }

        return $current;
    }

    /**
     * $name, or `name (2).ext` and up when it is taken. With $max, more
     * files of that name than $max is a conflict.
     */
    public static function freeName(Folder $folder, string $name, ?int $max = null): string
    {
        $target = $name;
        $stem = pathinfo($name, PATHINFO_FILENAME);
        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $n = 2;
        while ($folder->nodeExists($target)) {
            if ($max !== null && $n > $max) {
                throw new AlreadyExistsException('Too many files with this name already exist.');
            }
            $target = $stem . ' (' . $n . ')' . ($ext !== '' ? '.' . $ext : '');
            $n++;
        }

        return $target;
    }

    /**
     * A new file in $folder with $bytes, renamed when $name is taken.
     */
    public static function write(Folder $folder, string $name, string $bytes): File
    {
        self::assertFits($folder, strlen($bytes));
        $file = $folder->newFile(self::freeName($folder, $name, self::MAX_SAME_NAME));
        try {
            $file->putContent($bytes);
        } catch (\Throwable $e) {
            // ownCloud makes the file before it writes it: no empty file stays.
            try {
                $file->delete();
            } catch (\Throwable) {
            }
            throw $e;
        }

        return $file;
    }

    /**
     * A write over the person's quota fails in the storage as a plain
     * refusal; this says it is the quota.
     */
    public static function assertFits(Folder $folder, int $bytes): void
    {
        $free = $folder->getFreeSpace();
        if ($free >= 0 && $free < $bytes) {
            throw new NotEnoughSpaceException('The file does not fit in the person\'s storage.');
        }
    }

    /**
     * A file name from Synaplan, reduced to its last segment.
     */
    public static function fileName(string $name): string
    {
        $base = basename(str_replace('\\', '/', $name));

        return $base !== '' && $base !== '.' && $base !== '..' ? $base : 'file';
    }
}
