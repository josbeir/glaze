<?php
declare(strict_types=1);

namespace Glaze\Config;

use Glaze\Utility\Normalization;
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
     * @param string $staticDir Relative static asset directory.
     * @param string $outputDir Relative output directory.
     * @param string $cacheDir Relative cache directory.
     * @param string $pageTemplate Sugar template used for full-page rendering.
     * @param array<string, array<string, string>> $imagePresets Configured Glide image presets.
     * @param array<string, string> $imageOptions Configured Glide server options.
     * @param array<string, array{paths: array<array{match: string, createPattern: string|null}>, defaults: array<string, mixed>}> $contentTypes Configured content type rules.
     * @param array<string> $taxonomies Enabled taxonomy keys.
     * @param \Glaze\Config\SiteConfig|null $site Site-wide project configuration.
     * @param bool $includeDrafts Whether draft pages should be included.
     */
    public function __construct(
        public readonly string $projectRoot,
        public readonly string $contentDir = 'content',
        public readonly string $templateDir = 'templates',
        public readonly string $staticDir = 'static',
        public readonly string $outputDir = 'public',
        public readonly string $cacheDir = 'tmp' . DIRECTORY_SEPARATOR . 'cache',
        public readonly string $pageTemplate = 'page',
        public readonly array $imagePresets = [],
        public readonly array $imageOptions = [],
        public readonly array $contentTypes = [],
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
        $normalizedRoot = Normalization::path($projectRoot);
        $projectConfiguration = self::readProjectConfiguration($normalizedRoot);
        $imageConfiguration = $projectConfiguration['images'] ?? null;
        $imagePresets = [];
        $imageOptions = [];
        if (is_array($imageConfiguration)) {
            $imagePresets = self::normalizeImagePresets($imageConfiguration['presets'] ?? null);
            $imageOptions = self::normalizeImageOptions($imageConfiguration);
        }

        return new self(
            projectRoot: $normalizedRoot,
            pageTemplate: self::normalizePageTemplate($projectConfiguration['pageTemplate'] ?? null),
            imagePresets: $imagePresets,
            imageOptions: $imageOptions,
            contentTypes: self::normalizeContentTypes($projectConfiguration['contentTypes'] ?? null),
            taxonomies: self::normalizeTaxonomies($projectConfiguration['taxonomies'] ?? null),
            site: SiteConfig::fromProjectConfig($projectConfiguration['site'] ?? null),
            includeDrafts: $includeDrafts,
        );
    }

    /**
     * Normalize configured content type rules.
     *
     * @param mixed $contentTypes Raw configured content type map.
     * @return array<string, array{paths: array<array{match: string, createPattern: string|null}>, defaults: array<string, mixed>}>
     */
    protected static function normalizeContentTypes(mixed $contentTypes): array
    {
        if ($contentTypes === null) {
            return [];
        }

        if (!is_array($contentTypes)) {
            throw new RuntimeException('Invalid project configuration: "contentTypes" must be a key/value mapping.');
        }

        $normalized = [];
        foreach ($contentTypes as $typeName => $typeConfiguration) {
            if (!is_string($typeName)) {
                throw new RuntimeException('Invalid project configuration: content type names must be strings.');
            }

            $normalizedTypeName = strtolower(trim($typeName));
            if ($normalizedTypeName === '') {
                throw new RuntimeException('Invalid project configuration: content type name cannot be empty.');
            }

            if (isset($normalized[$normalizedTypeName])) {
                throw new RuntimeException(sprintf(
                    'Invalid project configuration: duplicate content type "%s".',
                    $normalizedTypeName,
                ));
            }

            if (!is_array($typeConfiguration)) {
                throw new RuntimeException(sprintf(
                    'Invalid project configuration: content type "%s" must be a key/value mapping.',
                    $normalizedTypeName,
                ));
            }

            $normalizedPaths = self::normalizeContentTypePaths(
                $normalizedTypeName,
                $typeConfiguration['paths'] ?? null,
            );
            $defaults = self::normalizeContentTypeDefaults($normalizedTypeName, $typeConfiguration['defaults'] ?? null);

            $normalized[$normalizedTypeName] = [
                'paths' => $normalizedPaths,
                'defaults' => $defaults,
            ];
        }

        return $normalized;
    }

    /**
     * Normalize content type paths.
     *
     * @param string $contentTypeName Content type name.
     * @param mixed $paths Raw paths configuration.
     * @return array<array{match: string, createPattern: string|null}>
     */
    protected static function normalizeContentTypePaths(
        string $contentTypeName,
        mixed $paths,
    ): array {
        if ($paths === null) {
            return [];
        }

        if (!is_array($paths)) {
            throw new RuntimeException(sprintf(
                'Invalid project configuration: content type "%s" paths must be a list.',
                $contentTypeName,
            ));
        }

        $normalized = [];
        foreach ($paths as $entry) {
            if (is_string($entry)) {
                $match = trim(str_replace('\\', '/', $entry), '/');
                if ($match === '') {
                    continue;
                }

                $normalized[$match] = [
                    'match' => $match,
                    'createPattern' => null,
                ];

                continue;
            }

            if (!is_array($entry)) {
                continue;
            }

            $match = Normalization::optionalString($entry['match'] ?? null);
            if ($match === null) {
                continue;
            }

            $normalizedMatch = trim(str_replace('\\', '/', $match), '/');
            if ($normalizedMatch === '') {
                continue;
            }

            $createPattern = Normalization::optionalString($entry['createPattern'] ?? null);

            $normalized[$normalizedMatch] = [
                'match' => $normalizedMatch,
                'createPattern' => $createPattern !== null
                    ? trim(str_replace('\\', '/', $createPattern), '/')
                    : null,
            ];
        }

        return array_values($normalized);
    }

    /**
     * Normalize content type metadata defaults.
     *
     * @param string $contentTypeName Content type name.
     * @param mixed $defaults Raw defaults map.
     * @return array<string, mixed>
     */
    protected static function normalizeContentTypeDefaults(string $contentTypeName, mixed $defaults): array
    {
        if ($defaults === null) {
            return [];
        }

        if (!is_array($defaults)) {
            throw new RuntimeException(sprintf(
                'Invalid project configuration: content type "%s" defaults must be a key/value mapping.',
                $contentTypeName,
            ));
        }

        $normalized = [];
        foreach ($defaults as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $normalizedKey = strtolower(trim($key));
            if ($normalizedKey === '') {
                continue;
            }

            $normalized[$normalizedKey] = $value;
        }

        return $normalized;
    }

    /**
     * Normalize configured image presets.
     *
     * @param mixed $imagePresets Raw configured image preset map.
     * @return array<string, array<string, string>>
     */
    protected static function normalizeImagePresets(mixed $imagePresets): array
    {
        if (!is_array($imagePresets)) {
            return [];
        }

        $normalized = [];
        foreach ($imagePresets as $presetName => $presetValues) {
            if (!is_string($presetName)) {
                continue;
            }

            $normalizedPresetName = trim($presetName);
            if ($normalizedPresetName === '') {
                continue;
            }

            if (!is_array($presetValues)) {
                continue;
            }

            $normalizedValues = Normalization::stringMap($presetValues);

            if ($normalizedValues === []) {
                continue;
            }

            $normalized[$normalizedPresetName] = $normalizedValues;
        }

        return $normalized;
    }

    /**
     * Normalize optional Glide image server options.
     *
     * @param array<mixed> $imageConfiguration Raw `images` configuration map.
     * @return array<string, string>
     */
    protected static function normalizeImageOptions(array $imageConfiguration): array
    {
        $allowedOptionKeys = ['driver'];
        $normalized = [];

        foreach ($allowedOptionKeys as $optionKey) {
            $value = Normalization::optionalScalarString($imageConfiguration[$optionKey] ?? null);
            if ($value === null) {
                continue;
            }

            $normalized[$optionKey] = $value;
        }

        return $normalized;
    }

    /**
     * Normalize configured page template value.
     *
     * @param mixed $pageTemplate Raw configured template value.
     */
    protected static function normalizePageTemplate(mixed $pageTemplate): string
    {
        return Normalization::optionalString($pageTemplate) ?? 'page';
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
        $normalized = array_map(
            static fn(string $taxonomy): string => strtolower($taxonomy),
            Normalization::stringList($taxonomies),
        );

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
     * Get absolute static asset directory.
     */
    public function staticPath(): string
    {
        return $this->resolvePath($this->staticDir);
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
     * Get absolute template cache directory.
     */
    public function templateCachePath(): string
    {
        return $this->cachePath() . DIRECTORY_SEPARATOR . 'sugar';
    }

    /**
     * Get absolute Glide image cache directory.
     */
    public function glideCachePath(): string
    {
        return $this->cachePath() . DIRECTORY_SEPARATOR . 'glide';
    }

    /**
     * Resolve a relative path against the project root.
     *
     * @param string $relativePath Relative path fragment.
     */
    protected function resolvePath(string $relativePath): string
    {
        return Normalization::path(
            $this->projectRoot . DIRECTORY_SEPARATOR . ltrim($relativePath, DIRECTORY_SEPARATOR),
        );
    }
}
