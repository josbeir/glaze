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
use Glaze\Config\SiteConfig;
use Glaze\Render\Djot\InternalDjotLinkExtension;
use Glaze\Render\Djot\PhikiCodeBlockRenderer;
use Glaze\Render\Djot\PhikiExtension;
use Glaze\Render\Djot\TocExtension;
use Glaze\Support\ResourcePathRewriter;
use Phiki\Theme\Theme;

/**
 * Converts Djot source documents to HTML.
 *
 * @phpstan-type DjotOptions array{
 *     codeHighlighting?: array{enabled: bool, theme: string, withGutter: bool},
 *     headerAnchors?: array{enabled: bool, symbol: string, position: string, cssClass: string, ariaLabel: string, levels: array<int>},
 *     autolink?: array{enabled: bool, allowedSchemes: array<string>},
 *     externalLinks?: array{enabled: bool, internalHosts: array<string>, target: string, rel: string, nofollow: bool},
 *     smartQuotes?: array{enabled: bool, locale: string|null, openDouble: string|null, closeDouble: string|null, openSingle: string|null, closeSingle: string|null},
 *     mentions?: array{enabled: bool, urlTemplate: string, cssClass: string},
 *     semanticSpan?: array{enabled: bool},
 *     defaultAttributes?: array{enabled: bool, defaults: array<string, array<string, string>>},
 * }
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
     * @param array<string, mixed> $djot Djot renderer options.
     * @param \Glaze\Config\SiteConfig|null $siteConfig Site configuration used for internal path rewriting.
     * @param string|null $relativePagePath Relative source page path for content-relative links.
     * @phpstan-param DjotOptions $djot
     */
    public function render(
        string $source,
        array $djot = [],
        ?SiteConfig $siteConfig = null,
        ?string $relativePagePath = null,
    ): string {
        $converter = $this->converter ?? $this->createConverter($djot, $siteConfig, $relativePagePath);

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
     * @param array<string, mixed> $djot Djot renderer options.
     * @param \Glaze\Config\SiteConfig|null $siteConfig Site configuration used for internal path rewriting.
     * @param string|null $relativePagePath Relative source page path for content-relative links.
     * @phpstan-param DjotOptions $djot
     */
    public function renderWithToc(
        string $source,
        array $djot = [],
        ?SiteConfig $siteConfig = null,
        ?string $relativePagePath = null,
    ): RenderResult {
        $toc = new TocExtension();
        $converter = $this->converter ?? $this->createConverter($djot, $siteConfig, $relativePagePath);
        $converter->addExtension($toc);

        $html = $toc->injectToc($converter->convert($source));

        return new RenderResult(html: $html, toc: $toc->getEntries());
    }

    /**
     * Create a converter instance with configured extensions.
     *
     * @param array<string, mixed> $djot Djot renderer options.
     * @param \Glaze\Config\SiteConfig|null $siteConfig Site configuration used for internal path rewriting.
     * @param string|null $relativePagePath Relative source page path for content-relative links.
     * @phpstan-param DjotOptions $djot
     */
    protected function createConverter(
        array $djot,
        ?SiteConfig $siteConfig = null,
        ?string $relativePagePath = null,
    ): DjotConverter {
        $converter = new DjotConverter();
        $converter->addExtension(new InternalDjotLinkExtension(
            resourcePathRewriter: $this->resourcePathRewriter,
            siteConfig: $siteConfig,
            relativePagePath: $relativePagePath,
        ));

        $headerAnchors = $djot['headerAnchors'] ?? null;
        if ($headerAnchors !== null && $headerAnchors['enabled']) {
            $converter->addExtension(new HeadingPermalinksExtension(
                symbol: $headerAnchors['symbol'],
                position: $headerAnchors['position'],
                cssClass: $headerAnchors['cssClass'],
                ariaLabel: $headerAnchors['ariaLabel'],
                levels: $headerAnchors['levels'],
            ));
        }

        $autolink = $djot['autolink'] ?? null;
        if ($autolink !== null && $autolink['enabled']) {
            $converter->addExtension(new AutolinkExtension(
                allowedSchemes: $autolink['allowedSchemes'],
            ));
        }

        $externalLinks = $djot['externalLinks'] ?? null;
        if ($externalLinks !== null && $externalLinks['enabled']) {
            $converter->addExtension(new ExternalLinksExtension(
                internalHosts: $externalLinks['internalHosts'],
                target: $externalLinks['target'],
                rel: $externalLinks['rel'],
                nofollow: $externalLinks['nofollow'],
            ));
        }

        $smartQuotes = $djot['smartQuotes'] ?? null;
        if ($smartQuotes !== null && $smartQuotes['enabled']) {
            $converter->addExtension(new SmartQuotesExtension(
                locale: $smartQuotes['locale'],
                openDoubleQuote: $smartQuotes['openDouble'],
                closeDoubleQuote: $smartQuotes['closeDouble'],
                openSingleQuote: $smartQuotes['openSingle'],
                closeSingleQuote: $smartQuotes['closeSingle'],
            ));
        }

        $mentions = $djot['mentions'] ?? null;
        if ($mentions !== null && $mentions['enabled']) {
            $converter->addExtension(new MentionsExtension(
                urlTemplate: $mentions['urlTemplate'],
                cssClass: $mentions['cssClass'],
            ));
        }

        $semanticSpan = $djot['semanticSpan'] ?? null;
        if ($semanticSpan !== null && $semanticSpan['enabled']) {
            $converter->addExtension(new SemanticSpanExtension());
        }

        $defaultAttributes = $djot['defaultAttributes'] ?? null;
        if ($defaultAttributes !== null && $defaultAttributes['enabled']) {
            $converter->addExtension(new DefaultAttributesExtension(
                defaults: $defaultAttributes['defaults'],
            ));
        }

        $codeHighlighting = $djot['codeHighlighting'] ?? null;

        if ($codeHighlighting !== null && !$codeHighlighting['enabled']) {
            return $converter;
        }

        $converter->addExtension(
            new PhikiExtension(
                new PhikiCodeBlockRenderer(
                    theme: $this->resolveTheme($codeHighlighting['theme'] ?? 'nord'),
                    withGutter: $codeHighlighting['withGutter'] ?? false,
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
}
