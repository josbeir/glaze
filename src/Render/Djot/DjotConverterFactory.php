<?php
declare(strict_types=1);

namespace Glaze\Render\Djot;

use Djot\DjotConverter;
use Djot\Extension\AutolinkExtension;
use Djot\Extension\DefaultAttributesExtension;
use Djot\Extension\ExternalLinksExtension;
use Djot\Extension\HeadingPermalinksExtension;
use Djot\Extension\MentionsExtension;
use Djot\Extension\SemanticSpanExtension;
use Djot\Extension\SmartQuotesExtension;
use Glaze\Config\BuildConfig;
use Glaze\Config\CachePath;
use Glaze\Config\DjotOptions;
use Glaze\Config\SiteConfig;
use Glaze\Support\FileCache;
use Glaze\Support\ResourcePathRewriter;
use Glaze\Utility\Hash;
use Phiki\Theme\Theme;
use Psr\SimpleCache\CacheInterface;

/**
 * Factory for fully configured Djot converter instances.
 *
 * A single {@see PhikiCodeBlockRenderer} instance is reused across all pages that share
 * the same highlight configuration. This ensures Phiki grammar and theme JSON files are
 * parsed at most once per build rather than once per page.
 */
class DjotConverterFactory
{
    /**
     * Cached Phiki code block renderer instance, shared across pages with identical highlight config.
     */
    private ?PhikiCodeBlockRenderer $cachedRenderer = null;

    /**
     * Signature of the configuration that produced {@see $cachedRenderer}.
     */
    private string $cachedRendererSignature = '';

    /**
     * Constructor.
     *
     * @param \Glaze\Support\ResourcePathRewriter $resourcePathRewriter Shared resource path rewriter.
     * @param \Glaze\Render\Djot\PhikiThemeResolver $phikiThemeResolver Resolver for Phiki single- and multi-theme options.
     */
    public function __construct(
        protected ResourcePathRewriter $resourcePathRewriter,
        protected PhikiThemeResolver $phikiThemeResolver,
    ) {
    }

    /**
     * Create a converter instance with configured extensions.
     *
     * @param \Glaze\Config\DjotOptions $djot Djot renderer options.
     * @param \Glaze\Config\SiteConfig|null $siteConfig Site configuration used for internal path rewriting.
     * @param string|null $relativePagePath Relative source page path for content-relative links.
     * @param \Glaze\Config\BuildConfig|null $config Optional active build configuration.
     */
    public function create(
        DjotOptions $djot,
        ?SiteConfig $siteConfig = null,
        ?string $relativePagePath = null,
        ?BuildConfig $config = null,
    ): DjotConverter {
        $converter = new DjotConverter();
        $converter->addExtension(new InternalDjotLinkExtension(
            resourcePathRewriter: $this->resourcePathRewriter,
            siteConfig: $siteConfig,
            relativePagePath: $relativePagePath,
        ));

        $phikiCodeBlockRenderer = null;
        if ($djot->codeHighlightingEnabled) {
            $phikiCodeBlockRenderer = $this->resolveRenderer($djot, $config);
        }

        foreach ($this->buildOptionalExtensionRegistrations($djot, $phikiCodeBlockRenderer) as $registration) {
            if (!$registration['enabled']) {
                continue;
            }

            $converter->addExtension($registration['extension']);
        }

        $converter->addExtension(new CodeGroupExtension($phikiCodeBlockRenderer));

        return $converter;
    }

    /**
     * Return a cached or newly created renderer for the given Djot options.
     *
     * The renderer is shared across all calls with an identical theme and gutter
     * configuration, so the underlying Phiki grammar and theme caches persist
     * across every page rendered in a single build.
     *
     * @param \Glaze\Config\DjotOptions $djot Djot renderer options.
     * @param \Glaze\Config\BuildConfig|null $config Optional active build configuration.
     */
    protected function resolveRenderer(DjotOptions $djot, ?BuildConfig $config = null): PhikiCodeBlockRenderer
    {
        $theme = $this->phikiThemeResolver->resolve($djot);
        $cachePath = $config instanceof BuildConfig ? $config->cachePath(CachePath::PhikiHtml) : null;
        $signature = $this->buildRendererSignature($theme, $djot->codeHighlightingWithGutter, $cachePath);

        if (!$this->cachedRenderer instanceof PhikiCodeBlockRenderer || $this->cachedRendererSignature !== $signature) {
            $cache = $this->resolvePhikiHtmlCache($config);
            $this->cachedRenderer = new PhikiCodeBlockRenderer(
                theme: $theme,
                withGutter: $djot->codeHighlightingWithGutter,
                cache: $cache,
            );
            $this->cachedRendererSignature = $signature;
        }

        return $this->cachedRenderer;
    }

    /**
     * Build a cache-key signature string for a given theme and gutter setting.
     *
     * @param \Phiki\Theme\Theme|array<string, string>|string $theme Resolved Phiki theme.
     * @param bool $withGutter Whether line-number gutters are enabled.
     * @param string|null $cachePath Optional cache path used for persistent Phiki HTML cache.
     */
    protected function buildRendererSignature(
        Theme|array|string $theme,
        bool $withGutter,
        ?string $cachePath = null,
    ): string {
        if ($theme instanceof Theme) {
            $themeKey = $theme->value;
        } elseif (is_array($theme)) {
            ksort($theme);
            $themeKey = implode(',', array_map(
                static fn(string $k, string $v): string => sprintf('%s:%s', $k, $v),
                array_keys($theme),
                $theme,
            ));
        } else {
            $themeKey = $theme;
        }

        return Hash::makeFromParts([$themeKey, $withGutter ? '1' : '0', $cachePath ?? 'none']);
    }

    /**
     * Resolve the Phiki HTML cache implementation.
     *
     * @param \Glaze\Config\BuildConfig|null $config Optional active build configuration.
     */
    protected function resolvePhikiHtmlCache(?BuildConfig $config = null): ?CacheInterface
    {
        if (!$config instanceof BuildConfig) {
            return null;
        }

        return new FileCache($config->cachePath(CachePath::PhikiHtml));
    }

    /**
     * Build optional extension registrations from Djot options.
     *
     * @param \Glaze\Config\DjotOptions $djot Djot renderer options.
     * @param \Glaze\Render\Djot\PhikiCodeBlockRenderer|null $phikiCodeBlockRenderer Optional Phiki code block renderer.
     * @return list<array{enabled: bool, extension: \Djot\Extension\ExtensionInterface}>
     */
    protected function buildOptionalExtensionRegistrations(
        DjotOptions $djot,
        ?PhikiCodeBlockRenderer $phikiCodeBlockRenderer,
    ): array {
        return [
            [
                'enabled' => $djot->headerAnchorsEnabled && class_exists(HeadingPermalinksExtension::class),
                'extension' => new HeadingPermalinksExtension(
                    symbol: $djot->headerAnchorsSymbol,
                    position: $djot->headerAnchorsPosition,
                    cssClass: $djot->headerAnchorsCssClass,
                    ariaLabel: $djot->headerAnchorsAriaLabel,
                    levels: $djot->headerAnchorsLevels,
                ),
            ],
            [
                'enabled' => $djot->autolinkEnabled && class_exists(AutolinkExtension::class),
                'extension' => new AutolinkExtension(
                    allowedSchemes: $djot->autolinkAllowedSchemes,
                ),
            ],
            [
                'enabled' => $djot->externalLinksEnabled && class_exists(ExternalLinksExtension::class),
                'extension' => new ExternalLinksExtension(
                    internalHosts: $djot->externalLinksInternalHosts,
                    target: $djot->externalLinksTarget,
                    rel: $djot->externalLinksRel,
                    nofollow: $djot->externalLinksNofollow,
                ),
            ],
            [
                'enabled' => $djot->smartQuotesEnabled && class_exists(SmartQuotesExtension::class),
                'extension' => new SmartQuotesExtension(
                    locale: $djot->smartQuotesLocale,
                    openDoubleQuote: $djot->smartQuotesOpenDouble,
                    closeDoubleQuote: $djot->smartQuotesCloseDouble,
                    openSingleQuote: $djot->smartQuotesOpenSingle,
                    closeSingleQuote: $djot->smartQuotesCloseSingle,
                ),
            ],
            [
                'enabled' => $djot->mentionsEnabled && class_exists(MentionsExtension::class),
                'extension' => new MentionsExtension(
                    urlTemplate: $djot->mentionsUrlTemplate,
                    cssClass: $djot->mentionsCssClass,
                ),
            ],
            [
                'enabled' => $djot->semanticSpanEnabled && class_exists(SemanticSpanExtension::class),
                'extension' => new SemanticSpanExtension(),
            ],
            [
                'enabled' => $djot->defaultAttributesEnabled && class_exists(DefaultAttributesExtension::class),
                'extension' => new DefaultAttributesExtension(
                    defaults: $djot->defaultAttributesDefaults,
                ),
            ],
            [
                'enabled' => $phikiCodeBlockRenderer instanceof PhikiCodeBlockRenderer,
                'extension' => new PhikiExtension($phikiCodeBlockRenderer ?? new PhikiCodeBlockRenderer()),
            ],
        ];
    }
}
