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
use OCA\OcoSynaplan\Exception\FailureException;
use OCP\Files\IRootFolder;
use OCP\ILogger;

/**
 * New → From Synaplan…: the person's Synaplan files, and a copy of one into
 * the folder open in the Files app. Downloaded with the person's own
 * credential, so it works when Synaplan cannot reach this server.
 */
class LibraryImport
{
    private const SEARCH_MAX_LENGTH = 200;

    public function __construct(
        private SynaplanClient $client,
        private IRootFolder $rootFolder,
        private ILogger $logger,
        private OwnWrites $ownWrites,
    ) {
    }

    /**
     * @param string $filter `generated` or `all`
     * @return array{files: list<array{id: int, name: string, generated: bool, uploaded_at: int}>, page: int, pages: int}
     */
    public function list(int $page = 1, string $filter = 'generated', string $search = ''): array
    {
        $page = max(1, $page);
        $decoded = $this->client->listRecentFiles(
            $page,
            $filter !== 'all' ? 'generated' : '',
            mb_substr(trim($search), 0, self::SEARCH_MAX_LENGTH),
        );
        $files = [];
        foreach (is_array($decoded['files'] ?? null) ? $decoded['files'] : [] as $row) {
            if (!is_array($row) || !isset($row['id'])) {
                continue;
            }
            $name = $this->name($row);
            if ($name === '') {
                continue;
            }
            $files[] = [
                'id' => (int) $row['id'],
                'name' => $name,
                'generated' => ($row['generated'] ?? false) === true || ($row['source'] ?? '') === 'generated',
                'uploaded_at' => (int) ($row['uploaded_at'] ?? 0),
            ];
        }
        $pages = is_array($decoded['pagination'] ?? null) ? (int) ($decoded['pagination']['pages'] ?? 1) : 1;

        return [
            'files' => $files,
            'page' => $page,
            'pages' => max(1, $pages),
        ];
    }

    /**
     * @param string $folder the folder open in the Files app, '' or '/' for the top
     * @return array{name: string, folder: string}
     */
    public function copy(string $uid, int $fileId, string $folder, string $name): array
    {
        if ($fileId < 1) {
            throw new \InvalidArgumentException('Missing file.');
        }
        $destination = SynaplanFolders::cleanFolder($folder);
        $bytes = $this->client->downloadStoredFile($fileId, CopyDelivery::MAX_BYTES);
        $name = SynaplanFolders::fileName(trim($name));

        try {
            $userFolder = $this->rootFolder->getUserFolder($uid);
            $file = SynaplanFolders::write(SynaplanFolders::ensure($userFolder, $destination), $name, $bytes);
        } catch (\Throwable $e) {
            $this->logger->warning('Could not copy a Synaplan file for {uid}: {message}', [
                'app' => Application::APP_ID,
                'uid' => $uid,
                'message' => $e->getMessage(),
            ]);
            throw FailureException::fromWrite($e);
        }
        $this->ownWrites->note($file, time());

        $relative = ltrim((string) ($userFolder->getRelativePath($file->getPath()) ?? $file->getName()), '/');
        $directory = dirname($relative);

        return [
            'name' => $file->getName(),
            'folder' => $directory === '.' ? '' : $directory,
        ];
    }

    /**
     * @param array<string, mixed> $row
     */
    private function name(array $row): string
    {
        $display = is_string($row['display_name'] ?? null) ? trim($row['display_name']) : '';
        $filename = is_string($row['filename'] ?? null) ? trim($row['filename']) : '';
        $base = basename(str_replace('\\', '/', $display !== '' ? $display : $filename));

        return $base === '.' || $base === '..' ? '' : $base;
    }
}
