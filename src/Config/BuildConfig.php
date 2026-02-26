<?php
declare(strict_types=1);

namespace Glaze\Config;

use Glaze\Utility\Normalization;
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
     * @param array{codeHighlighting: array{enabled: bool, theme: string, withGutter: bool}, headerAnchors: array{enabled: bool, symbol: string, position: string, cssClass: string, ariaLabel: string, levels: array<int>}, autolink: array{enabled: bool, allowedSchemes: array<string>}, externalLinks: array{enabled: bool, internalHosts: array<string>, target: string, rel: string, nofollow: bool}, smartQuotes: array{enabled: bool, locale: string|null, openDouble: string|null, closeDouble: string|null, openSingle: string|null, closeSingle: string|null}, mentions: array{enabled: bool, urlTemplate: string, cssClass: string}, semanticSpan: array{enabled: bool}, defaultAttributes: array{enabled: bool, defaults: array<string, array<string, string>>}} $djot Djot rendering configuration.
     * @param array<string, array{paths: array<array{match: string, createPattern: string|null}>, defaults: array<string, mixed>}> $contentTypes Configured content type rules.
     * @param array<string> $taxonomies Enabled taxonomy keys.
     * @param array<string, mixed> $templateVite Sugar Vite extension configuration.
     * @param \Glaze\Config\SiteConfig|null $site Site-wide project configuration.
     * @param bool $includeDrafts Whether draft pages should be included.
     * @param string $extensionsDir Relative directory scanned for auto-discoverable extension classes.
     */
    public function __construct(
        public readonly string $projectRoot,
        public readonly string $contentDir = 'content',
        public readonly string $templateDir = 'templates',
        public readonly string $staticDir = 'static',
        public readonly string $outputDir = 'public',
        public readonly string $cacheDir = 'tmp' . DIRECTORY_SEPARATOR . 'cache',
        public readonly string $pageTemplate = 'page',
        public readonly string $extensionsDir = 'extensions',
        public readonly array $imagePresets = [],
        public readonly array $imageOptions = [],
        public readonly array $djot = [
            'codeHighlighting' => [
                'enabled' => true,
                'theme' => 'nord',
                'withGutter' => false,
            ],
            'headerAnchors' => [
                'enabled' => false,
                'symbol' => '#',
                'position' => 'after',
                'cssClass' => 'header-anchor',
                'ariaLabel' => 'Anchor link',
                'levels' => [1, 2, 3, 4, 5, 6],
            ],
            'autolink' => [
                'enabled' => false,
                'allowedSchemes' => ['https', 'http', 'mailto'],
            ],
            'externalLinks' => [
                'enabled' => false,
                'internalHosts' => [],
                'target' => '_blank',
                'rel' => 'noopener noreferrer',
                'nofollow' => false,
            ],
            'smartQuotes' => [
                'enabled' => false,
                'locale' => null,
                'openDouble' => null,
                'closeDouble' => null,
                'openSingle' => null,
                'closeSingle' => null,
            ],
            'mentions' => [
                'enabled' => false,
                'urlTemplate' => '/users/view/{username}',
                'cssClass' => 'mention',
            ],
            'semanticSpan' => [
                'enabled' => false,
            ],
            'defaultAttributes' => [
                'enabled' => false,
                'defaults' => [],
            ],
        ],
        public readonly array $contentTypes = [],
        public readonly array $taxonomies = ['tags'],
        public readonly array $templateVite = [
            'buildEnabled' => false,
            'devEnabled' => false,
            'assetBaseUrl' => '/assets/',
            'manifestPath' => 'public/assets/.vite/manifest.json',
            'devServerUrl' => 'http://127.0.0.1:5173',
            'injectClient' => true,
            'defaultEntry' => null,
        ],
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
     * @param \Glaze\Config\ProjectConfigurationReader|null $projectConfigurationReader Project configuration reader service.
     */
    public static function fromProjectRoot(
        string $projectRoot,
        bool $includeDrafts = false,
        ?ProjectConfigurationReader $projectConfigurationReader = null,
    ): self {
        $normalizedRoot = Normalization::path($projectRoot);
        $projectConfiguration = self::readProjectConfiguration(
            projectRoot: $normalizedRoot,
            projectConfigurationReader: $projectConfigurationReader,
        );
        $imageConfiguration = $projectConfiguration['images'] ?? null;
        $imagePresets = [];
        $imageOptions = [];
        $djotConfiguration = self::normalizeDjotConfiguration(
            projectConfiguration: $projectConfiguration,
            legacyCodeHighlightingConfiguration: $projectConfiguration['codeHighlighting'] ?? null,
        );
        if (is_array($imageConfiguration)) {
            $imagePresets = self::normalizeImagePresets($imageConfiguration['presets'] ?? null);
            $imageOptions = self::normalizeImageOptions($imageConfiguration);
        }

        return new self(
            projectRoot: $normalizedRoot,
            pageTemplate: self::normalizePageTemplate($projectConfiguration['pageTemplate'] ?? null),
            extensionsDir: self::normalizeExtensionsDir($projectConfiguration['extensionsDir'] ?? null),
            imagePresets: $imagePresets,
            imageOptions: $imageOptions,
            djot: $djotConfiguration,
            contentTypes: self::normalizeContentTypes($projectConfiguration['contentTypes'] ?? null),
            taxonomies: self::normalizeTaxonomies($projectConfiguration['taxonomies'] ?? null),
            templateVite: self::normalizeTemplateViteConfiguration($projectConfiguration, $normalizedRoot),
            site: SiteConfig::fromProjectConfig($projectConfiguration['site'] ?? null),
            includeDrafts: $includeDrafts,
        );
    }

    /**
     * Normalise the extensions directory name from project configuration.
     *
     * Returns the default `'extensions'` when the value is absent or blank.
     *
     * @param mixed $value Raw config value.
     */
    protected static function normalizeExtensionsDir(mixed $value): string
    {
        $normalized = Normalization::optionalString($value);

        return $normalized !== null && $normalized !== '' ? $normalized : 'extensions';
    }

    /**
     * Normalize Sugar Vite extension settings from build and devServer configuration.
     *
     * @param array<string, mixed> $projectConfiguration Raw project configuration map.
     * @param string $projectRoot Absolute project root path.
     * @return array<string, mixed>
     */
    protected static function normalizeTemplateViteConfiguration(
        array $projectConfiguration,
        string $projectRoot,
    ): array {
        $buildConfiguration = $projectConfiguration['build'] ?? null;
        $buildViteConfiguration = is_array($buildConfiguration)
            ? ($buildConfiguration['vite'] ?? null)
            : null;
        if (!is_array($buildViteConfiguration)) {
            $buildViteConfiguration = [];
        }

        $devServerConfiguration = $projectConfiguration['devServer'] ?? null;
        $devServerViteConfiguration = is_array($devServerConfiguration)
            ? ($devServerConfiguration['vite'] ?? null)
            : null;
        if (!is_array($devServerViteConfiguration)) {
            $devServerViteConfiguration = [];
        }

        $buildEnabled = is_bool($buildViteConfiguration['enabled'] ?? null) && $buildViteConfiguration['enabled'];
        $devEnabled = is_bool($devServerViteConfiguration['enabled'] ?? null) && $devServerViteConfiguration['enabled'];

        $assetBaseUrl = Normalization::optionalString($buildViteConfiguration['assetBaseUrl'] ?? null)
            ?? '/assets/';

        $manifestPath = Normalization::optionalString($buildViteConfiguration['manifestPath'] ?? null)
            ?? 'public/assets/.vite/manifest.json';
        if (!str_starts_with($manifestPath, DIRECTORY_SEPARATOR)) {
            $manifestPath = $projectRoot . DIRECTORY_SEPARATOR . ltrim($manifestPath, DIRECTORY_SEPARATOR);
        }

        /** @var array<string, mixed> $devServerViteConfiguration */
        $devServerUrl = Normalization::optionalString($devServerViteConfiguration['url'] ?? null)
            ?? self::buildViteServerUrl($devServerViteConfiguration);

        $injectClient = is_bool($devServerViteConfiguration['injectClient'] ?? null)
            ? $devServerViteConfiguration['injectClient']
            : true;

        $defaultEntry = Normalization::optionalString($devServerViteConfiguration['defaultEntry'] ?? null)
            ?? Normalization::optionalString($buildViteConfiguration['defaultEntry'] ?? null);

        return [
            'buildEnabled' => $buildEnabled,
            'devEnabled' => $devEnabled,
            'assetBaseUrl' => rtrim($assetBaseUrl, '/') . '/',
            'manifestPath' => Normalization::path($manifestPath),
            'devServerUrl' => $devServerUrl,
            'injectClient' => $injectClient,
            'defaultEntry' => $defaultEntry,
        ];
    }

    /**
     * Build Vite dev server URL from host/port fallback settings.
     *
     * @param array<string, mixed> $devServerViteConfiguration Vite dev server configuration.
     */
    protected static function buildViteServerUrl(array $devServerViteConfiguration): string
    {
        $host = Normalization::optionalString($devServerViteConfiguration['host'] ?? null) ?? '127.0.0.1';
        $portValue = $devServerViteConfiguration['port'] ?? null;
        $port = is_int($portValue)
            ? $portValue
            : (is_string($portValue) && ctype_digit($portValue) ? (int)$portValue : 5173);
        if ($port < 1 || $port > 65535) {
            $port = 5173;
        }

        return sprintf('http://%s:%d', $host, $port);
    }

    /**
     * Normalize Djot settings from grouped and legacy configuration values.
     *
     * @param array<string, mixed> $projectConfiguration Raw project configuration map.
     * @param mixed $legacyCodeHighlightingConfiguration Legacy root-level code highlighting map.
     * @return array{codeHighlighting: array{enabled: bool, theme: string, withGutter: bool}, headerAnchors: array{enabled: bool, symbol: string, position: string, cssClass: string, ariaLabel: string, levels: array<int>}, autolink: array{enabled: bool, allowedSchemes: array<string>}, externalLinks: array{enabled: bool, internalHosts: array<string>, target: string, rel: string, nofollow: bool}, smartQuotes: array{enabled: bool, locale: string|null, openDouble: string|null, closeDouble: string|null, openSingle: string|null, closeSingle: string|null}, mentions: array{enabled: bool, urlTemplate: string, cssClass: string}, semanticSpan: array{enabled: bool}, defaultAttributes: array{enabled: bool, defaults: array<string, array<string, string>>}}
     */
    protected static function normalizeDjotConfiguration(
        array $projectConfiguration,
        mixed $legacyCodeHighlightingConfiguration,
    ): array {
        $djotConfiguration = $projectConfiguration['djot'] ?? null;
        $rawCodeHighlighting = $legacyCodeHighlightingConfiguration;
        $rawHeaderAnchors = null;
        $rawAutolink = null;
        $rawExternalLinks = null;
        $rawSmartQuotes = null;
        $rawMentions = null;
        $rawSemanticSpan = null;
        $rawDefaultAttributes = null;

        if (is_array($djotConfiguration)) {
            $rawCodeHighlighting = $djotConfiguration['codeHighlighting'] ?? $rawCodeHighlighting;
            $rawHeaderAnchors = $djotConfiguration['headerAnchors'] ?? null;
            $rawAutolink = $djotConfiguration['autolink'] ?? null;
            $rawExternalLinks = $djotConfiguration['externalLinks'] ?? null;
            $rawSmartQuotes = $djotConfiguration['smartQuotes'] ?? null;
            $rawMentions = $djotConfiguration['mentions'] ?? null;
            $rawSemanticSpan = $djotConfiguration['semanticSpan'] ?? null;
            $rawDefaultAttributes = $djotConfiguration['defaultAttributes'] ?? null;
        }

        return [
            'codeHighlighting' => self::normalizeCodeHighlighting($rawCodeHighlighting),
            'headerAnchors' => self::normalizeHeaderAnchors($rawHeaderAnchors),
            'autolink' => self::normalizeAutolink($rawAutolink),
            'externalLinks' => self::normalizeExternalLinks($rawExternalLinks),
            'smartQuotes' => self::normalizeSmartQuotes($rawSmartQuotes),
            'mentions' => self::normalizeMentions($rawMentions),
            'semanticSpan' => self::normalizeSemanticSpan($rawSemanticSpan),
            'defaultAttributes' => self::normalizeDefaultAttributes($rawDefaultAttributes),
        ];
    }

    /**
     * Normalize Djot code highlighting settings.
     *
     * @param mixed $codeHighlighting Raw configured highlighting map.
     * @return array{enabled: bool, theme: string, withGutter: bool}
     */
    protected static function normalizeCodeHighlighting(mixed $codeHighlighting): array
    {
        $defaults = [
            'enabled' => true,
            'theme' => 'nord',
            'withGutter' => false,
        ];

        if ($codeHighlighting === null) {
            return $defaults;
        }

        if (!is_array($codeHighlighting)) {
            return $defaults;
        }

        $enabled = $codeHighlighting['enabled'] ?? $defaults['enabled'];
        $withGutter = $codeHighlighting['withGutter'] ?? $defaults['withGutter'];
        $theme = Normalization::optionalString($codeHighlighting['theme'] ?? null) ?? $defaults['theme'];

        return [
            'enabled' => is_bool($enabled) ? $enabled : $defaults['enabled'],
            'theme' => strtolower($theme),
            'withGutter' => is_bool($withGutter) ? $withGutter : $defaults['withGutter'],
        ];
    }

    /**
     * Normalize heading anchor settings for Djot headings.
     *
     * @param mixed $headerAnchors Raw heading anchor configuration map.
     * @return array{enabled: bool, symbol: string, position: string, cssClass: string, ariaLabel: string, levels: array<int>}
     */
    protected static function normalizeHeaderAnchors(mixed $headerAnchors): array
    {
        $defaults = [
            'enabled' => false,
            'symbol' => '#',
            'position' => 'after',
            'cssClass' => 'header-anchor',
            'ariaLabel' => 'Anchor link',
            'levels' => [1, 2, 3, 4, 5, 6],
        ];

        if (!is_array($headerAnchors)) {
            return $defaults;
        }

        $enabled = $headerAnchors['enabled'] ?? $defaults['enabled'];
        $symbol = Normalization::optionalString($headerAnchors['symbol'] ?? null) ?? $defaults['symbol'];
        $positionValue = strtolower(
            Normalization::optionalString($headerAnchors['position'] ?? null) ?? $defaults['position'],
        );
        $cssClass = Normalization::optionalString($headerAnchors['cssClass'] ?? null) ?? $defaults['cssClass'];
        $ariaLabel = Normalization::optionalString($headerAnchors['ariaLabel'] ?? null) ?? $defaults['ariaLabel'];

        $levels = array_values(array_unique(array_filter(
            array_map(
                static function (mixed $level): int {
                    if (is_int($level)) {
                        return $level;
                    }

                    if (is_string($level) && ctype_digit($level)) {
                        return (int)$level;
                    }

                    return 0;
                },
                is_array($headerAnchors['levels'] ?? null) ? $headerAnchors['levels'] : $defaults['levels'],
            ),
            static fn(int $level): bool => $level >= 1 && $level <= 6,
        )));

        if ($levels === []) {
            $levels = $defaults['levels'];
        }

        return [
            'enabled' => is_bool($enabled) ? $enabled : $defaults['enabled'],
            'symbol' => $symbol,
            'position' => in_array($positionValue, ['before', 'after'], true) ? $positionValue : $defaults['position'],
            'cssClass' => $cssClass,
            'ariaLabel' => $ariaLabel,
            'levels' => $levels,
        ];
    }

    /**
     * Normalize autolink settings for Djot rendering.
     *
     * When enabled, bare URLs in the document are automatically converted to anchor links.
     * The `allowedSchemes` list controls which URL schemes are linkified.
     *
     * Example:
     * ```yaml
     * djot:
     *   autolink:
     *     enabled: true
     *     allowedSchemes: [https, http, mailto]
     * ```
     *
     * @param mixed $autolink Raw autolink configuration map.
     * @return array{enabled: bool, allowedSchemes: array<string>}
     */
    protected static function normalizeAutolink(mixed $autolink): array
    {
        $defaults = [
            'enabled' => false,
            'allowedSchemes' => ['https', 'http', 'mailto'],
        ];

        if (!is_array($autolink)) {
            return $defaults;
        }

        $enabled = $autolink['enabled'] ?? $defaults['enabled'];
        $rawSchemes = $autolink['allowedSchemes'] ?? null;
        $allowedSchemes = is_array($rawSchemes)
            ? array_values(array_filter(array_map(
                static fn(mixed $s): string => is_scalar($s) ? strtolower(trim((string)$s)) : '',
                $rawSchemes,
            ), static fn(string $s): bool => $s !== ''))
            : $defaults['allowedSchemes'];

        return [
            'enabled' => is_bool($enabled) ? $enabled : $defaults['enabled'],
            'allowedSchemes' => $allowedSchemes !== [] ? $allowedSchemes : $defaults['allowedSchemes'],
        ];
    }

    /**
     * Normalize external links settings for Djot rendering.
     *
     * When enabled, external links are rendered with configurable attributes such as
     * `target`, `rel`, and optionally `nofollow`. Hosts matching `internalHosts` are
     * excluded from this treatment.
     *
     * Example:
     * ```yaml
     * djot:
     *   externalLinks:
     *     enabled: true
     *     internalHosts: [example.com]
     *     target: _blank
     *     rel: noopener noreferrer
     *     nofollow: false
     * ```
     *
     * @param mixed $externalLinks Raw external links configuration map.
     * @return array{enabled: bool, internalHosts: array<string>, target: string, rel: string, nofollow: bool}
     */
    protected static function normalizeExternalLinks(mixed $externalLinks): array
    {
        $defaults = [
            'enabled' => false,
            'internalHosts' => [],
            'target' => '_blank',
            'rel' => 'noopener noreferrer',
            'nofollow' => false,
        ];

        if (!is_array($externalLinks)) {
            return $defaults;
        }

        $enabled = $externalLinks['enabled'] ?? $defaults['enabled'];
        $rawHosts = $externalLinks['internalHosts'] ?? null;
        $internalHosts = is_array($rawHosts)
            ? array_values(array_filter(array_map(
                static fn(mixed $h): string => is_scalar($h) ? strtolower(trim((string)$h)) : '',
                $rawHosts,
            ), static fn(string $h): bool => $h !== ''))
            : $defaults['internalHosts'];

        $target = Normalization::optionalString($externalLinks['target'] ?? null) ?? $defaults['target'];
        $rel = Normalization::optionalString($externalLinks['rel'] ?? null) ?? $defaults['rel'];
        $nofollow = $externalLinks['nofollow'] ?? $defaults['nofollow'];

        return [
            'enabled' => is_bool($enabled) ? $enabled : $defaults['enabled'],
            'internalHosts' => $internalHosts,
            'target' => $target,
            'rel' => $rel,
            'nofollow' => is_bool($nofollow) ? $nofollow : $defaults['nofollow'],
        ];
    }

    /**
     * Normalize smart quotes settings for Djot rendering.
     *
     * When enabled, straight quotes in the document are replaced with typographic curly quotes.
     * Locale-based defaults are applied when no explicit quote characters are given.
     *
     * Example:
     * ```yaml
     * djot:
     *   smartQuotes:
     *     enabled: true
     *     locale: en
     * ```
     *
     * @param mixed $smartQuotes Raw smart quotes configuration map.
     * @return array{enabled: bool, locale: string|null, openDouble: string|null, closeDouble: string|null, openSingle: string|null, closeSingle: string|null}
     */
    protected static function normalizeSmartQuotes(mixed $smartQuotes): array
    {
        $defaults = [
            'enabled' => false,
            'locale' => null,
            'openDouble' => null,
            'closeDouble' => null,
            'openSingle' => null,
            'closeSingle' => null,
        ];

        if (!is_array($smartQuotes)) {
            return $defaults;
        }

        $enabled = $smartQuotes['enabled'] ?? $defaults['enabled'];

        return [
            'enabled' => is_bool($enabled) ? $enabled : $defaults['enabled'],
            'locale' => Normalization::optionalString($smartQuotes['locale'] ?? null),
            'openDouble' => Normalization::optionalString($smartQuotes['openDouble'] ?? null),
            'closeDouble' => Normalization::optionalString($smartQuotes['closeDouble'] ?? null),
            'openSingle' => Normalization::optionalString($smartQuotes['openSingle'] ?? null),
            'closeSingle' => Normalization::optionalString($smartQuotes['closeSingle'] ?? null),
        ];
    }

    /**
     * Normalize mentions settings for Djot rendering.
     *
     * When enabled, `@username` references in the document are rendered as links using
     * the configured URL template and CSS class.
     *
     * Example:
     * ```yaml
     * djot:
     *   mentions:
     *     enabled: true
     *     urlTemplate: /users/view/{username}
     *     cssClass: mention
     * ```
     *
     * @param mixed $mentions Raw mentions configuration map.
     * @return array{enabled: bool, urlTemplate: string, cssClass: string}
     */
    protected static function normalizeMentions(mixed $mentions): array
    {
        $defaults = [
            'enabled' => false,
            'urlTemplate' => '/users/view/{username}',
            'cssClass' => 'mention',
        ];

        if (!is_array($mentions)) {
            return $defaults;
        }

        $enabled = $mentions['enabled'] ?? $defaults['enabled'];
        $urlTemplate = Normalization::optionalString($mentions['urlTemplate'] ?? null) ?? $defaults['urlTemplate'];
        $cssClass = Normalization::optionalString($mentions['cssClass'] ?? null) ?? $defaults['cssClass'];

        return [
            'enabled' => is_bool($enabled) ? $enabled : $defaults['enabled'],
            'urlTemplate' => $urlTemplate,
            'cssClass' => $cssClass,
        ];
    }

    /**
     * Normalize semantic span settings for Djot rendering.
     *
     * When enabled, spans carrying a class of `mark`, `ins`, or `del` are promoted to
     * their semantic HTML equivalents.
     *
     * Example:
     * ```yaml
     * djot:
     *   semanticSpan:
     *     enabled: true
     * ```
     *
     * @param mixed $semanticSpan Raw semantic span configuration map.
     * @return array{enabled: bool}
     */
    protected static function normalizeSemanticSpan(mixed $semanticSpan): array
    {
        $defaults = ['enabled' => false];

        if (!is_array($semanticSpan)) {
            return $defaults;
        }

        $enabled = $semanticSpan['enabled'] ?? $defaults['enabled'];

        return [
            'enabled' => is_bool($enabled) ? $enabled : $defaults['enabled'],
        ];
    }

    /**
     * Normalize default attributes settings for Djot rendering.
     *
     * When enabled, the provided `defaults` map is applied to matching Djot elements at
     * render time, e.g. automatically adding a CSS class to every heading.
     *
     * Example:
     * ```yaml
     * djot:
     *   defaultAttributes:
     *     enabled: true
     *     defaults:
     *       heading:
     *         class: heading
     * ```
     *
     * @param mixed $defaultAttributes Raw default attributes configuration map.
     * @return array{enabled: bool, defaults: array<string, array<string, string>>}
     */
    protected static function normalizeDefaultAttributes(mixed $defaultAttributes): array
    {
        $defaults = [
            'enabled' => false,
            'defaults' => [],
        ];

        if (!is_array($defaultAttributes)) {
            return $defaults;
        }

        $enabled = $defaultAttributes['enabled'] ?? $defaults['enabled'];
        $rawDefaults = $defaultAttributes['defaults'] ?? null;
        $normalizedDefaults = [];

        if (is_array($rawDefaults)) {
            foreach ($rawDefaults as $elementType => $attributes) {
                if (!is_string($elementType)) {
                    continue;
                }

                if (!is_array($attributes)) {
                    continue;
                }

                $normalizedType = strtolower(trim($elementType));
                if ($normalizedType === '') {
                    continue;
                }

                $normalizedDefaults[$normalizedType] = Normalization::stringMap($attributes);
            }
        }

        return [
            'enabled' => is_bool($enabled) ? $enabled : $defaults['enabled'],
            'defaults' => $normalizedDefaults,
        ];
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
     * @param \Glaze\Config\ProjectConfigurationReader|null $projectConfigurationReader Project configuration reader service.
     * @return array<string, mixed>
     */
    protected static function readProjectConfiguration(
        string $projectRoot,
        ?ProjectConfigurationReader $projectConfigurationReader = null,
    ): array {
        $reader = $projectConfigurationReader ?? new ProjectConfigurationReader();

        return $reader->read($projectRoot);
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
