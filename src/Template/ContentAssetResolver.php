<?php
declare(strict_types=1);

namespace Glaze\Template;

use Glaze\Content\ContentAsset;
use Glaze\Content\ContentPage;
use Glaze\Template\Collection\ContentAssetCollection;
use Glaze\Utility\Normalization;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * Resolves content assets from the filesystem for template consumption.
 */
final class ContentAssetResolver
{
    /**
     * Constructor.
     *
     * @param string $contentPath Absolute content root path.
     * @param string|null $basePath Optional site base path prefix for generated URLs.
     */
    public function __construct(
        protected readonly string $contentPath,
        protected readonly ?string $basePath = null,
    ) {
    }

    /**
     * Return direct assets from a content-relative directory.
     *
     * Scans only direct files in the target directory (non-recursive) and excludes
     * Djot source files.
     *
     * @param string|null $relativePath Content-relative directory path.
     */
    public function forDirectory(?string $relativePath = null): ContentAssetCollection
    {
        $absoluteDirectory = $this->resolveAbsoluteDirectory($relativePath);
        if (!is_dir($absoluteDirectory)) {
            return new ContentAssetCollection([]);
        }

        $entries = scandir($absoluteDirectory);
        if (!is_array($entries)) {
            return new ContentAssetCollection([]);
        }

        $assets = [];
        foreach ($entries as $entry) {
            if ($entry === '.') {
                continue;
            }

            if ($entry === '..') {
                continue;
            }

            $path = $absoluteDirectory . DIRECTORY_SEPARATOR . $entry;
            $file = new SplFileInfo($path);
            if (!$file->isFile()) {
                continue;
            }

            if ($this->isDjotFile($file)) {
                continue;
            }

            $assets[] = $this->buildAsset($file);
        }

        return new ContentAssetCollection($assets);
    }

    /**
     * Return assets from a content-relative directory recursively.
     *
     * Excludes Djot source files.
     *
     * @param string|null $relativePath Content-relative directory path.
     */
    public function forDirectoryRecursive(?string $relativePath = null): ContentAssetCollection
    {
        $absoluteDirectory = $this->resolveAbsoluteDirectory($relativePath);
        if (!is_dir($absoluteDirectory)) {
            return new ContentAssetCollection([]);
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($absoluteDirectory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );

        $assets = [];
        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo) {
                continue;
            }

            if (!$file->isFile()) {
                continue;
            }

            if ($this->isDjotFile($file)) {
                continue;
            }

            $assets[] = $this->buildAsset($file);
        }

        return new ContentAssetCollection($assets);
    }

    /**
     * Return assets co-located with a content page.
     *
     * For bundle pages (`index.dj` inside a directory), this scans the bundle
     * directory. For leaf pages (`about.dj`), this scans the page parent directory.
     *
     * @param \Glaze\Content\ContentPage $page Content page.
     * @param string|null $subdirectory Optional subdirectory relative to the page directory.
     */
    public function forPage(ContentPage $page, ?string $subdirectory = null): ContentAssetCollection
    {
        $relativePageDirectory = $this->relativePageDirectory($page);
        $relativeDirectory = $this->appendRelativePath($relativePageDirectory, $subdirectory);

        return $this->forDirectory($relativeDirectory);
    }

    /**
     * Build an asset value object from a source file.
     *
     * @param \SplFileInfo $file Source file.
     */
    protected function buildAsset(SplFileInfo $file): ContentAsset
    {
        $absolutePath = str_replace('\\', '/', $file->getPathname());
        $relativePath = $this->relativePathFromContentRoot($absolutePath);

        return new ContentAsset(
            relativePath: $relativePath,
            urlPath: $this->toUrlPath($relativePath),
            absolutePath: $absolutePath,
            filename: $file->getBasename(),
            extension: strtolower($file->getExtension()),
            size: $file->getSize(),
        );
    }

    /**
     * Resolve absolute directory path from optional content-relative input.
     *
     * @param string|null $relativePath Content-relative directory path.
     */
    protected function resolveAbsoluteDirectory(?string $relativePath): string
    {
        $fragment = Normalization::optionalPathFragment($relativePath ?? '');
        $normalizedRoot = rtrim(str_replace('\\', '/', $this->contentPath), '/');

        if ($fragment === null) {
            return $normalizedRoot;
        }

        $safeFragment = Normalization::normalizePathSegments($fragment);
        if ($safeFragment === '') {
            return $normalizedRoot;
        }

        return $normalizedRoot . '/' . $safeFragment;
    }

    /**
     * Return content-relative directory path for a page.
     *
     * @param \Glaze\Content\ContentPage $page Content page.
     */
    protected function relativePageDirectory(ContentPage $page): string
    {
        $relativeSourcePath = Normalization::pathFragment($page->relativePath);
        if ($relativeSourcePath === '') {
            return '';
        }

        if (strtolower(basename($relativeSourcePath)) === 'index.dj') {
            $directory = dirname($relativeSourcePath);

            return $directory === '.' ? '' : $directory;
        }

        $directory = dirname($relativeSourcePath);

        return $directory === '.' ? '' : $directory;
    }

    /**
     * Append an optional child path to a parent relative path.
     *
     * @param string $parent Parent content-relative path.
     * @param string|null $child Optional child path.
     */
    protected function appendRelativePath(string $parent, ?string $child): string
    {
        $childFragment = Normalization::optionalPathFragment($child ?? '');
        if ($childFragment === null) {
            return $parent;
        }

        if ($parent === '') {
            return Normalization::normalizePathSegments($childFragment);
        }

        return Normalization::normalizePathSegments($parent . '/' . $childFragment);
    }

    /**
     * Build a content-relative path from absolute source path.
     *
     * @param string $absolutePath Absolute source path.
     */
    protected function relativePathFromContentRoot(string $absolutePath): string
    {
        $normalizedRoot = rtrim(str_replace('\\', '/', $this->contentPath), '/');
        $normalizedPath = str_replace('\\', '/', $absolutePath);

        $suffix = ltrim(substr($normalizedPath, strlen($normalizedRoot)), '/');

        return Normalization::pathFragment($suffix);
    }

    /**
     * Build public URL path for a content-relative asset path.
     *
     * @param string $relativePath Content-relative path.
     */
    protected function toUrlPath(string $relativePath): string
    {
        $assetPath = '/' . trim($relativePath, '/');
        $baseFragment = Normalization::optionalPathFragment($this->basePath ?? '');

        if ($baseFragment === null) {
            return $assetPath;
        }

        return '/' . $baseFragment . $assetPath;
    }

    /**
     * Determine whether a file is a Djot source file.
     *
     * @param \SplFileInfo $file Source file.
     */
    protected function isDjotFile(SplFileInfo $file): bool
    {
        return strtolower($file->getExtension()) === 'dj';
    }
}
