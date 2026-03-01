<?php
declare(strict_types=1);

namespace Glaze\Render;

use Djot\DjotConverter;
use Glaze\Config\DjotOptions;
use Glaze\Config\SiteConfig;
use Glaze\Render\Djot\DjotConverterFactory;
use Glaze\Render\Djot\TocExtension;

/**
 * Converts Djot source documents to HTML.
 */
final class DjotRenderer
{
    /**
     * Constructor.
     *
     * @param \Glaze\Render\Djot\DjotConverterFactory $converterFactory Djot converter factory.
     */
    public function __construct(
        protected DjotConverterFactory $converterFactory,
    ) {
    }

    /**
     * Render Djot source to HTML.
     *
     * @param string $source Djot source content.
     * @param \Glaze\Config\DjotOptions $djot Djot renderer options.
     * @param \Glaze\Config\SiteConfig|null $siteConfig Site configuration used for internal path rewriting.
     * @param string|null $relativePagePath Relative source page path for content-relative links.
     */
    public function render(
        string $source,
        ?DjotOptions $djot = null,
        ?SiteConfig $siteConfig = null,
        ?string $relativePagePath = null,
    ): string {
        return $this->renderWithToc($source, $djot, $siteConfig, $relativePagePath)->html;
    }

    /**
     * Render Djot source to HTML and collect table-of-contents entries in a single pass.
     *
     * @param string $source Djot source content.
     * @param \Glaze\Config\DjotOptions $djot Djot renderer options.
     * @param \Glaze\Config\SiteConfig|null $siteConfig Site configuration used for internal path rewriting.
     * @param string|null $relativePagePath Relative source page path for content-relative links.
     */
    public function renderWithToc(
        string $source,
        ?DjotOptions $djot = null,
        ?SiteConfig $siteConfig = null,
        ?string $relativePagePath = null,
    ): RenderResult {
        $toc = new TocExtension();
        $converter = $this->createConverter($djot ?? new DjotOptions(), $siteConfig, $relativePagePath);
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
        return $this->converterFactory->create($djot, $siteConfig, $relativePagePath);
    }
}
