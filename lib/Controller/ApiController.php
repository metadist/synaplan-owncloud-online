<?php

declare(strict_types=1);

/**
 * Synaplan Integration for owncloud.online
 *
 * @copyright Copyright (c) 2026, Synaplan
 * @license Apache-2.0
 */

namespace OCA\OcoSynaplan\Controller;

use OCA\OcoSynaplan\AppInfo\Application;
use OCA\OcoSynaplan\Exception\FailureException;
use OCA\OcoSynaplan\Http\RespondsToFailures;
use OCA\OcoSynaplan\Service\CloudStateService;
use OCA\OcoSynaplan\Service\LanguageService;
use OCA\OcoSynaplan\Service\PlatformLinkService;
use OCA\OcoSynaplan\Service\ReadScope;
use OCA\OcoSynaplan\Service\SynaplanClient;
use OCA\OcoSynaplan\Service\UserAccountService;
use OCA\OcoSynaplan\Service\WatchPolicy;
use OCP\App\IAppManager;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\Files\File;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;
use OCP\IConfig;
use OCP\ILogger;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * API controller for document AI operations.
 *
 * Provides summarization, translation and knowledge-base management of
 * ownCloud.online files via the Synaplan API.
 */
class ApiController extends Controller
{
    use RespondsToFailures;

    /** Max file size for direct text extraction (5 MB). */
    private const MAX_TEXT_FILE_SIZE = 5 * 1024 * 1024;

    /** Max file size for binary upload to Synaplan (100 MB). */
    private const MAX_UPLOAD_FILE_SIZE = 100 * 1024 * 1024;

    /** MIME types we can extract text from directly (in PHP). */
    private const TEXT_MIME_TYPES = [
        'text/plain',
        'text/markdown',
        'text/csv',
        'text/html',
        'text/xml',
        'text/rtf',
        'application/json',
        'application/xml',
        'application/rtf',
    ];

    /** Binary MIME types that Synaplan can extract text from via Tika. */
    private const BINARY_MIME_TYPES = [
        'application/pdf',
        'application/msword',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.oasis.opendocument.text',
        'application/vnd.ms-excel',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.oasis.opendocument.spreadsheet',
        'application/vnd.ms-powerpoint',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'application/vnd.oasis.opendocument.presentation',
    ];

    public function __construct(
        IRequest $request,
        private SynaplanClient $synaplanClient,
        private IRootFolder $rootFolder,
        private IUserSession $userSession,
        private LanguageService $languageService,
        private IConfig $config,
        private IAppManager $appManager,
        private ILogger $logger,
        private UserAccountService $userAccounts,
        private ReadScope $readScope,
        private PlatformLinkService $links,
        private CloudStateService $cloudState,
    ) {
        parent::__construct(Application::APP_ID, $request);
    }

    /**
     * Client runtime config for the chat UI and the consent gate: the answer
     * language, whether memories work, and how the person uses Synaplan.
     *
     * @NoAdminRequired
     */
    public function clientConfig(): JSONResponse
    {
        $language = $this->languageService->resolveLanguage();

        $memoryAllowed = $this->config->getAppValue(Application::APP_ID, 'enable_memories', '1') === '1';
        $memory = ['allowed' => $memoryAllowed, 'available' => false, 'configured' => false];

        if ($memoryAllowed) {
            $serviceState = $this->synaplanClient->checkMemoryService();
            $memory['available'] = $serviceState['available'];
            $memory['configured'] = $serviceState['configured'];
        }

        $info = $this->appManager->getAppInfo(Application::APP_ID);

        return new JSONResponse(array_merge([
            'success' => true,
            'version' => is_string($info['version'] ?? null) ? $info['version'] : '',
            'language' => $language,
            'languageName' => $this->languageService->getLanguageName($language),
            'useInterfaceLanguage' => $this->languageService->useInterfaceLanguage(),
            'memory' => $memory,
            'host' => parse_url($this->synaplanClient->getPublicBaseUrl(), PHP_URL_HOST) ?: '',
        ], $this->userAccounts->getLinkStatus()));
    }

    /**
     * What the Files app offers beyond its fixed actions: Translate only where
     * the connected Synaplan translates, and "Let Synaplan read this folder"
     * only where the administrator lets people name folders and Synaplan
     * reads folders; the bird and the Synaplan tab only where Synaplan tells
     * what it made of each file, and "Keep it up to date" only where Synaplan
     * hears when files change; watching where the administrator allows it and
     * Synaplan watches.
     *
     * @NoAdminRequired
     */
    public function features(): JSONResponse
    {
        $watches = WatchPolicy::watchingAllowedIn($this->config) && $this->links->offersWatches();

        return new JSONResponse([
            'translation' => $this->synaplanClient->offersTranslation(),
            'read_folders' => $this->readScope->ceiling() !== ReadScope::MODE_WATCHED && $this->links->readsFolders(),
            'file_state' => $this->cloudState->available(),
            'follows_changes' => $this->cloudState->followsChanges(),
            'watches' => $watches,
            'watch_shared' => $watches && WatchPolicy::sharedAllowedIn($this->config),
        ]);
    }

    /**
     * Summarize a file.
     *
     * @NoAdminRequired
     *
     * @param int    $fileId         ownCloud.online file ID
     * @param string $summaryType    bullet-points|abstractive|extractive
     * @param string $length         short|medium|long
     * @param string $outputLanguage ISO language code (en, de, fr, es, it)
     */
    public function summarize(
        int $fileId,
        string $summaryType = 'bullet-points',
        string $length = 'medium',
        string $outputLanguage = '',
    ): JSONResponse {
        try {
            if (trim($outputLanguage) === '') {
                $outputLanguage = $this->languageService->resolveLanguage();
            }

            $text = $this->extractFileText($fileId);

            $result = $this->synaplanClient->summarize($text, $summaryType, $length, $outputLanguage);

            return new JSONResponse([
                'success' => true,
                'summary' => $result['summary'] ?? '',
                'metadata' => $result['metadata'] ?? null,
            ]);
        } catch (\Exception $e) {
            return $this->failed($e, 'Summarization failed');
        }
    }

    /**
     * Translate a file.
     *
     * @NoAdminRequired
     *
     * @param int    $fileId         ownCloud.online file ID
     * @param string $targetLanguage ISO language code (en, de, fr, es, it)
     */
    public function translate(int $fileId, string $targetLanguage = ''): JSONResponse
    {
        try {
            if (trim($targetLanguage) === '') {
                $targetLanguage = $this->languageService->resolveLanguage();
            }

            $text = $this->extractFileText($fileId);

            $result = $this->synaplanClient->translate($text, $targetLanguage);

            return new JSONResponse([
                'success' => true,
                'translation' => $result['summary'] ?? '',
                'metadata' => $this->translationMetadata($result, $targetLanguage),
            ]);
        } catch (\Exception $e) {
            return $this->failed($e, 'Translation failed');
        }
    }

    /**
     * What the browser may show about a translation. Synaplan's own details
     * describe a summary (type, focus, compression) and stay on the server.
     *
     * @param array<string, mixed> $result
     * @return array<string, string>
     */
    private function translationMetadata(array $result, string $targetLanguage): array
    {
        $metadata = is_array($result['metadata'] ?? null) ? $result['metadata'] : [];
        $out = ['target_language' => $targetLanguage];
        foreach (['model', 'provider'] as $key) {
            if (is_string($metadata[$key] ?? null) && $metadata[$key] !== '') {
                $out[$key] = $metadata[$key];
            }
        }

        return $out;
    }

    /**
     * Upload an ownCloud.online file to the Synaplan knowledge base.
     *
     * @NoAdminRequired
     *
     * @param int       $fileId     ownCloud.online file ID
     * @param string    $groupKey   Target group key for vectorization
     * @param bool|null $keepSynced whether Synaplan takes each new version; yes unless the person said no
     */
    public function uploadToKnowledge(int $fileId, string $groupKey = 'DEFAULT', ?bool $keepSynced = null): JSONResponse
    {
        $body = file_get_contents('php://input');
        if ($body !== false && $body !== '') {
            $decoded = json_decode($body, true);
            if (is_array($decoded) && isset($decoded['groupKey'])) {
                $groupKey = trim((string) $decoded['groupKey']);
            }
            if ($keepSynced === null && is_array($decoded) && array_key_exists('keepSynced', $decoded)) {
                $keepSynced = (bool) $decoded['keepSynced'];
            }
        }
        // Where Synaplan hears of no change, a kept file would read as
        // updating for good; without the field Synaplan keeps what it had.
        $keepSynced = $this->cloudState->followsChanges() ? ($keepSynced ?? true) : null;

        if ($groupKey === '') {
            $groupKey = 'DEFAULT';
        }

        try {
            $node = $this->getUserFile($fileId);
            if (!($node instanceof File)) {
                throw new NotFoundException('File not found');
            }

            if ($node->getSize() > self::MAX_UPLOAD_FILE_SIZE) {
                throw FailureException::fileTooLarge(self::MAX_UPLOAD_FILE_SIZE);
            }

            $userFolder = $this->rootFolder->getUserFolder($this->requireUid());
            $content = $node->getContent();
            $filename = $node->getName();
            $originalName = ltrim((string) ($userFolder->getRelativePath($node->getPath()) ?? $filename), '/');

            // Key the knowledge entry on the ownCloud.online file id + etag and always
            // overwrite, so re-adding (or adding a changed) file updates its
            // entry in place instead of creating a duplicate.
            $result = $this->synaplanClient->uploadFile(
                $filename,
                $content,
                $groupKey,
                'vectorize',
                $originalName,
                (string) $fileId,
                $node->getEtag(),
                true,
                null,
                $keepSynced,
            );

            if (($result['success'] ?? false) !== true) {
                // Synaplan's reason (storage quota, rate limit) tells the person what to do.
                $reason = (string) ($result['errors'][0]['error'] ?? ($result['error'] ?? ''));
                throw $reason !== ''
                    ? FailureException::refusedBySynaplan($reason)
                    : FailureException::invalidSynaplanAnswer();
            }
            $fileInfo = $result['files'][0] ?? null;

            return new JSONResponse([
                'success' => true,
                'filename' => $filename,
                'groupKey' => $groupKey,
                'chunksCreated' => $fileInfo['chunks_created'] ?? 0,
                'vectorized' => $fileInfo['vectorized'] ?? false,
                'extractedTextLength' => $fileInfo['extracted_text_length'] ?? 0,
                'overwritten' => $fileInfo['overwritten'] ?? false,
            ]);
        } catch (\Exception $e) {
            return $this->failed($e, 'Knowledge upload failed');
        }
    }

    /**
     * Knowledge-base status for an ownCloud.online file.
     *
     * @NoAdminRequired
     *
     * @param int $fileId ownCloud.online file ID
     */
    public function knowledgeStatus(int $fileId): JSONResponse
    {
        try {
            $node = $this->getUserFile($fileId);
            if (!($node instanceof File)) {
                throw new NotFoundException('File not found');
            }

            $status = $this->synaplanClient->knowledgeStatus($fileId, $node->getEtag());

            return new JSONResponse([
                'success' => true,
                'inKnowledge' => $status['in_knowledge'],
                'stale' => $status['stale'],
            ]);
        } catch (NotFoundException $e) {
            return $this->failed($e, 'Knowledge status failed');
        } catch (\Exception $e) {
            // Status is best-effort — the modal still works if it fails.
            $this->logger->info('Knowledge status check failed: {message}', [
                'app' => Application::APP_ID,
                'message' => $e->getMessage(),
            ]);

            return new JSONResponse([
                'success' => true,
                'inKnowledge' => false,
                'stale' => false,
                'unavailable' => true,
            ]);
        }
    }

    /**
     * Remove an ownCloud.online file from the Synaplan knowledge base.
     *
     * @NoAdminRequired
     *
     * @param int $fileId ownCloud.online file ID
     */
    public function removeFromKnowledge(int $fileId): JSONResponse
    {
        try {
            $node = $this->getUserFile($fileId);
            $etag = ($node instanceof File) ? $node->getEtag() : null;

            $status = $this->synaplanClient->knowledgeStatus($fileId, $etag);
            $synaplanFileId = $status['synaplan_file_id'];

            if (!$status['in_knowledge'] || $synaplanFileId === null) {
                return new JSONResponse(['success' => true, 'removed' => false]);
            }

            $this->synaplanClient->deleteKnowledgeFile($synaplanFileId);

            return new JSONResponse(['success' => true, 'removed' => true]);
        } catch (\Exception $e) {
            return $this->failed($e, 'Remove from knowledge failed');
        }
    }

    /**
     * Get available file groups from Synaplan.
     *
     * @NoAdminRequired
     */
    public function getGroups(): JSONResponse
    {
        try {
            $result = $this->synaplanClient->getFileGroups();

            return new JSONResponse(['success' => true, 'groups' => $result['groups'] ?? []]);
        } catch (\Exception $e) {
            return $this->failed($e, 'Failed to fetch groups', ['groups' => []]);
        }
    }

    /**
     * Get available AI models from Synaplan.
     *
     * @NoAdminRequired
     */
    public function getModels(): JSONResponse
    {
        try {
            $result = $this->synaplanClient->getModels();
            $models = $result['models'] ?? [];

            $extractModels = static function (array $group): array {
                $out = [];
                foreach ($group as $model) {
                    $out[] = [
                        'id' => $model['id'] ?? 0,
                        'name' => $model['name'] ?? 'Unknown',
                        'service' => $model['service'] ?? '',
                        'providerId' => $model['providerId'] ?? '',
                    ];
                }

                return $out;
            };

            $chatModels = isset($models['CHAT']) && is_array($models['CHAT'])
                ? $extractModels($models['CHAT']) : [];
            $imageModels = isset($models['TEXT2PIC']) && is_array($models['TEXT2PIC'])
                ? $extractModels($models['TEXT2PIC']) : [];
            $videoModels = isset($models['TEXT2VID']) && is_array($models['TEXT2VID'])
                ? $extractModels($models['TEXT2VID']) : [];

            return new JSONResponse([
                'success' => true,
                'models' => $chatModels,
                'imageModels' => $imageModels,
                'videoModels' => $videoModels,
                'capabilities' => [
                    'image' => count($imageModels) > 0,
                    'video' => count($videoModels) > 0,
                ],
            ]);
        } catch (\Exception $e) {
            return $this->failed($e, 'Failed to fetch models', [
                'models' => [],
                'imageModels' => [],
                'videoModels' => [],
                'capabilities' => ['image' => false, 'video' => false],
            ]);
        }
    }

    /**
     * Extract text content from an ownCloud.online file.
     *
     * For text-based files, reads content directly. For binary documents
     * (PDF, DOCX, etc.), uploads to Synaplan which uses Tika to extract text,
     * then returns the extracted text.
     *
     * @throws NotFoundException if file doesn't exist or user has no access
     * @throws FailureException when the file is too large, empty, of an unsupported type or has no text
     */
    private function extractFileText(int $fileId): string
    {
        $node = $this->getUserFile($fileId);
        if (!($node instanceof File)) {
            throw new NotFoundException('File not found');
        }

        $mimeType = $node->getMimeType();

        if ($this->isTextMimeType($mimeType)) {
            if ($node->getSize() > self::MAX_TEXT_FILE_SIZE) {
                throw FailureException::fileTooLarge(self::MAX_TEXT_FILE_SIZE);
            }

            $content = $node->getContent();
            if ($content === '') {
                throw FailureException::fileEmpty();
            }

            return $content;
        }

        // Binary documents: upload to Synaplan for Tika extraction, then fetch text
        if ($this->isBinaryDocumentType($mimeType)) {
            if ($node->getSize() > self::MAX_UPLOAD_FILE_SIZE) {
                throw FailureException::fileTooLarge(self::MAX_UPLOAD_FILE_SIZE);
            }

            $userFolder = $this->rootFolder->getUserFolder($this->requireUid());
            $uploadResult = $this->synaplanClient->uploadFile(
                $node->getName(),
                $node->getContent(),
                '_owncloud_temp',
                'extract',
                ltrim((string) ($userFolder->getRelativePath($node->getPath()) ?? $node->getName()), '/'),
            );

            $fileInfo = $uploadResult['files'][0] ?? null;
            if ($fileInfo === null) {
                // The server's own reason (storage quota, rate limit, …) lets
                // the person act on it.
                $detail = (string) ($uploadResult['errors'][0]['error'] ?? ($uploadResult['error'] ?? ''));
                throw $detail !== ''
                    ? FailureException::refusedBySynaplan($detail)
                    : FailureException::invalidSynaplanAnswer();
            }

            $synaplanFileId = $fileInfo['id'] ?? null;
            $extractedLength = $fileInfo['extracted_text_length'] ?? 0;

            if ($synaplanFileId === null || $extractedLength === 0) {
                throw FailureException::noText($node->getName());
            }

            $contentResult = $this->synaplanClient->getFileContent((int) $synaplanFileId);
            $extractedText = $contentResult['extracted_text'] ?? '';

            if ($extractedText === '') {
                throw FailureException::noText($node->getName());
            }

            return $extractedText;
        }

        throw FailureException::unsupportedType($mimeType);
    }

    /**
     * Resolve a file node owned by the current user by its id.
     *
     * ownCloud.online's Folder API returns an array from getById(); with
     * $first=true we take the single match.
     *
     * @throws NotFoundException when not authenticated or the id is unknown
     */
    private function getUserFile(int $fileId): ?Node
    {
        $userFolder = $this->rootFolder->getUserFolder($this->requireUid());
        $nodes = $userFolder->getById($fileId, true);

        return $nodes[0] ?? null;
    }

    /**
     * @throws NotFoundException when there is no logged-in user
     */
    private function requireUid(): string
    {
        $user = $this->userSession->getUser();
        if ($user === null) {
            throw new NotFoundException('Not authenticated');
        }

        return $user->getUID();
    }

    private function isTextMimeType(string $mimeType): bool
    {
        foreach (self::TEXT_MIME_TYPES as $supported) {
            if (str_starts_with($mimeType, $supported)) {
                return true;
            }
        }

        return false;
    }

    private function isBinaryDocumentType(string $mimeType): bool
    {
        foreach (self::BINARY_MIME_TYPES as $supported) {
            if (str_starts_with($mimeType, $supported)) {
                return true;
            }
        }

        return false;
    }
}
