<?php

declare(strict_types=1);

namespace Codeblick\NextcloudFal\Driver;

use Codeblick\NextcloudFal\Client\NextcloudClient;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\Capabilities;
use TYPO3\CMS\Core\Resource\Driver\AbstractHierarchicalFilesystemDriver;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;

class NextcloudDriver extends AbstractHierarchicalFilesystemDriver implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    private const DEFAULT_FOLDER = '_default_upload_';

    private ?NextcloudClient $client = null;

    private ?FrontendInterface $persistentCache = null;

    /** @var array<string, array{path: string, is_directory: bool, size: int, mtime: int, ctime: int, mimetype: string}> */
    private array $entryCache = [];

    /** @var array<string, array<string, array>> Folder identifier => list of child entries from PROPFIND */
    private array $folderListingCache = [];

    /** @var array<string, string> File identifier => local temp file path */
    private array $localFileCache = [];

    /** @var array<string, bool> Identifier => existence result for paths checked via exists() */
    private array $existsCache = [];

    public function __construct(array $configuration = [])
    {
        parent::__construct($configuration);
        $this->capabilities = new Capabilities(
            Capabilities::CAPABILITY_BROWSABLE
            | Capabilities::CAPABILITY_WRITABLE
            | Capabilities::CAPABILITY_HIERARCHICAL_IDENTIFIERS
        );
    }

    public function processConfiguration(): void
    {
    }

    public function initialize(): void
    {
        $baseUrl = trim($this->configuration['baseUrl'] ?? '');
        $username = trim($this->configuration['username'] ?? '');
        $password = $this->configuration['password'] ?? '';

        if ($baseUrl !== '' && $username !== '') {
            $this->client = new NextcloudClient($baseUrl, $username, $password, $this->logger);
        }

        try {
            $this->persistentCache = GeneralUtility::makeInstance(CacheManager::class)
                ->getCache('nextcloud_fal');
        } catch (\TYPO3\CMS\Core\Cache\Exception\NoSuchCacheException) {
            // Cache not available, continue without persistent caching
        }

        $this->ensureLocalProcessingFolder();
    }

    /**
     * Redirects processed file storage to the local default storage (UID 1) on first use.
     * This prevents TYPO3 from storing thumbnails/resized images in Nextcloud,
     * which would require downloading them from Nextcloud on every frontend/backend request.
     */
    private function ensureLocalProcessingFolder(): void
    {
        if ($this->storageUid <= 0) {
            return;
        }

        try {
            $connection = GeneralUtility::makeInstance(ConnectionPool::class)
                ->getConnectionForTable('sys_file_storage');

            $record = $connection
                ->select(['processingfolder'], 'sys_file_storage', ['uid' => $this->storageUid])
                ->fetchAssociative();

            if ($record === false) {
                return;
            }

            $processingFolder = $record['processingfolder'] ?? '';

            // Already redirected to a different storage (format "storageUid:path")
            if (str_contains($processingFolder, ':')) {
                return;
            }

            // Set processing folder to local default storage so processed images
            // are stored locally and served via direct URLs (not via Nextcloud WebDAV)
            $connection->update(
                'sys_file_storage',
                ['processingfolder' => '1:/_processed_/'],
                ['uid' => $this->storageUid]
            );

            // Remove any existing processed file records pointing to this Nextcloud storage
            // so TYPO3 regenerates them in the correct local location
            GeneralUtility::makeInstance(ConnectionPool::class)
                ->getConnectionForTable('sys_file_processedfile')
                ->delete('sys_file_processedfile', ['storage' => $this->storageUid]);
        } catch (\Throwable) {
            // Non-critical: if this fails the storage still works, just with slower processing
        }
    }

    public function mergeConfigurationCapabilities(Capabilities $capabilities): Capabilities
    {
        $this->capabilities->and($capabilities);
        return $this->capabilities;
    }

    public function sanitizeFileName(string $fileName, string $charset = ''): string
    {
        $fileName = preg_replace('/[\\x00-\\x1F\\x7F]/', '_', $fileName);
        $fileName = preg_replace('/[\\\\\\/:*?"<>|]/', '_', $fileName);
        $fileName = trim($fileName, '. ');

        if ($fileName === '') {
            $fileName = '_unnamed_';
        }

        return $fileName;
    }

    public function getRootLevelFolder(): string
    {
        return '/';
    }

    public function getDefaultFolder(): string
    {
        $identifier = '/' . self::DEFAULT_FOLDER . '/';
        if ($this->isConnected() && !$this->folderExists($identifier)) {
            $this->createFolder(self::DEFAULT_FOLDER, '/');
        }

        return $identifier;
    }

    public function getPublicUrl(string $identifier): ?string
    {
        return null;
    }

    // -----------------------------------------------------------------------
    // File operations
    // -----------------------------------------------------------------------

    public function createFile(string $fileName, string $parentFolderIdentifier): string
    {
        $parentFolderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($parentFolderIdentifier);
        $fileName = $this->sanitizeFileName($fileName);
        $identifier = $parentFolderIdentifier . $fileName;

        $this->getClient()->put($identifier, '');
        $this->flushCacheForFolder($parentFolderIdentifier);

        return $identifier;
    }

    public function addFile(string $localFilePath, string $targetFolderIdentifier, string $newFileName = '', bool $removeOriginal = true): string
    {
        $targetFolderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($targetFolderIdentifier);
        if ($newFileName === '') {
            $newFileName = PathUtility::basename($localFilePath);
        }
        $newFileName = $this->sanitizeFileName($newFileName);
        $identifier = $targetFolderIdentifier . $newFileName;

        $this->getClient()->putFile($identifier, $localFilePath);
        $this->flushCacheForFolder($targetFolderIdentifier);

        // Cache local file for subsequent hash/indexing calls to avoid re-download
        if ($removeOriginal) {
            // Copy to TYPO3 transient dir (rename fails cross-filesystem on macOS)
            $tempCopy = $this->getTemporaryPathForFile($identifier);
            if (copy($localFilePath, $tempCopy)) {
                $this->localFileCache[$identifier] = $tempCopy;
            }
            @unlink($localFilePath);
        } else {
            // Keep a reference to the original for hashing
            $tempCopy = $this->getTemporaryPathForFile($identifier);
            copy($localFilePath, $tempCopy);
            $this->localFileCache[$identifier] = $tempCopy;
        }

        return $identifier;
    }

    public function fileExists(string $fileIdentifier): bool
    {
        $fileIdentifier = $this->canonicalizeAndCheckFileIdentifier($fileIdentifier);

        if (!$this->isConnected()) {
            return false;
        }

        // Check entry cache – ensure it's actually a file, not a directory
        if (isset($this->entryCache[$fileIdentifier])) {
            return !$this->entryCache[$fileIdentifier]['is_directory'];
        }

        // Try populating from parent folder listing (1 request for all siblings)
        $entry = $this->getEntryByIdentifier($fileIdentifier);
        if ($entry === null) {
            return false;
        }

        return !$entry['is_directory'];
    }

    public function getFileContents(string $fileIdentifier): string
    {
        $fileIdentifier = $this->canonicalizeAndCheckFileIdentifier($fileIdentifier);

        return $this->getClient()->get($fileIdentifier);
    }

    public function setFileContents(string $fileIdentifier, string $contents): int
    {
        $fileIdentifier = $this->canonicalizeAndCheckFileIdentifier($fileIdentifier);
        $this->getClient()->put($fileIdentifier, $contents);
        $this->flushCacheForEntry($fileIdentifier);

        return strlen($contents);
    }

    public function replaceFile(string $fileIdentifier, string $localFilePath): bool
    {
        $fileIdentifier = $this->canonicalizeAndCheckFileIdentifier($fileIdentifier);
        $result = $this->getClient()->putFile($fileIdentifier, $localFilePath);
        $this->flushCacheForEntry($fileIdentifier);

        return $result;
    }

    public function deleteFile(string $fileIdentifier): bool
    {
        $fileIdentifier = $this->canonicalizeAndCheckFileIdentifier($fileIdentifier);
        $parentFolder = $this->getParentFolderIdentifierOfIdentifier($fileIdentifier);
        $result = $this->getClient()->delete($fileIdentifier);
        $this->flushCacheForFolder($parentFolder);

        return $result;
    }

    public function renameFile(string $fileIdentifier, string $newName): string
    {
        $fileIdentifier = $this->canonicalizeAndCheckFileIdentifier($fileIdentifier);
        $newName = $this->sanitizeFileName($newName);
        $parentFolder = $this->getParentFolderIdentifierOfIdentifier($fileIdentifier);
        $newIdentifier = $parentFolder . $newName;

        $this->getClient()->move($fileIdentifier, $newIdentifier);
        $this->flushCacheForFolder($parentFolder);

        return $newIdentifier;
    }

    public function copyFileWithinStorage(string $fileIdentifier, string $targetFolderIdentifier, string $fileName): string
    {
        $fileIdentifier = $this->canonicalizeAndCheckFileIdentifier($fileIdentifier);
        $targetFolderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($targetFolderIdentifier);
        $fileName = $this->sanitizeFileName($fileName);
        $newIdentifier = $targetFolderIdentifier . $fileName;

        $this->getClient()->copy($fileIdentifier, $newIdentifier);
        $this->flushCacheForFolder($targetFolderIdentifier);

        return $newIdentifier;
    }

    public function moveFileWithinStorage(string $fileIdentifier, string $targetFolderIdentifier, string $newFileName): string
    {
        $fileIdentifier = $this->canonicalizeAndCheckFileIdentifier($fileIdentifier);
        $targetFolderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($targetFolderIdentifier);
        $newFileName = $this->sanitizeFileName($newFileName);
        $newIdentifier = $targetFolderIdentifier . $newFileName;
        $oldParent = $this->getParentFolderIdentifierOfIdentifier($fileIdentifier);

        $this->getClient()->move($fileIdentifier, $newIdentifier);
        $this->flushCacheForFolder($oldParent);
        $this->flushCacheForFolder($targetFolderIdentifier);

        return $newIdentifier;
    }

    public function getFileForLocalProcessing(string $fileIdentifier, bool $writable = true): string
    {
        $fileIdentifier = $this->canonicalizeAndCheckFileIdentifier($fileIdentifier);

        // Return cached local copy if available (avoids re-downloading for hash etc.)
        if (isset($this->localFileCache[$fileIdentifier]) && file_exists($this->localFileCache[$fileIdentifier])) {
            if ($writable) {
                $copy = $this->getTemporaryPathForFile($fileIdentifier);
                copy($this->localFileCache[$fileIdentifier], $copy);
                return $copy;
            }
            return $this->localFileCache[$fileIdentifier];
        }

        // Stream directly to disk – avoids loading entire file into memory
        $temporaryPath = $this->getTemporaryPathForFile($fileIdentifier);
        $this->getClient()->downloadToFile($fileIdentifier, $temporaryPath);

        $this->localFileCache[$fileIdentifier] = $temporaryPath;

        return $temporaryPath;
    }

    public function dumpFileContents(string $identifier): void
    {
        $identifier = $this->canonicalizeAndCheckFileIdentifier($identifier);
        // Use local processing cache if available, otherwise stream
        $localPath = $this->getFileForLocalProcessing($identifier, false);
        readfile($localPath);
    }

    public function hash(string $fileIdentifier, string $hashAlgorithm): string
    {
        $localPath = $this->getFileForLocalProcessing($fileIdentifier, false);

        return hash_file($hashAlgorithm, $localPath);
    }

    public function getFileInfoByIdentifier(string $fileIdentifier, array $propertiesToExtract = []): array
    {
        $fileIdentifier = $this->canonicalizeAndCheckFileIdentifier($fileIdentifier);
        $entry = $this->getEntryByIdentifier($fileIdentifier);

        if ($entry === null) {
            throw new \InvalidArgumentException(
                sprintf('File "%s" does not exist.', $fileIdentifier),
                1314516809
            );
        }

        $fileInfo = [
            'identifier' => $fileIdentifier,
            'name' => PathUtility::basename($fileIdentifier),
            'storage' => $this->storageUid,
            'identifier_hash' => $this->hashIdentifier($fileIdentifier),
            'folder_hash' => $this->hashIdentifier($this->getParentFolderIdentifierOfIdentifier($fileIdentifier)),
            'mtime' => $entry['mtime'],
            'ctime' => $entry['ctime'] ?: $entry['mtime'],
            'mimetype' => $entry['mimetype'] ?: 'application/octet-stream',
            'size' => $entry['size'],
        ];

        if (!empty($propertiesToExtract)) {
            $fileInfo = array_intersect_key($fileInfo, array_flip($propertiesToExtract));
        }

        return $fileInfo;
    }

    public function fileExistsInFolder(string $fileName, string $folderIdentifier): bool
    {
        $folderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($folderIdentifier);
        $identifier = $folderIdentifier . $fileName;

        if (isset($this->entryCache[$identifier])) {
            return true;
        }

        // Try to populate cache from folder listing
        $this->ensureFolderListingCached($folderIdentifier);
        if (isset($this->entryCache[$identifier])) {
            return true;
        }

        return $this->getClient()->exists($identifier);
    }

    public function getFileInFolder(string $fileName, string $folderIdentifier): string
    {
        $folderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($folderIdentifier);

        return $folderIdentifier . $fileName;
    }

    public function getFilesInFolder(
        string $folderIdentifier,
        int $start = 0,
        int $numberOfItems = 0,
        bool $recursive = false,
        array $filenameFilterCallbacks = [],
        string $sort = '',
        bool $sortRev = false,
    ): array {
        $folderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($folderIdentifier);
        $entries = $this->getEntriesInFolder($folderIdentifier, $recursive, false, true);

        $entries = $this->applyFilters($entries, $folderIdentifier, $filenameFilterCallbacks);
        $entries = $this->sortEntries($entries, $sort, $sortRev);

        if ($start > 0 || $numberOfItems > 0) {
            $entries = array_slice($entries, $start, $numberOfItems > 0 ? $numberOfItems : null, true);
        }

        return $entries;
    }

    public function countFilesInFolder(string $folderIdentifier, bool $recursive = false, array $filenameFilterCallbacks = []): int
    {
        return count($this->getFilesInFolder($folderIdentifier, 0, 0, $recursive, $filenameFilterCallbacks));
    }

    // -----------------------------------------------------------------------
    // Folder operations
    // -----------------------------------------------------------------------

    public function createFolder(string $newFolderName, string $parentFolderIdentifier = '', bool $recursive = false): string
    {
        if ($parentFolderIdentifier === '') {
            $parentFolderIdentifier = '/';
        }
        $parentFolderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($parentFolderIdentifier);
        $newFolderName = $this->sanitizeFileName($newFolderName);
        $newIdentifier = $parentFolderIdentifier . $newFolderName . '/';

        if ($recursive) {
            $parts = GeneralUtility::trimExplode('/', $newFolderName, true);
            $current = $parentFolderIdentifier;
            foreach ($parts as $part) {
                $current .= $part . '/';
                if (!$this->folderExists($current)) {
                    $this->getClient()->mkcol($current);
                }
            }
        } else {
            $this->getClient()->mkcol($newIdentifier);
        }

        $this->flushCacheForFolder($parentFolderIdentifier);

        return $newIdentifier;
    }

    public function folderExists(string $folderIdentifier): bool
    {
        $folderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($folderIdentifier);
        if ($folderIdentifier === '/') {
            return true;
        }

        if (!$this->isConnected()) {
            return false;
        }

        // Check entry cache – ensure it's actually a directory
        if (isset($this->entryCache[$folderIdentifier])) {
            return $this->entryCache[$folderIdentifier]['is_directory'];
        }

        // Check exists cache (avoids repeated HEAD requests for the same path)
        if (isset($this->existsCache[$folderIdentifier])) {
            return $this->existsCache[$folderIdentifier];
        }

        // Try populating from parent folder listing
        $entry = $this->getEntryByIdentifier($folderIdentifier);
        if ($entry !== null) {
            return $entry['is_directory'];
        }

        // Try persistent cache for existence check
        $cacheKey = $this->buildCacheKey('exists', $folderIdentifier);
        $cached = $this->persistentCache?->get($cacheKey);
        if ($cached !== false && $cached !== null) {
            $this->existsCache[$folderIdentifier] = $cached;
            return $cached;
        }

        // Fallback: lightweight HEAD check, cache the result
        $result = $this->getClient()->exists($folderIdentifier);
        $this->existsCache[$folderIdentifier] = $result;

        $parentFolder = $this->getParentFolderIdentifierOfIdentifier($folderIdentifier);
        $folderTag = $this->buildCacheTag($parentFolder);
        $this->persistentCache?->set($cacheKey, $result, [$folderTag]);

        return $result;
    }

    public function isFolderEmpty(string $folderIdentifier): bool
    {
        $folderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($folderIdentifier);
        $this->ensureFolderListingCached($folderIdentifier);

        $children = $this->folderListingCache[$folderIdentifier] ?? [];
        return empty($children);
    }

    public function deleteFolder(string $folderIdentifier, bool $deleteRecursively = false): bool
    {
        $folderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($folderIdentifier);
        $parentFolder = $this->getParentFolderIdentifierOfIdentifier($folderIdentifier);
        // WebDAV DELETE on a collection always deletes recursively
        $result = $this->getClient()->delete($folderIdentifier);
        $this->flushCacheForFolder($parentFolder);
        $this->flushCacheForFolder($folderIdentifier);

        return $result;
    }

    public function renameFolder(string $folderIdentifier, string $newName): array
    {
        $folderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($folderIdentifier);
        $parentFolder = $this->getParentFolderIdentifierOfIdentifier($folderIdentifier);
        $newName = $this->sanitizeFileName($newName);
        $newIdentifier = $parentFolder . $newName . '/';

        $identifierMap = $this->collectIdentifierMap($folderIdentifier);

        $this->getClient()->move($folderIdentifier, $newIdentifier);
        $this->flushCacheForFolder($parentFolder);

        $mapping = [];
        foreach ($identifierMap as $oldId) {
            $newId = str_replace($folderIdentifier, $newIdentifier, $oldId);
            $mapping[$oldId] = $newId;
        }

        return $mapping;
    }

    public function copyFolderWithinStorage(string $sourceFolderIdentifier, string $targetFolderIdentifier, string $newFolderName): bool
    {
        $sourceFolderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($sourceFolderIdentifier);
        $targetFolderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($targetFolderIdentifier);
        $newFolderName = $this->sanitizeFileName($newFolderName);
        $newIdentifier = $targetFolderIdentifier . $newFolderName . '/';

        $result = $this->getClient()->copy($sourceFolderIdentifier, $newIdentifier);
        $this->flushCacheForFolder($targetFolderIdentifier);

        return $result;
    }

    public function moveFolderWithinStorage(string $sourceFolderIdentifier, string $targetFolderIdentifier, string $newFolderName): array
    {
        $sourceFolderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($sourceFolderIdentifier);
        $targetFolderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($targetFolderIdentifier);
        $newFolderName = $this->sanitizeFileName($newFolderName);
        $newIdentifier = $targetFolderIdentifier . $newFolderName . '/';

        $identifierMap = $this->collectIdentifierMap($sourceFolderIdentifier);

        $this->getClient()->move($sourceFolderIdentifier, $newIdentifier);
        $this->flushCacheForFolder($this->getParentFolderIdentifierOfIdentifier($sourceFolderIdentifier));
        $this->flushCacheForFolder($targetFolderIdentifier);

        $mapping = [];
        foreach ($identifierMap as $oldId) {
            $newId = str_replace($sourceFolderIdentifier, $newIdentifier, $oldId);
            $mapping[$oldId] = $newId;
        }

        return $mapping;
    }

    public function getFolderInfoByIdentifier(string $folderIdentifier): array
    {
        $folderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($folderIdentifier);

        $name = PathUtility::basename(rtrim($folderIdentifier, '/'));
        if ($folderIdentifier === '/') {
            $name = '';
        }

        $entry = $this->entryCache[$folderIdentifier] ?? null;

        return [
            'identifier' => $folderIdentifier,
            'name' => $name,
            'mtime' => $entry['mtime'] ?? time(),
            'ctime' => $entry['ctime'] ?? time(),
            'storage' => $this->storageUid,
        ];
    }

    public function folderExistsInFolder(string $folderName, string $folderIdentifier): bool
    {
        $folderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($folderIdentifier);
        $identifier = $folderIdentifier . $folderName . '/';

        if (isset($this->entryCache[$identifier])) {
            return true;
        }

        $this->ensureFolderListingCached($folderIdentifier);
        if (isset($this->entryCache[$identifier])) {
            return true;
        }

        return $this->getClient()->exists($identifier);
    }

    public function getFolderInFolder(string $folderName, string $folderIdentifier): string
    {
        $folderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($folderIdentifier);

        return $folderIdentifier . $folderName . '/';
    }

    public function getFoldersInFolder(
        string $folderIdentifier,
        int $start = 0,
        int $numberOfItems = 0,
        bool $recursive = false,
        array $folderNameFilterCallbacks = [],
        string $sort = '',
        bool $sortRev = false,
    ): array {
        $folderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($folderIdentifier);
        $entries = $this->getEntriesInFolder($folderIdentifier, $recursive, true, false);

        $entries = $this->applyFilters($entries, $folderIdentifier, $folderNameFilterCallbacks);
        $entries = $this->sortEntries($entries, $sort, $sortRev);

        if ($start > 0 || $numberOfItems > 0) {
            $entries = array_slice($entries, $start, $numberOfItems > 0 ? $numberOfItems : null, true);
        }

        return $entries;
    }

    public function countFoldersInFolder(string $folderIdentifier, bool $recursive = false, array $folderNameFilterCallbacks = []): int
    {
        return count($this->getFoldersInFolder($folderIdentifier, 0, 0, $recursive, $folderNameFilterCallbacks));
    }

    public function isWithin(string $folderIdentifier, string $identifier): bool
    {
        $folderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($folderIdentifier);
        if ($folderIdentifier === '/') {
            return true;
        }

        return str_starts_with($identifier, $folderIdentifier);
    }

    public function getPermissions(string $identifier): array
    {
        return [
            'r' => true,
            'w' => true,
        ];
    }

    // -----------------------------------------------------------------------
    // Internal helpers
    // -----------------------------------------------------------------------

    private function isConnected(): bool
    {
        return $this->client !== null;
    }

    private function getClient(): NextcloudClient
    {
        if ($this->client === null) {
            throw new \RuntimeException(
                'NextCloud driver is not configured. Please provide a valid base URL and username in the storage configuration.',
                1711700000
            );
        }

        return $this->client;
    }

    /**
     * Ensures the folder listing for the given folder is cached.
     * A single PROPFIND depth 1 populates both the folder listing cache
     * and the entry cache for all children.
     */
    private function ensureFolderListingCached(string $folderIdentifier): void
    {
        if (isset($this->folderListingCache[$folderIdentifier])) {
            return;
        }

        // Try persistent cache
        $cacheKey = $this->buildCacheKey('listing', $folderIdentifier);
        $cached = $this->persistentCache?->get($cacheKey);
        if ($cached !== false && $cached !== null) {
            $this->folderListingCache[$folderIdentifier] = $cached['children'];
            foreach ($cached['entries'] as $path => $entry) {
                $this->entryCache[$path] = $entry;
            }
            return;
        }

        // Fetch from Nextcloud
        $entries = $this->getClient()->propfind($folderIdentifier, 1);
        $children = [];
        $allEntries = [];

        foreach ($entries as $entry) {
            $path = $entry['path'];
            $this->entryCache[$path] = $entry;
            $allEntries[$path] = $entry;

            if ($path !== $folderIdentifier) {
                $children[$path] = $entry;
            }
        }

        $this->folderListingCache[$folderIdentifier] = $children;

        // Store in persistent cache with folder tag for targeted invalidation
        $folderTag = $this->buildCacheTag($folderIdentifier);
        $this->persistentCache?->set($cacheKey, [
            'children' => $children,
            'entries' => $allEntries,
        ], [$folderTag]);
    }

    private function getEntryByIdentifier(string $identifier): ?array
    {
        if (isset($this->entryCache[$identifier])) {
            return $this->entryCache[$identifier];
        }

        // Try populating from parent folder listing – avoids a per-file PROPFIND
        $parentFolder = $this->getParentFolderIdentifierOfIdentifier($identifier);
        $this->ensureFolderListingCached($parentFolder);

        if (isset($this->entryCache[$identifier])) {
            return $this->entryCache[$identifier];
        }

        // Try persistent cache for single entry
        $cacheKey = $this->buildCacheKey('entry', $identifier);
        $cached = $this->persistentCache?->get($cacheKey);
        if ($cached !== false && $cached !== null) {
            $this->entryCache[$identifier] = $cached;
            return $cached;
        }

        // Fallback: direct PROPFIND for this single entry
        $entries = $this->getClient()->propfind($identifier, 0);
        if (empty($entries)) {
            return null;
        }

        $entry = $entries[0];
        $this->entryCache[$identifier] = $entry;

        // Persist with parent folder tag
        $folderTag = $this->buildCacheTag($parentFolder);
        $this->persistentCache?->set($cacheKey, $entry, [$folderTag]);

        return $entry;
    }

    /**
     * @return array<string, string> Map of identifier => identifier
     */
    private function getEntriesInFolder(string $folderIdentifier, bool $recursive, bool $includeDirs, bool $includeFiles): array
    {
        $this->ensureFolderListingCached($folderIdentifier);
        $children = $this->folderListingCache[$folderIdentifier] ?? [];

        $result = [];
        foreach ($children as $path => $entry) {
            $isDir = $entry['is_directory'];

            if ($isDir && $includeDirs) {
                $result[$path] = $path;
            }

            if (!$isDir && $includeFiles) {
                $result[$path] = $path;
            }

            if ($isDir && $recursive) {
                $subEntries = $this->getEntriesInFolder($path, true, $includeDirs, $includeFiles);
                foreach ($subEntries as $subKey => $subPath) {
                    $result[$subKey] = $subPath;
                }
            }
        }

        return $result;
    }

    /**
     * @return string[]
     */
    private function collectIdentifierMap(string $folderIdentifier): array
    {
        $this->ensureFolderListingCached($folderIdentifier);
        $children = $this->folderListingCache[$folderIdentifier] ?? [];

        $identifiers = [$folderIdentifier];
        foreach ($children as $path => $entry) {
            $identifiers[] = $path;
            if ($entry['is_directory']) {
                array_push($identifiers, ...$this->collectIdentifierMap($path));
            }
        }

        return $identifiers;
    }

    /**
     * @param array<string, string> $entries
     * @param callable[] $filterCallbacks
     * @return array<string, string>
     */
    private function applyFilters(array $entries, string $folderIdentifier, array $filterCallbacks): array
    {
        if (empty($filterCallbacks)) {
            return $entries;
        }

        foreach ($entries as $identifier => $value) {
            $name = PathUtility::basename(rtrim($identifier, '/'));
            foreach ($filterCallbacks as $callback) {
                if (is_callable($callback)) {
                    $result = call_user_func($callback, $name, $identifier, $folderIdentifier, [], $this);
                    if ($result === -1) {
                        unset($entries[$identifier]);
                        break;
                    }
                    if ($result === false) {
                        throw new \RuntimeException(
                            sprintf('Could not apply filter on "%s"', $name),
                            1476046425
                        );
                    }
                }
            }
        }

        return $entries;
    }

    /**
     * @param array<string, string> $entries
     * @return array<string, string>
     */
    private function sortEntries(array $entries, string $sort, bool $sortRev): array
    {
        if ($sort === '' || empty($entries)) {
            return $entries;
        }

        $sortedEntries = [];
        foreach ($entries as $identifier => $value) {
            $name = PathUtility::basename(rtrim($identifier, '/'));
            $sortedEntries[$identifier] = match ($sort) {
                'name' => mb_strtolower($name),
                'fileext' => mb_strtolower(PathUtility::pathinfo($name, PATHINFO_EXTENSION) ?: ''),
                'size' => $this->entryCache[$identifier]['size'] ?? 0,
                'tstamp' => $this->entryCache[$identifier]['mtime'] ?? 0,
                default => mb_strtolower($name),
            };
        }

        if ($sortRev) {
            arsort($sortedEntries);
        } else {
            asort($sortedEntries);
        }

        $result = [];
        foreach (array_keys($sortedEntries) as $identifier) {
            $result[$identifier] = $entries[$identifier];
        }

        return $result;
    }

    /**
     * Flush all caches related to a folder (listing + child entries).
     */
    private function flushCacheForFolder(string $folderIdentifier): void
    {
        unset($this->folderListingCache[$folderIdentifier]);
        foreach ($this->entryCache as $key => $_) {
            if (str_starts_with($key, $folderIdentifier)) {
                unset($this->entryCache[$key]);
            }
        }
        foreach ($this->existsCache as $key => $_) {
            if (str_starts_with($key, $folderIdentifier)) {
                unset($this->existsCache[$key]);
            }
        }

        // Flush persistent cache: listing + all tagged entries for this folder
        $this->persistentCache?->remove($this->buildCacheKey('listing', $folderIdentifier));
        $this->persistentCache?->flushByTag($this->buildCacheTag($folderIdentifier));
    }

    /**
     * Flush cache for a single entry and its parent folder listing.
     */
    private function flushCacheForEntry(string $identifier): void
    {
        unset($this->entryCache[$identifier]);
        $parentFolder = $this->getParentFolderIdentifierOfIdentifier($identifier);
        unset($this->folderListingCache[$parentFolder]);

        // Flush persistent cache
        $this->persistentCache?->remove($this->buildCacheKey('entry', $identifier));
        $this->persistentCache?->remove($this->buildCacheKey('listing', $parentFolder));
    }

    private function buildCacheKey(string $prefix, string $identifier): string
    {
        return 'storage_' . $this->storageUid . '_' . $prefix . '_' . sha1($identifier);
    }

    private function buildCacheTag(string $folderIdentifier): string
    {
        return 'storage_' . $this->storageUid . '_folder_' . sha1($folderIdentifier);
    }
}
