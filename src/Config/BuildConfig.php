<?php
declare(strict_types=1);

namespace Glaze\Config;

use Nette\Neon\Exception;
use Nette\Neon\Neon;
use RuntimeException;

/**
 * Immutable build configuration for static site generation.
 */
final class BuildConfig
{
    public readonly SiteConfig $site;

    /**
     * Constructor.
     *
     * @param string $projectRoot Absolute project root path.
     * @param string $contentDir Relative content directory.
     * @param string $templateDir Relative template directory.
     * @param string $outputDir Relative output directory.
     * @param string $cacheDir Relative cache directory.
     * @param string $pageTemplate Sugar template used for full-page rendering.
     * @param array<string> $taxonomies Enabled taxonomy keys.
     * @param \Glaze\Config\SiteConfig|null $site Site-wide project configuration.
     * @param bool $includeDrafts Whether draft pages should be included.
     */
    public function __construct(
        public readonly string $projectRoot,
        public readonly string $contentDir = 'content',
        public readonly string $templateDir = 'templates',
        public readonly string $outputDir = 'public',
        public readonly string $cacheDir = 'tmp' . DIRECTORY_SEPARATOR . 'cache',
        public readonly string $pageTemplate = 'page',
        public readonly array $taxonomies = ['tags'],
        ?SiteConfig $site = null,
        public readonly bool $includeDrafts = false,
    ) {
        $this->site = $site ?? new SiteConfig();
    }

    /**
     * Create a configuration from a project root.
     *
     * @param string $projectRoot Project root path.
     * @param bool $includeDrafts Whether draft pages should be included.
     */
    public static function fromProjectRoot(string $projectRoot, bool $includeDrafts = false): self
    {
        $normalizedRoot = self::normalizePath($projectRoot);
        $projectConfiguration = self::readProjectConfiguration($normalizedRoot);

        return new self(
            projectRoot: $normalizedRoot,
            pageTemplate: self::normalizePageTemplate($projectConfiguration['pageTemplate'] ?? null),
            taxonomies: self::normalizeTaxonomies($projectConfiguration['taxonomies'] ?? null),
            site: self::normalizeSiteConfiguration($projectConfiguration['site'] ?? null),
            includeDrafts: $includeDrafts,
        );
    }

    /**
     * Normalize configured page template value.
     *
     * @param mixed $pageTemplate Raw configured template value.
     */
    protected static function normalizePageTemplate(mixed $pageTemplate): string
    {
        if (!is_string($pageTemplate)) {
            return 'page';
        }

        $normalized = trim($pageTemplate);

        return $normalized === '' ? 'page' : $normalized;
    }

    /**
     * Normalize site-wide configuration.
     *
     * @param mixed $siteConfiguration Raw site configuration value.
     */
    protected static function normalizeSiteConfiguration(mixed $siteConfiguration): SiteConfig
    {
        return SiteConfig::fromProjectConfig($siteConfiguration);
    }

    /**
     * Read optional project configuration from `glaze.neon`.
     *
     * @param string $projectRoot Absolute project root path.
     * @return array<string, mixed>
     */
    protected static function readProjectConfiguration(string $projectRoot): array
    {
        $configurationPath = $projectRoot . DIRECTORY_SEPARATOR . 'glaze.neon';
        if (!is_file($configurationPath)) {
            return [];
        }

        $contents = file_get_contents($configurationPath);
        if (!is_string($contents)) {
            throw new RuntimeException(sprintf('Unable to read project configuration "%s".', $configurationPath));
        }

        try {
            $decoded = Neon::decode($contents);
        } catch (Exception $exception) {
            throw new RuntimeException(
                sprintf('Invalid project configuration in "%s": %s', $configurationPath, $exception->getMessage()),
                0,
                $exception,
            );
        }

        if (!is_array($decoded)) {
            return [];
        }

        /** @var array<string, mixed> $decoded */
        return $decoded;
    }

    /**
     * Normalize configured taxonomy keys.
     *
     * @param mixed $taxonomies Raw configured taxonomies.
     * @return array<string>
     */
    protected static function normalizeTaxonomies(mixed $taxonomies): array
    {
        if (!is_array($taxonomies)) {
            return ['tags'];
        }

        $normalized = [];
        foreach ($taxonomies as $taxonomy) {
            if (!is_string($taxonomy)) {
                continue;
            }

            $key = strtolower(trim($taxonomy));
            if ($key === '') {
                continue;
            }

            $normalized[] = $key;
        }

        $unique = array_values(array_unique($normalized));
        if ($unique === []) {
            return ['tags'];
        }

        return $unique;
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
