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
     * @param \Glaze\Config\BuildConfig $config Build configuration.
     * @param \Glaze\Content\ContentPage $page Page to render.
     * @param string $pageTemplate Template name to render with.
     * @param bool $debug Whether to enable template debug freshness checks.
     * @param \Glaze\Template\SiteIndex|null $siteIndex Optional pre-built site index.
     * @param \Glaze\Template\Extension\ExtensionRegistry|null $extensionRegistry Optional pre-built extension registry.
     * @param \Glaze\Build\Event\EventDispatcher|null $dispatcher Optional build event dispatcher.
     */
    public function render(
        BuildConfig $config,
        ContentPage $page,
        string $pageTemplate,
        bool $debug,
        ?SiteIndex $siteIndex = null,
        ?ExtensionRegistry $extensionRegistry = null,
        ?EventDispatcher $dispatcher = null,
    ): string {
        $pageRenderer = $this->sugarPageRendererFactory->createCached($config, $pageTemplate, $debug, $dispatcher);

        $assetResolver = new ContentAssetResolver($config->contentPath(), $config->site->basePath);
        $siteIndex ??= new SiteIndex([$page], $assetResolver);

        $renderResult = $this->djotRenderer->renderWithToc(
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

        if (!$debug) {
            return $this->buildGlideHtmlRewriter->rewrite($output, $config);
        }

        return $output;
    }
}
