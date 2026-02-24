<?php
declare(strict_types=1);

namespace Glaze\Config;

/**
 * Immutable build configuration for static site generation.
 */
final class BuildConfig
{
    /**
     * Constructor.
     *
     * @param string $projectRoot Absolute project root path.
     * @param string $contentDir Relative content directory.
     * @param string $templateDir Relative template directory.
     * @param string $outputDir Relative output directory.
     * @param string $cacheDir Relative cache directory.
     * @param string $pageTemplate Sugar template used for full-page rendering.
     * @param bool $includeDrafts Whether draft pages should be included.
     */
    public function __construct(
        public readonly string $projectRoot,
        public readonly string $contentDir = 'content',
        public readonly string $templateDir = 'templates',
        public readonly string $outputDir = 'public',
        public readonly string $cacheDir = 'tmp' . DIRECTORY_SEPARATOR . 'cache',
        public readonly string $pageTemplate = 'page',
        public readonly bool $includeDrafts = false,
    ) {
    }

    /**
     * Create a configuration from a project root.
     *
     * @param string $projectRoot Project root path.
     * @param bool $includeDrafts Whether draft pages should be included.
     */
    public static function fromProjectRoot(string $projectRoot, bool $includeDrafts = false): self
    {
        return new self(
            projectRoot: self::normalizePath($projectRoot),
            includeDrafts: $includeDrafts,
        );
    }

    /**
     * Get absolute content directory.
     */
    public function contentPath(): string
    {
        return $this->resolvePath($this->contentDir);
    }

    /**
     * Get absolute template directory.
     */
    public function templatePath(): string
    {
        return $this->resolvePath($this->templateDir);
    }

    /**
     * Get absolute output directory.
     */
    public function outputPath(): string
    {
        return $this->resolvePath($this->outputDir);
    }

    /**
     * Get absolute cache directory.
     */
    public function cachePath(): string
    {
        return $this->resolvePath($this->cacheDir);
    }

    /**
     * Resolve a relative path against the project root.
     *
     * @param string $relativePath Relative path fragment.
     */
    protected function resolvePath(string $relativePath): string
    {
        return self::normalizePath(
            $this->projectRoot . DIRECTORY_SEPARATOR . ltrim($relativePath, DIRECTORY_SEPARATOR),
        );
    }

    /**
     * Normalize path separators and trailing slashes.
     *
     * @param string $path Path to normalize.
     */
    protected static function normalizePath(string $path): string
    {
        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);

        return rtrim($normalized, DIRECTORY_SEPARATOR);
    }
}
