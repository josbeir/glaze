<?php
declare(strict_types=1);

namespace Glaze\Build;

use FilesystemIterator;
use Glaze\Config\BuildConfig;
use Glaze\Utility\Hash;
use JsonException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Persistent build state used to drive incremental page rendering.
 *
 * The manifest stores a global dependency fingerprint plus per-page source
 * body hashes. The global hash captures inputs that can affect many pages
 * (configuration, templates, extensions, and page metadata). Per-page body
 * hashes allow selective re-rendering when only Djot body content changes.
 */
final readonly class BuildManifest
{
    /**
     * Constructor.
     *
     * @param string $globalHash Global dependency fingerprint.
     * @param array<string, string> $pageBodyHashes Body hashes keyed by output-relative path.
     * @param array<string, string> $contentAssetSignatures Content asset signatures keyed by output-relative path.
     * @param array<string, string> $staticAssetSignatures Static asset signatures keyed by output-relative path.
     */
    public function __construct(
        public string $globalHash,
        public array $pageBodyHashes,
        public array $contentAssetSignatures,
        public array $staticAssetSignatures,
    ) {
    }

    /**
     * Build a manifest snapshot from the current build inputs.
     *
     * @param \Glaze\Config\BuildConfig $config Build configuration.
     * @param array<\Glaze\Content\ContentPage> $pages Current discovered page list.
     */
    public static function fromBuild(BuildConfig $config, array $pages): self
    {
        $pageBodyHashes = [];
        foreach ($pages as $page) {
            if ($page->virtual) {
                continue;
            }

            $pageBodyHashes[$page->outputRelativePath] = Hash::make($page->source);
        }

        ksort($pageBodyHashes);
        $contentAssetSignatures = self::assetSignatures(
            rootPath: $config->contentPath(),
            includeFile: static fn(SplFileInfo $file): bool => strtolower($file->getExtension()) !== 'dj',
        );
        $staticAssetSignatures = self::assetSignatures(
            rootPath: $config->staticPath(),
            includeFile: static fn(SplFileInfo $file): bool => true,
        );

        return new self(
            globalHash: Hash::make(self::buildGlobalSignature($config, $pages)),
            pageBodyHashes: $pageBodyHashes,
            contentAssetSignatures: $contentAssetSignatures,
            staticAssetSignatures: $staticAssetSignatures,
        );
    }

    /**
     * Load a manifest from disk.
     *
     * Returns null when the file does not exist or cannot be decoded.
     *
     * @param string $path Absolute manifest file path.
     */
    public static function load(string $path): ?self
    {
        if (!is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            return null;
        }

        try {
            /** @var mixed $decoded */
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        $globalHash = $decoded['globalHash'] ?? null;
        $rawPageBodyHashes = $decoded['pageBodyHashes'] ?? null;
        $rawContentAssetSignatures = $decoded['contentAssetSignatures'] ?? [];
        $rawStaticAssetSignatures = $decoded['staticAssetSignatures'] ?? [];
        if (
            !is_string($globalHash)
            || !is_array($rawPageBodyHashes)
            || !is_array($rawContentAssetSignatures)
            || !is_array($rawStaticAssetSignatures)
        ) {
            return null;
        }

        $pageBodyHashes = [];
        foreach ($rawPageBodyHashes as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            if (!is_string($value)) {
                continue;
            }

            $pageBodyHashes[$key] = $value;
        }

        $contentAssetSignatures = self::normalizeStringMap($rawContentAssetSignatures);
        $staticAssetSignatures = self::normalizeStringMap($rawStaticAssetSignatures);

        ksort($pageBodyHashes);

        return new self(
            $globalHash,
            $pageBodyHashes,
            $contentAssetSignatures,
            $staticAssetSignatures,
        );
    }

    /**
     * Persist the manifest to disk.
     *
     * @param string $path Absolute manifest file path.
     */
    public function save(string $path): void
    {
        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create build cache directory "%s".', $directory));
        }

        try {
            $encoded = json_encode([
                'globalHash' => $this->globalHash,
                'pageBodyHashes' => $this->pageBodyHashes,
                'contentAssetSignatures' => $this->contentAssetSignatures,
                'staticAssetSignatures' => $this->staticAssetSignatures,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            throw new RuntimeException('Unable to encode build manifest.', 0, $jsonException);
        }

        if (!is_string($encoded) || file_put_contents($path, $encoded) === false) {
            throw new RuntimeException(sprintf('Unable to write build manifest "%s".', $path));
        }
    }

    /**
     * Return true when a full rebuild is required.
     *
     * @param \Glaze\Build\BuildManifest|null $previous Previously persisted manifest.
     */
    public function requiresFullBuild(?self $previous): bool
    {
        if (!$previous instanceof self) {
            return true;
        }

        return $previous->globalHash !== $this->globalHash;
    }

    /**
     * Return output paths whose body hash changed compared to the previous manifest.
     *
     * @param \Glaze\Build\BuildManifest $previous Previously persisted manifest.
     * @return array<string, bool> Changed output-relative paths indexed as a set.
     */
    public function changedPageOutputPaths(self $previous): array
    {
        $changed = [];
        foreach ($this->pageBodyHashes as $outputRelativePath => $hash) {
            if (($previous->pageBodyHashes[$outputRelativePath] ?? null) === $hash) {
                continue;
            }

            $changed[$outputRelativePath] = true;
        }

        return $changed;
    }

    /**
     * Return tracked page output-relative paths.
     *
     * @return array<string> Output-relative file paths.
     */
    public function pageOutputPaths(): array
    {
        return array_keys($this->pageBodyHashes);
    }

    /**
     * Return output-relative page paths removed since the previous manifest.
     *
     * @param \Glaze\Build\BuildManifest $previous Previously persisted manifest.
     * @return array<string> Removed output-relative file paths.
     */
    public function orphanedPageOutputPaths(self $previous): array
    {
        return array_values(array_diff(
            $previous->pageOutputPaths(),
            $this->pageOutputPaths(),
        ));
    }

    /**
     * Return tracked content asset output-relative paths.
     *
     * @return array<string> Output-relative file paths.
     */
    public function contentAssetOutputPaths(): array
    {
        return array_keys($this->contentAssetSignatures);
    }

    /**
     * Return tracked static asset output-relative paths.
     *
     * @return array<string> Output-relative file paths.
     */
    public function staticAssetOutputPaths(): array
    {
        return array_keys($this->staticAssetSignatures);
    }

    /**
     * Return output-relative content asset paths removed since the previous manifest.
     *
     * @param \Glaze\Build\BuildManifest $previous Previously persisted manifest.
     * @return array<string> Removed output-relative file paths.
     */
    public function orphanedContentAssetOutputPaths(self $previous): array
    {
        return array_values(array_diff(
            $previous->contentAssetOutputPaths(),
            $this->contentAssetOutputPaths(),
        ));
    }

    /**
     * Return output-relative static asset paths removed since the previous manifest.
     *
     * @param \Glaze\Build\BuildManifest $previous Previously persisted manifest.
     * @return array<string> Removed output-relative file paths.
     */
    public function orphanedStaticAssetOutputPaths(self $previous): array
    {
        return array_values(array_diff(
            $previous->staticAssetOutputPaths(),
            $this->staticAssetOutputPaths(),
        ));
    }

    /**
     * Build a stable global dependency signature string.
     *
     * @param \Glaze\Config\BuildConfig $config Build configuration.
     * @param array<\Glaze\Content\ContentPage> $pages Current discovered page list.
     */
    protected static function buildGlobalSignature(BuildConfig $config, array $pages): string
    {
        $parts = [
            'glaze=' . self::fileSignature($config->projectRoot . DIRECTORY_SEPARATOR . 'glaze.neon'),
            'templates=' . self::directorySignature($config->templatePath()),
            'extensions=' . self::directorySignature(
                $config->projectRoot . DIRECTORY_SEPARATOR . $config->extensionsDir,
            ),
            'includeDrafts=' . ($config->includeDrafts ? '1' : '0'),
            'defaultTemplate=' . $config->pageTemplate,
            'pages=' . self::pagesMetadataSignature($pages),
        ];

        return implode("\n", $parts);
    }

    /**
     * Build a stable file content signature.
     *
     * @param string $path Absolute file path.
     */
    protected static function fileSignature(string $path): string
    {
        if (!is_file($path)) {
            return 'missing';
        }

        $contents = file_get_contents($path);
        if (!is_string($contents)) {
            return 'unreadable';
        }

        return Hash::make($contents);
    }

    /**
     * Build a stable directory mtime signature.
     *
     * @param string $path Absolute directory path.
     */
    protected static function directorySignature(string $path): string
    {
        if (!is_dir($path)) {
            return 'missing';
        }

        $entries = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo) {
                continue;
            }

            if (!$file->isFile()) {
                continue;
            }

            $absolutePath = str_replace('\\', '/', $file->getPathname());
            $basePath = rtrim(str_replace('\\', '/', $path), '/');
            $relativePath = ltrim(substr($absolutePath, strlen($basePath)), '/');
            $entries[] = $relativePath . ':' . $file->getMTime();
        }

        sort($entries);

        return Hash::make(implode("\n", $entries));
    }

    /**
     * Build a stable page-metadata signature excluding Djot body content.
     *
     * @param array<\Glaze\Content\ContentPage> $pages Current discovered page list.
     */
    protected static function pagesMetadataSignature(array $pages): string
    {
        $entries = [];
        foreach ($pages as $page) {
            $entries[$page->outputRelativePath] = [
                'slug' => $page->slug,
                'urlPath' => $page->urlPath,
                'outputRelativePath' => $page->outputRelativePath,
                'title' => $page->title,
                'draft' => $page->draft,
                'type' => $page->type,
                'virtual' => $page->virtual,
                'meta' => $page->meta,
                'taxonomies' => $page->taxonomies,
            ];
        }

        ksort($entries);

        try {
            $encoded = json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException $jsonException) {
            throw new RuntimeException('Unable to encode page metadata signature.', 0, $jsonException);
        }

        if (!is_string($encoded)) {
            return Hash::make('empty');
        }

        return Hash::make($encoded);
    }

    /**
     * Normalize a mixed hash map into a sorted string-to-string map.
     *
     * @param array<mixed> $values Raw decoded map.
     * @return array<string, string>
     */
    protected static function normalizeStringMap(array $values): array
    {
        $normalized = [];
        foreach ($values as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            if (!is_string($value)) {
                continue;
            }

            $normalized[$key] = $value;
        }

        ksort($normalized);

        return $normalized;
    }

    /**
     * Build source-asset signatures keyed by output-relative path.
     *
     * Uses lightweight `size:mtime` signatures for performance. These
     * signatures are used for incremental asset inventory and orphan detection.
     *
     * @param string $rootPath Absolute source root path.
     * @param callable(\SplFileInfo):bool $includeFile File inclusion callback.
     * @return array<string, string> Output-relative path to signature map.
     */
    protected static function assetSignatures(string $rootPath, callable $includeFile): array
    {
        if (!is_dir($rootPath)) {
            return [];
        }

        $signatures = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($rootPath, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo) {
                continue;
            }

            if (!$file->isFile()) {
                continue;
            }

            if (!$includeFile($file)) {
                continue;
            }

            $absolutePath = str_replace('\\', '/', $file->getPathname());
            $basePath = rtrim(str_replace('\\', '/', $rootPath), '/');
            $relativePath = ltrim(substr($absolutePath, strlen($basePath)), '/');
            $signatures[$relativePath] = $file->getSize() . ':' . $file->getMTime();
        }

        ksort($signatures);

        return $signatures;
    }
}
