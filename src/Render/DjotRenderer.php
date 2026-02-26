<?php
declare(strict_types=1);

namespace Glaze\Render;

use Djot\DjotConverter;
use Glaze\Config\SiteConfig;
use Glaze\Render\Djot\HeaderAnchorsExtension;
use Glaze\Render\Djot\InternalDjotLinkExtension;
use Glaze\Render\Djot\PhikiCodeBlockRenderer;
use Glaze\Render\Djot\PhikiExtension;
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
     * @param array{codeHighlighting: array{enabled: bool, theme: string, withGutter: bool}, headerAnchors: array{enabled: bool, symbol: string, position: string, cssClass: string, ariaLabel: string, levels: array<int>}} $djot Djot renderer options.
     * @param \Glaze\Config\SiteConfig|null $siteConfig Site configuration used for internal path rewriting.
     * @param string|null $relativePagePath Relative source page path for content-relative links.
     */
    public function render(
        string $source,
        array $djot = [
            'codeHighlighting' => ['enabled' => true, 'theme' => 'nord', 'withGutter' => false],
            'headerAnchors' => [
                'enabled' => false,
                'symbol' => '#',
                'position' => 'after',
                'cssClass' => 'header-anchor',
                'ariaLabel' => 'Anchor link',
                'levels' => [1, 2, 3, 4, 5, 6],
            ],
        ],
        ?SiteConfig $siteConfig = null,
        ?string $relativePagePath = null,
    ): string {
        $converter = $this->converter ?? $this->createConverter($djot, $siteConfig, $relativePagePath);

        return $converter->convert($source);
    }

    /**
     * Create a converter instance with configured extensions.
     *
     * @param array{codeHighlighting: array{enabled: bool, theme: string, withGutter: bool}, headerAnchors: array{enabled: bool, symbol: string, position: string, cssClass: string, ariaLabel: string, levels: array<int>}} $djot Djot renderer options.
     * @param \Glaze\Config\SiteConfig|null $siteConfig Site configuration used for internal path rewriting.
     * @param string|null $relativePagePath Relative source page path for content-relative links.
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

        $headerAnchors = $djot['headerAnchors'];
        if ($headerAnchors['enabled']) {
            $converter->addExtension(new HeaderAnchorsExtension(
                symbol: $headerAnchors['symbol'],
                position: $headerAnchors['position'],
                cssClass: $headerAnchors['cssClass'],
                ariaLabel: $headerAnchors['ariaLabel'],
                levels: $headerAnchors['levels'],
            ));
        }

        $codeHighlighting = $djot['codeHighlighting'];

        if (!$codeHighlighting['enabled']) {
            return $converter;
        }

        $converter->addExtension(
            new PhikiExtension(
                new PhikiCodeBlockRenderer(
                    theme: $this->resolveTheme($codeHighlighting['theme']),
                    withGutter: $codeHighlighting['withGutter'],
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
