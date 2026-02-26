<?php
declare(strict_types=1);

namespace Glaze\Render;

use Djot\DjotConverter;
use Djot\Extension\AutolinkExtension;
use Djot\Extension\DefaultAttributesExtension;
use Djot\Extension\ExternalLinksExtension;
use Djot\Extension\HeadingPermalinksExtension;
use Djot\Extension\MentionsExtension;
use Djot\Extension\SemanticSpanExtension;
use Djot\Extension\SmartQuotesExtension;
use Glaze\Config\DjotOptions;
use Glaze\Config\SiteConfig;
use Glaze\Render\Djot\InternalDjotLinkExtension;
use Glaze\Render\Djot\PhikiCodeBlockRenderer;
use Glaze\Render\Djot\PhikiExtension;
use Glaze\Render\Djot\TocExtension;
use Glaze\Support\ResourcePathRewriter;
use Phiki\Theme\Theme;

/**
 * Converts Djot source documents to HTML.
 */
final class DjotRenderer
{
    protected ?DjotConverter $converter;

    /**
     * Constructor.
     *
     * @param \Glaze\Support\ResourcePathRewriter $resourcePathRewriter Shared resource path rewriter.
     * @param \Djot\DjotConverter|null $converter Djot converter instance.
     */
    public function __construct(
        protected ResourcePathRewriter $resourcePathRewriter,
        ?DjotConverter $converter = null,
    ) {
        $this->converter = $converter;
    }

    /**
     * Render Djot source to HTML.
     *
     * @param string $source Djot source content.
     * @param \Glaze\Config\DjotOptions|array<string, mixed> $djot Djot renderer options.
     * @param \Glaze\Config\SiteConfig|null $siteConfig Site configuration used for internal path rewriting.
     * @param string|null $relativePagePath Relative source page path for content-relative links.
     */
    public function render(
        string $source,
        array|DjotOptions $djot = [],
        ?SiteConfig $siteConfig = null,
        ?string $relativePagePath = null,
    ): string {
        $options = $this->normalizeOptions($djot);
        $converter = $this->converter ?? $this->createConverter($options, $siteConfig, $relativePagePath);

        return $converter->convert($source);
    }

    /**
     * Render Djot source to HTML and collect table-of-contents entries in a single pass.
     *
     * Behaves identically to `render()` but additionally registers a `TocExtension`
     * so that:
     * - `[[toc]]` directives in the source are replaced with rendered TOC HTML, and
     * - the returned `RenderResult` carries `TocEntry[]` for template access.
     *
     * @param string $source Djot source content.
     * @param \Glaze\Config\DjotOptions|array<string, mixed> $djot Djot renderer options.
     * @param \Glaze\Config\SiteConfig|null $siteConfig Site configuration used for internal path rewriting.
     * @param string|null $relativePagePath Relative source page path for content-relative links.
     */
    public function renderWithToc(
        string $source,
        array|DjotOptions $djot = [],
        ?SiteConfig $siteConfig = null,
        ?string $relativePagePath = null,
    ): RenderResult {
        $toc = new TocExtension();
        $options = $this->normalizeOptions($djot);
        $converter = $this->converter ?? $this->createConverter($options, $siteConfig, $relativePagePath);
        $converter->addExtension($toc);

        $html = $toc->injectToc($converter->convert($source));

        return new RenderResult(html: $html, toc: $toc->getEntries());
    }

    /**
     * Create a converter instance with configured extensions.
     *
     * @param \Glaze\Config\DjotOptions $djot Djot renderer options.
     * @param \Glaze\Config\SiteConfig|null $siteConfig Site configuration used for internal path rewriting.
     * @param string|null $relativePagePath Relative source page path for content-relative links.
     */
    protected function createConverter(
        DjotOptions $djot,
        ?SiteConfig $siteConfig = null,
        ?string $relativePagePath = null,
    ): DjotConverter {
        $converter = new DjotConverter();
        $converter->addExtension(new InternalDjotLinkExtension(
            resourcePathRewriter: $this->resourcePathRewriter,
            siteConfig: $siteConfig,
            relativePagePath: $relativePagePath,
        ));

        if ($djot->headerAnchorsEnabled && class_exists(HeadingPermalinksExtension::class)) {
            $converter->addExtension(new HeadingPermalinksExtension(
                symbol: $djot->headerAnchorsSymbol,
                position: $djot->headerAnchorsPosition,
                cssClass: $djot->headerAnchorsCssClass,
                ariaLabel: $djot->headerAnchorsAriaLabel,
                levels: $djot->headerAnchorsLevels,
            ));
        }

        if ($djot->autolinkEnabled && class_exists(AutolinkExtension::class)) {
            $converter->addExtension(new AutolinkExtension(
                allowedSchemes: $djot->autolinkAllowedSchemes,
            ));
        }

        if ($djot->externalLinksEnabled && class_exists(ExternalLinksExtension::class)) {
            $converter->addExtension(new ExternalLinksExtension(
                internalHosts: $djot->externalLinksInternalHosts,
                target: $djot->externalLinksTarget,
                rel: $djot->externalLinksRel,
                nofollow: $djot->externalLinksNofollow,
            ));
        }

        if ($djot->smartQuotesEnabled && class_exists(SmartQuotesExtension::class)) {
            $converter->addExtension(new SmartQuotesExtension(
                locale: $djot->smartQuotesLocale,
                openDoubleQuote: $djot->smartQuotesOpenDouble,
                closeDoubleQuote: $djot->smartQuotesCloseDouble,
                openSingleQuote: $djot->smartQuotesOpenSingle,
                closeSingleQuote: $djot->smartQuotesCloseSingle,
            ));
        }

        if ($djot->mentionsEnabled && class_exists(MentionsExtension::class)) {
            $converter->addExtension(new MentionsExtension(
                urlTemplate: $djot->mentionsUrlTemplate,
                cssClass: $djot->mentionsCssClass,
            ));
        }

        if ($djot->semanticSpanEnabled && class_exists(SemanticSpanExtension::class)) {
            $converter->addExtension(new SemanticSpanExtension());
        }

        if ($djot->defaultAttributesEnabled && class_exists(DefaultAttributesExtension::class)) {
            $converter->addExtension(new DefaultAttributesExtension(
                defaults: $djot->defaultAttributesDefaults,
            ));
        }

        if (!$djot->codeHighlightingEnabled) {
            return $converter;
        }

        $converter->addExtension(
            new PhikiExtension(
                new PhikiCodeBlockRenderer(
                    theme: $this->resolveTheme($djot->codeHighlightingTheme),
                    withGutter: $djot->codeHighlightingWithGutter,
                ),
            ),
        );

        return $converter;
    }

    /**
     * Resolve configured theme value to a Phiki theme enum when available.
     *
     * @param string $theme Theme identifier.
     */
    protected function resolveTheme(string $theme): string|Theme
    {
        $normalizedTheme = strtolower(trim($theme));
        if ($normalizedTheme === '') {
            return Theme::Nord;
        }

        foreach (Theme::cases() as $case) {
            if ($case->value === $normalizedTheme) {
                return $case;
            }
        }

        return $normalizedTheme;
    }

    /**
     * Normalize a raw Djot options map into a typed value object.
     *
     * @param \Glaze\Config\DjotOptions|array<string, mixed> $djot Raw or typed options.
     */
    protected function normalizeOptions(array|DjotOptions $djot): DjotOptions
    {
        if ($djot instanceof DjotOptions) {
            return $djot;
        }

        return DjotOptions::fromProjectConfig($djot);
    }
}
