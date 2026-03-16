<?php
declare(strict_types=1);

namespace Glaze\Render;

use ArrayObject;
use Glaze\Build\Event\EventDispatcher;
use Glaze\Build\PageMetaResolver;
use Glaze\Config\BuildConfig;
use Glaze\Content\ContentPage;
use Glaze\Support\BuildGlideHtmlRewriter;
use Glaze\Support\ResourcePathRewriter;
use Glaze\Template\ContentAssetResolver;
use Glaze\Template\Extension\ExtensionRegistry;
use Glaze\Template\SiteContext;
use Glaze\Template\SiteIndex;

/**
 * Executes the page rendering pipeline from Djot source to final HTML output.
 */
final class PageRenderPipeline
{
    /**
     * Constructor.
     *
     * @param \Glaze\Render\DjotRenderer $djotRenderer Djot renderer service.
     * @param \Glaze\Render\SugarPageRendererFactory $sugarPageRendererFactory Sugar renderer factory.
     * @param \Glaze\Support\BuildGlideHtmlRewriter $buildGlideHtmlRewriter Build-time Glide source rewriter.
     * @param \Glaze\Support\ResourcePathRewriter $resourcePathRewriter Shared resource path rewriter.
     * @param \Glaze\Build\PageMetaResolver $pageMetaResolver Page meta resolver.
     */
    public function __construct(
        protected DjotRenderer $djotRenderer,
        protected SugarPageRendererFactory $sugarPageRendererFactory,
        protected BuildGlideHtmlRewriter $buildGlideHtmlRewriter,
        protected ResourcePathRewriter $resourcePathRewriter,
        protected PageMetaResolver $pageMetaResolver,
    ) {
    }

    /**
     * Render one content page to final HTML.
     *
     * Returns a `PageRenderOutput` containing both the rendered HTML string and
     * the toc-enriched `ContentPage`, allowing callers to access the collected
     * TOC entries when dispatching downstream build events.
     *
     * @param \Glaze\Config\BuildConfig $config Build configuration.
     * @param \Glaze\Content\ContentPage $page Page to render.
     * @param string $pageTemplate Template name to render with.
     * @param bool $liveMode Whether this render is for live serving instead of static build output.
     * @param \Glaze\Template\SiteIndex|null $siteIndex Language-scoped index for navigation.
     *   When `$globalIndex` is also provided this should contain only the current language's pages.
     * @param \Glaze\Template\SiteIndex|null $globalIndex Full site-wide index for cross-language lookups.
     *   Pass when `$siteIndex` is limited to a specific language (i18n builds). Omit for single-language builds.
     * @param \Glaze\Template\Extension\ExtensionRegistry|null $extensionRegistry Optional pre-built extension registry.
     * @param \Glaze\Build\Event\EventDispatcher|null $dispatcher Optional build event dispatcher.
     */
    public function render(
        BuildConfig $config,
        ContentPage $page,
        string $pageTemplate,
        bool $liveMode,
        ?SiteIndex $siteIndex = null,
        ?SiteIndex $globalIndex = null,
        ?ExtensionRegistry $extensionRegistry = null,
        ?EventDispatcher $dispatcher = null,
    ): PageRenderOutput {
        $pageRenderer = $this->sugarPageRendererFactory->createCached(
            $config,
            $pageTemplate,
            $liveMode,
            $dispatcher,
        );

        $assetResolver = new ContentAssetResolver($config->contentPath(), $config->site->basePath);
        $siteIndex ??= new SiteIndex([$page], $assetResolver);

        $renderResult = $this->djotRenderer->render(
            source: $page->source,
            djot: $config->djotOptions,
            siteConfig: $config->site,
            relativePagePath: $page->relativePath,
            dispatcher: $dispatcher,
            page: $page,
            config: $config,
        );

        $page = $page->withToc($renderResult->toc);

        $templateContext = new SiteContext(
            siteIndex: $siteIndex,
            currentPage: $page,
            extensions: $extensionRegistry ?? new ExtensionRegistry(),
            assetResolver: $assetResolver,
            siteConfig: $config->site,
            i18nConfig: $config->i18n,
            translationsPath: $config->i18n->isEnabled()
                ? $config->translationsPath()
                : '',
            globalIndex: $globalIndex,
            liveMode: $liveMode,
        );

        $htmlContent = $renderResult->html;
        $pageUrl = $this->resourcePathRewriter->applyBasePathToPath($page->urlPath, $config->site);

        $output = $pageRenderer->render([
            'title' => $page->title,
            'url' => $pageUrl,
            'content' => $htmlContent,
            'page' => $page,
            'meta' => new ArrayObject(
                $this->pageMetaResolver->resolve($page, $config->site),
                ArrayObject::ARRAY_AS_PROPS,
            ),
            'site' => $config->site,
        ], $templateContext);

        if (!$liveMode) {
            return new PageRenderOutput($this->buildGlideHtmlRewriter->rewrite($output, $config), $page);
        }

        return new PageRenderOutput($output, $page);
    }

    /**
     * Extract table-of-contents entries from a page's Djot source without running
     * the full Sugar template pipeline.
     *
     * This performs a lightweight Djot-only conversion to collect heading entries.
     * The rendered HTML is discarded — only the TOC list is returned. Used by
     * `SiteBuilder` to populate TOC data for cache-hit pages that skip the full
     * render pass, so that `PageRendered` listeners always receive a complete page.
     *
     * @param \Glaze\Content\ContentPage $page Source page to extract TOC from.
     * @param \Glaze\Config\BuildConfig $config Active build configuration.
     * @return list<\Glaze\Render\Djot\TocEntry> TOC entries in document order.
     */
    public function extractToc(ContentPage $page, BuildConfig $config): array
    {
        return $this->djotRenderer->render(
            source: $page->source,
            djot: $config->djotOptions,
            siteConfig: $config->site,
            relativePagePath: $page->relativePath,
        )->toc;
    }
}
