<?php
declare(strict_types=1);

namespace Glaze\Config;

use Cake\Core\Configure;
use Glaze\Utility\Normalization;
use Glaze\Utility\Path;
use RuntimeException;

/**
 * Immutable build configuration for static site generation.
 *
 * Instantiated via {@see self::fromProjectRoot()} (CLI commands) or
 * {@see self::fromConfigure()} (DI container) which construct fully typed
 * sub-objects ({@see DjotOptions}, {@see TemplateViteOptions},
 * {@see SiteConfig}) directly — no intermediate array representations are stored.
 */
final class BuildConfig
{
    public readonly SiteConfig $site;

    /**
     * Constructor.
     *
     * @param string $projectRoot Absolute project root path.
     * @param string $contentDir Relative or absolute content directory.
     * @param string $templateDir Relative or absolute template directory.
     * @param string $staticDir Relative or absolute static asset directory.
     * @param string $outputDir Relative or absolute output directory.
     * @param string $cacheDir Relative cache directory.
     * @param string $pageTemplate Sugar template used for full-page rendering.
     * @param string $extensionsDir Relative directory scanned for auto-discoverable extension classes.
     * @param array<string, array<string, mixed>> $enabledExtensions Explicitly enabled extension definitions keyed by extension identifier.
     * @param array<string, array<string, string>> $imagePresets Configured Glide image presets.
     * @param array<string, string> $imageOptions Configured Glide server options.
     * @param array<string, array{paths: array<array{match: string, createPattern: string|null}>, defaults: array<string, mixed>}> $contentTypes Configured content type rules.
     * @param array<string> $taxonomies Enabled taxonomy keys.
     * @param \Glaze\Config\DjotOptions $djotOptions Typed Djot renderer options.
     * @param \Glaze\Config\TemplateViteOptions $templateViteOptions Typed Sugar Vite extension options.
     * @param \Glaze\Config\SiteConfig|null $site Site-wide project configuration.
     * @param bool $includeDrafts Whether draft pages should be included.
     */
    public function __construct(
        public readonly string $projectRoot,
        public readonly string $contentDir = 'content',
        public readonly string $templateDir = 'templates',
        public readonly string $staticDir = 'static',
        public readonly string $outputDir = 'public',
        public readonly string $cacheDir = 'tmp/cache',
        public readonly string $pageTemplate = 'page',
        public readonly string $extensionsDir = 'extensions',
        public readonly array $enabledExtensions = [],
        public readonly array $imagePresets = [],
        public readonly array $imageOptions = [],
        public readonly array $contentTypes = [],
        public readonly array $taxonomies = ['tags'],
        public readonly DjotOptions $djotOptions = new DjotOptions(),
        public readonly TemplateViteOptions $templateViteOptions = new TemplateViteOptions(),
        ?SiteConfig $site = null,
        public readonly bool $includeDrafts = false,
    ) {
        $this->site = $site ?? new SiteConfig();
    }

    /**
     * Create a configuration from a project root path by reading glaze.neon.
     *
     * Loads the reference configuration and merges the project's glaze.neon
     * on top via Configure. Runtime values (`projectRoot`, CLI `includeDrafts`)
     * are written to Configure so that downstream code and the container's
     * shared {@see self::fromConfigure()} factory can read them.
     *
     * @param string $projectRoot Project root path.
     * @param bool $includeDrafts Whether draft pages should be included (CLI override).
     */
    public static function fromProjectRoot(
        string $projectRoot,
        bool $includeDrafts = false,
    ): self {
        $root = Path::normalize($projectRoot);
        (new ProjectConfigurationReader())->read($root);

        Configure::write('projectRoot', $root);
        if ($includeDrafts) {
            Configure::write('build.drafts', true);
        }

        return self::fromConfigure();
    }

    /**
     * Create a configuration from the current Configure state.
     *
     * Expects the merged reference + project configuration to already be
     * loaded into Configure (via {@see ProjectConfigurationReader::read()})
     * and `projectRoot` to be set. This factory is used by the DI container
     * to provide a shared BuildConfig instance.
     *
     * @throws \RuntimeException When projectRoot is not available in Configure.
     */
    public static function fromConfigure(): self
    {
        $root = Configure::read('projectRoot');
        if (!is_string($root) || $root === '') {
            throw new RuntimeException(
                'projectRoot not available in Configure. Load project configuration first.',
            );
        }

        $config = Configure::read();
        if (!is_array($config)) {
            $config = [];
        }

        /** @var array<string, mixed> $config */

        /** @var array<string, mixed> $buildConfig */
        $buildConfig = is_array($config['build'] ?? null) ? $config['build'] : [];
        /** @var array<string, mixed> $buildVite */
        $buildVite = is_array($buildConfig['vite'] ?? null) ? $buildConfig['vite'] : [];

        /** @var array<string, mixed> $devConfig */
        $devConfig = is_array($config['devServer'] ?? null) ? $config['devServer'] : [];
        /** @var array<string, mixed> $devVite */
        $devVite = is_array($devConfig['vite'] ?? null) ? $devConfig['vite'] : [];

        /** @var array<string, mixed> $djotConfig */
        $djotConfig = is_array($config['djot'] ?? null) ? $config['djot'] : [];

        $pathConfig = self::extractPathConfig($config);

        return new self(
            projectRoot: $root,
            contentDir: $pathConfig['content'],
            templateDir: $pathConfig['template'],
            staticDir: $pathConfig['static'],
            outputDir: $pathConfig['public'],
            pageTemplate: self::extractPageTemplate($config),
            extensionsDir: $pathConfig['extensions'],
            enabledExtensions: self::extractEnabledExtensions($config),
            imagePresets: self::extractImagePresets($config),
            imageOptions: self::extractImageOptions($config),
            contentTypes: self::extractContentTypes($config),
            taxonomies: self::extractTaxonomies($config),
            djotOptions: DjotOptions::fromProjectConfig($djotConfig),
            templateViteOptions: TemplateViteOptions::fromProjectConfig($buildVite, $devVite, $root),
            site: SiteConfig::fromProjectConfig($config['site'] ?? null),
            includeDrafts: ($buildConfig['drafts'] ?? false) === true,
        );
    }

    /**
     * Get absolute content directory.
     */
    public function contentPath(): string
    {
        return Path::resolve($this->projectRoot, $this->contentDir);
    }

    /**
     * Get absolute template directory.
     */
    public function templatePath(): string
    {
        return Path::resolve($this->projectRoot, $this->templateDir);
    }

    /**
     * Get absolute static asset directory.
     */
    public function staticPath(): string
    {
        return Path::resolve($this->projectRoot, $this->staticDir);
    }

    /**
     * Get absolute output directory.
     */
    public function outputPath(): string
    {
        return Path::resolve($this->projectRoot, $this->outputDir);
    }

    /**
     * Get absolute cache directory or a specific cache subpath.
     *
     * @param \Glaze\Config\CachePath|string|null $path Optional named cache target or relative cache path.
     */
    public function cachePath(CachePath|string|null $path = null): string
    {
        $baseCachePath = Path::resolve($this->projectRoot, $this->cacheDir);
        if ($path === null) {
            return $baseCachePath;
        }

        $suffix = $path instanceof CachePath ? $path->value : trim($path);
        if ($suffix === '') {
            return $baseCachePath;
        }

        return Path::normalize($baseCachePath . '/' . ltrim($suffix, '/'));
    }

    /**
     * Extract configurable project paths from the `paths` block.
     *
     * @param array<string, mixed> $config Raw project configuration.
     * @return array{content: string, template: string, static: string, public: string, extensions: string}
     */
    private static function extractPathConfig(array $config): array
    {
        /** @var array<string, mixed> $paths */
        $paths = is_array($config['paths'] ?? null) ? $config['paths'] : [];

        return [
            'content' => self::extractConfiguredPath($paths, 'content', 'content'),
            'template' => self::extractConfiguredPath($paths, 'template', 'templates'),
            'static' => self::extractConfiguredPath($paths, 'static', 'static'),
            'public' => self::extractConfiguredPath($paths, 'public', 'public'),
            'extensions' => self::extractConfiguredPath($paths, 'extensions', 'extensions'),
        ];
    }

    /**
     * Extract and normalize a single path value with default fallback.
     *
     * @param array<string, mixed> $paths Project path map.
     * @param string $key Path key in the `paths` block.
     * @param string $default Default path value.
     */
    private static function extractConfiguredPath(array $paths, string $key, string $default): string
    {
        $value = $paths[$key] ?? null;

        return is_string($value) && trim($value) !== ''
            ? Path::normalize(trim($value))
            : $default;
    }

    /**
     * Extract the page template name from project configuration.
     *
     * @param array<string, mixed> $config Raw project configuration.
     */
    private static function extractPageTemplate(array $config): string
    {
        $value = $config['pageTemplate'] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : 'page';
    }

    /**
     * Extract explicitly enabled extension definitions from project configuration.
     *
     * Supports both list-style and map-style declarations:
     *
     * ```neon
     * extensions:
     *   - version
     *   - sitemap:
     *       includeDrafts: false
     * ```
     *
     * ```neon
     * extensions:
     *   version: []
     *   sitemap:
     *     includeDrafts: false
     * ```
     *
     * @param array<string, mixed> $config Raw project configuration.
     * @return array<string, array<string, mixed>>
     */
    private static function extractEnabledExtensions(array $config): array
    {
        $raw = $config['extensions'] ?? null;
        if (!is_array($raw)) {
            return [];
        }

        $extensions = [];
        foreach ($raw as $key => $value) {
            if (is_string($key)) {
                $name = trim($key);
                if ($name === '') {
                    continue;
                }

                $extensions[$name] = self::normalizeExtensionOptions($value);
                continue;
            }

            if (is_string($value)) {
                $name = trim($value);
                if ($name === '') {
                    continue;
                }

                $extensions[$name] = [];
                continue;
            }

            if (!is_array($value)) {
                continue;
            }

            foreach ($value as $name => $options) {
                if (!is_string($name)) {
                    continue;
                }

                $normalizedName = trim($name);
                if ($normalizedName === '') {
                    continue;
                }

                $extensions[$normalizedName] = self::normalizeExtensionOptions($options);
            }
        }

        return $extensions;
    }

    /**
     * Normalize extension option payloads to string-keyed maps.
     *
     * Non-map values are normalized to an empty option map.
     *
     * @param mixed $options Raw extension option payload.
     * @return array<string, mixed>
     */
    private static function normalizeExtensionOptions(mixed $options): array
    {
        if (!is_array($options)) {
            return [];
        }

        $normalized = [];
        foreach ($options as $optionName => $optionValue) {
            if (!is_string($optionName)) {
                continue;
            }

            $trimmed = trim($optionName);
            if ($trimmed === '') {
                continue;
            }

            $normalized[$trimmed] = $optionValue;
        }

        return $normalized;
    }

    /**
     * Extract taxonomy keys from project configuration.
     *
     * Filters to lower-cased non-empty strings and deduplicates. Falls back
     * to `['tags']` when the list is absent or resolves to empty.
     *
     * @param array<string, mixed> $config Raw project configuration.
     * @return array<string>
     */
    private static function extractTaxonomies(array $config): array
    {
        $raw = $config['taxonomies'] ?? null;
        if (!is_array($raw)) {
            return ['tags'];
        }

        $taxonomies = [];
        foreach ($raw as $item) {
            if (is_string($item) && trim($item) !== '') {
                $taxonomies[] = strtolower(trim($item));
            }
        }

        $unique = array_values(array_unique($taxonomies));

        return $unique !== [] ? $unique : ['tags'];
    }

    /**
     * Extract and validate Glide image presets from project configuration.
     *
     * Invalid preset names (non-string, empty) and presets with no scalar
     * values are silently skipped.
     *
     * @param array<string, mixed> $config Raw project configuration.
     * @return array<string, array<string, string>>
     */
    private static function extractImagePresets(array $config): array
    {
        $imageConfig = is_array($config['images'] ?? null) ? $config['images'] : [];
        $raw = $imageConfig['presets'] ?? null;
        if (!is_array($raw)) {
            return [];
        }

        $presets = [];
        foreach ($raw as $name => $values) {
            if (!is_string($name)) {
                continue;
            }

            if (trim($name) === '') {
                continue;
            }

            if (!is_array($values)) {
                continue;
            }

            $normalizedValues = Normalization::stringMap($values);
            if ($normalizedValues === []) {
                continue;
            }

            $presets[trim($name)] = $normalizedValues;
        }

        return $presets;
    }

    /**
     * Extract Glide server options (currently only `driver`) from project configuration.
     *
     * @param array<string, mixed> $config Raw project configuration.
     * @return array<string, string>
     */
    private static function extractImageOptions(array $config): array
    {
        $imageConfig = is_array($config['images'] ?? null) ? $config['images'] : [];
        $options = [];

        foreach (['driver'] as $key) {
            $value = $imageConfig[$key] ?? null;
            if (!is_scalar($value)) {
                continue;
            }

            $normalized = trim((string)$value);
            if ($normalized !== '') {
                $options[$key] = $normalized;
            }
        }

        return $options;
    }

    /**
     * Extract and validate content type definitions from project configuration.
     *
     * Throws {@see RuntimeException} for structural violations (wrong types,
     * duplicate names, empty names). Path and defaults entries with invalid
     * values are silently skipped.
     *
     * @param array<string, mixed> $config Raw project configuration.
     * @return array<string, array{paths: array<array{match: string, createPattern: string|null}>, defaults: array<string, mixed>}>
     * @throws \RuntimeException When content type configuration is structurally invalid.
     */
    private static function extractContentTypes(array $config): array
    {
        $raw = $config['contentTypes'] ?? null;
        if ($raw === null) {
            return [];
        }

        if (!is_array($raw)) {
            throw new RuntimeException('Invalid project configuration: "contentTypes" must be a key/value mapping.');
        }

        $contentTypes = [];
        foreach ($raw as $typeName => $typeConfig) {
            if (!is_string($typeName)) {
                throw new RuntimeException('Invalid project configuration: content type names must be strings.');
            }

            $normalizedName = strtolower(trim($typeName));
            if ($normalizedName === '') {
                throw new RuntimeException('Invalid project configuration: content type name cannot be empty.');
            }

            if (isset($contentTypes[$normalizedName])) {
                throw new RuntimeException(sprintf(
                    'Invalid project configuration: duplicate content type "%s".',
                    $normalizedName,
                ));
            }

            if (!is_array($typeConfig)) {
                throw new RuntimeException(sprintf(
                    'Invalid project configuration: content type "%s" must be a key/value mapping.',
                    $normalizedName,
                ));
            }

            $contentTypes[$normalizedName] = [
                'paths' => self::extractContentTypePaths($normalizedName, $typeConfig['paths'] ?? null),
                'defaults' => self::extractContentTypeDefaults($normalizedName, $typeConfig['defaults'] ?? null),
            ];
        }

        return $contentTypes;
    }

    /**
     * Extract content type path entries, normalising slash direction and stripping leading/trailing slashes.
     *
     * @param string $typeName Content type name (used for error messages).
     * @param mixed $paths Raw paths configuration.
     * @return array<array{match: string, createPattern: string|null}>
     * @throws \RuntimeException When paths is present but not an array.
     */
    private static function extractContentTypePaths(string $typeName, mixed $paths): array
    {
        if ($paths === null) {
            return [];
        }

        if (!is_array($paths)) {
            throw new RuntimeException(sprintf(
                'Invalid project configuration: content type "%s" paths must be a list.',
                $typeName,
            ));
        }

        $result = [];
        foreach ($paths as $entry) {
            if (is_string($entry)) {
                $match = trim(str_replace('\\', '/', $entry), '/');
                if ($match === '') {
                    continue;
                }

                $result[$match] = ['match' => $match, 'createPattern' => null];
                continue;
            }

            if (!is_array($entry)) {
                continue;
            }

            $matchRaw = $entry['match'] ?? null;
            if (!is_string($matchRaw)) {
                continue;
            }

            $match = trim(str_replace('\\', '/', $matchRaw), '/');
            if ($match === '') {
                continue;
            }

            $createPatternRaw = $entry['createPattern'] ?? null;
            $createPattern = is_string($createPatternRaw) && trim($createPatternRaw) !== ''
                ? trim(str_replace('\\', '/', $createPatternRaw), '/')
                : null;

            $result[$match] = ['match' => $match, 'createPattern' => $createPattern];
        }

        return array_values($result);
    }

    /**
     * Extract content type metadata defaults, lower-casing all keys.
     *
     * @param string $typeName Content type name (used for error messages).
     * @param mixed $defaults Raw defaults map.
     * @return array<string, mixed>
     * @throws \RuntimeException When defaults is present but not an array.
     */
    private static function extractContentTypeDefaults(string $typeName, mixed $defaults): array
    {
        if ($defaults === null) {
            return [];
        }

        if (!is_array($defaults)) {
            throw new RuntimeException(sprintf(
                'Invalid project configuration: content type "%s" defaults must be a key/value mapping.',
                $typeName,
            ));
        }

        $result = [];
        foreach ($defaults as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $normalizedKey = strtolower(trim($key));
            if ($normalizedKey === '') {
                continue;
            }

            $result[$normalizedKey] = $value;
        }

        return $result;
    }
}
