<?php
declare(strict_types=1);

namespace Glaze\Build;

use ArrayObject;
use Glaze\Build\Event\BuildCompletedEvent;
use Glaze\Build\Event\BuildEvent;
use Glaze\Build\Event\BuildStartedEvent;
use Glaze\Build\Event\ContentDiscoveredEvent;
use Glaze\Build\Event\EventDispatcher;
use Glaze\Build\Event\PageRenderedEvent;
use Glaze\Build\Event\PageWrittenEvent;
use Glaze\Config\BuildConfig;
use Glaze\Content\ContentDiscoveryService;
use Glaze\Content\ContentPage;
use Glaze\Render\DjotRenderer;
use Glaze\Render\SugarPageRenderer;
use Glaze\Support\BuildGlideHtmlRewriter;
use Glaze\Support\ResourcePathRewriter;
use Glaze\Template\Extension\ExtensionLoader;
use Glaze\Template\Extension\ExtensionRegistry;
use Glaze\Template\SiteContext;
use Glaze\Template\SiteIndex;
use RuntimeException;

/**
 * Coordinates content discovery, rendering, and file output.
 */
final class SiteBuilder
{
    /**
     * Constructor.
     *
     * @param \Glaze\Content\ContentDiscoveryService $discoveryService Content discovery service.
     * @param \Glaze\Render\DjotRenderer $djotRenderer Djot renderer service.
     * @param \Glaze\Build\ContentAssetPublisher $assetPublisher Asset publishing service.
     * @param \Glaze\Build\PageMetaResolver $pageMetaResolver Page meta resolver.
     * @param \Glaze\Support\BuildGlideHtmlRewriter $buildGlideHtmlRewriter Build-time Glide source rewriter.
     * @param \Glaze\Support\ResourcePathRewriter $resourcePathRewriter Shared resource path rewriter.
     */
    public function __construct(
        protected ContentDiscoveryService $discoveryService,
        protected DjotRenderer $djotRenderer,
        protected ContentAssetPublisher $assetPublisher,
        protected PageMetaResolver $pageMetaResolver,
        protected BuildGlideHtmlRewriter $buildGlideHtmlRewriter,
        protected ResourcePathRewriter $resourcePathRewriter,
    ) {
    }

    /**
     * Render a page for a request path in live mode.
     *
     * @param \Glaze\Config\BuildConfig $config Build configuration.
     * @param string $requestPath Request URI path.
     * @return string|null Rendered HTML or null when no page matches.
     */
    public function renderRequest(BuildConfig $config, string $requestPath): ?string
    {
        $pages = $this->filterPages(
            $this->discoveryService->discover($config->contentPath(), $config->taxonomies, $config->contentTypes),
            $config,
        );
        $page = $this->matchPageByPath($pages, $requestPath);
        if (!$page instanceof ContentPage) {
            return null;
        }

        $siteIndex = new SiteIndex($pages);
        $extensionRegistry = ExtensionLoader::loadFromProjectRoot($config->projectRoot, $config->extensionsDir);

        return $this->renderPage($config, $page, true, null, $siteIndex, $extensionRegistry);
    }

    /**
     * Build static pages and write them to disk.
     *
     * @param \Glaze\Config\BuildConfig $config Build configuration.
     * @param bool $cleanOutput Whether to clear output directory before build.
     * @param callable(int, int, string):void|null $progressCallback Optional per-page progress callback.
     * @param \Glaze\Build\Event\EventDispatcher|null $dispatcher Optional pre-configured dispatcher;
     *                                                             when null a fresh dispatcher is created.
     * @return array<string> Generated file paths.
     */
    public function build(
        BuildConfig $config,
        bool $cleanOutput = false,
        ?callable $progressCallback = null,
        ?EventDispatcher $dispatcher = null,
    ): array {
        if ($cleanOutput) {
            $this->removeDirectory($config->outputPath());
        }

        $startTime = hrtime(true);
        $dispatcher ??= new EventDispatcher();
        $extensionRegistry = ExtensionLoader::loadFromProjectRoot(
            $config->projectRoot,
            $config->extensionsDir,
            $dispatcher,
        );

        $dispatcher->dispatch(BuildEvent::BuildStarted, new BuildStartedEvent($config));

        $discoveredEvent = new ContentDiscoveredEvent(
            $this->filterPages(
                $this->discoveryService->discover($config->contentPath(), $config->taxonomies, $config->contentTypes),
                $config,
            ),
            $config,
        );
        $dispatcher->dispatch(BuildEvent::ContentDiscovered, $discoveredEvent);
        $pages = $discoveredEvent->pages;

        $pageRenderer = new SugarPageRenderer(
            templatePath: $config->templatePath(),
            cachePath: $config->templateCachePath(),
            template: $config->pageTemplate,
            siteConfig: $config->site,
            resourcePathRewriter: $this->resourcePathRewriter,
            templateVite: $config->templateViteOptions,
        );
        $siteIndex = new SiteIndex($pages);
        $rendererCache = [
            $config->pageTemplate => $pageRenderer,
        ];

        $writtenFiles = [];
        $totalPages = count($pages);
        $completedPages = 0;
        if (is_callable($progressCallback)) {
            $progressCallback($completedPages, $totalPages, '');
        }

        foreach ($pages as $page) {
            $pageTemplate = $this->resolvePageTemplate($page, $config);
            $activeRenderer = $rendererCache[$pageTemplate] ?? new SugarPageRenderer(
                templatePath: $config->templatePath(),
                cachePath: $config->templateCachePath(),
                template: $pageTemplate,
                siteConfig: $config->site,
                resourcePathRewriter: $this->resourcePathRewriter,
                templateVite: $config->templateViteOptions,
            );
            $rendererCache[$pageTemplate] = $activeRenderer;

            $html = $this->renderPage(
                config: $config,
                page: $page,
                debug: false,
                pageRenderer: $activeRenderer,
                siteIndex: $siteIndex,
                extensionRegistry: $extensionRegistry,
            );

            $renderedEvent = new PageRenderedEvent($page, $html, $config);
            $dispatcher->dispatch(BuildEvent::PageRendered, $renderedEvent);
            $html = $renderedEvent->html;

            $destination = $config->outputPath() . DIRECTORY_SEPARATOR . $page->outputRelativePath;
            $this->writeFile($destination, $html);
            $dispatcher->dispatch(BuildEvent::PageWritten, new PageWrittenEvent($page, $destination, $config));
            $writtenFiles[] = $destination;
            $completedPages++;

            if (is_callable($progressCallback)) {
                $progressCallback($completedPages, $totalPages, $destination);
            }
        }

        $this->assetPublisher->publish(
            contentPath: $config->contentPath(),
            outputPath: $config->outputPath(),
        );

        $this->assetPublisher->publishStatic(
            staticPath: $config->staticPath(),
            outputPath: $config->outputPath(),
        );

        $dispatcher->dispatch(
            BuildEvent::BuildCompleted,
            new BuildCompletedEvent($writtenFiles, $config, (float)(hrtime(true) - $startTime) / 1e9),
        );

        return $writtenFiles;
    }

    /**
     * Ensure parent directories and write final output file.
     *
     * @param string $destination Absolute destination path.
     * @param string $content Rendered HTML content.
     */
    protected function writeFile(string $destination, string $content): void
    {
        $directory = dirname($destination);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create output directory "%s".', $directory));
        }

        $written = file_put_contents($destination, $content);
        if ($written === false) {
            throw new RuntimeException(sprintf('Unable to write output file "%s".', $destination));
        }
    }

    /**
     * Remove a directory tree recursively.
     *
     * @param string $directory Absolute directory path.
     */
    protected function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $entries = scandir($directory);
        if (!is_array($entries)) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.') {
                continue;
            }

            if ($entry === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                $this->removeDirectory($path);
                continue;
            }

            unlink($path);
        }

        rmdir($directory);
    }

    /**
     * Render a content page using shared Djot and Sugar pipeline.
     *
     * @param \Glaze\Config\BuildConfig $config Build configuration.
     * @param \Glaze\Content\ContentPage $page Page to render.
     * @param bool $debug Whether to enable template debug freshness checks.
     * @param \Glaze\Render\SugarPageRenderer|null $pageRenderer Optional pre-built renderer.
     * @param \Glaze\Template\SiteIndex|null $siteIndex Optional pre-built site index.
     * @param \Glaze\Template\Extension\ExtensionRegistry|null $extensionRegistry Optional pre-built extension registry.
     */
    protected function renderPage(
        BuildConfig $config,
        ContentPage $page,
        bool $debug,
        ?SugarPageRenderer $pageRenderer = null,
        ?SiteIndex $siteIndex = null,
        ?ExtensionRegistry $extensionRegistry = null,
    ): string {
        $pageTemplate = $this->resolvePageTemplate($page, $config);
        $activeRenderer = $pageRenderer;

        if (
            !$activeRenderer instanceof SugarPageRenderer
            || $activeRenderer->templateName() !== $pageTemplate
            || $activeRenderer->isDebugEnabled() !== $debug
        ) {
            $activeRenderer = new SugarPageRenderer(
                templatePath: $config->templatePath(),
                cachePath: $config->templateCachePath(),
                template: $pageTemplate,
                siteConfig: $config->site,
                resourcePathRewriter: $this->resourcePathRewriter,
                templateVite: $config->templateViteOptions,
                debug: $debug,
            );
        }

        $siteIndex ??= new SiteIndex([$page]);

        $renderResult = $this->djotRenderer->renderWithToc(
            source: $page->source,
            djot: $config->djotOptions,
            siteConfig: $config->site,
            relativePagePath: $page->relativePath,
        );

        $page = $page->withToc($renderResult->toc);

        $templateContext = new SiteContext(
            siteIndex: $siteIndex,
            currentPage: $page,
            extensions: $extensionRegistry ?? new ExtensionRegistry(),
        );

        $htmlContent = $renderResult->html;
        if (!$debug) {
            $htmlContent = $this->buildGlideHtmlRewriter->rewrite($htmlContent, $config);
        }

        $pageUrl = $this->resourcePathRewriter->applyBasePathToPath($page->urlPath, $config->site);

        return $activeRenderer->render([
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
    }

    /**
     * Resolve effective page template from frontmatter metadata or build defaults.
     *
     * @param \Glaze\Content\ContentPage $page Source page metadata.
     * @param \Glaze\Config\BuildConfig $config Build configuration.
     */
    protected function resolvePageTemplate(ContentPage $page, BuildConfig $config): string
    {
        $template = $page->meta['template'] ?? null;
        if (!is_string($template)) {
            return $config->pageTemplate;
        }

        $normalized = trim($template);

        return $normalized === '' ? $config->pageTemplate : $normalized;
    }

    /**
     * Match request path against discovered pages.
     *
     * @param array<\Glaze\Content\ContentPage> $pages Discovered pages.
     * @param string $requestPath Request URI path.
     */
    protected function matchPageByPath(array $pages, string $requestPath): ?ContentPage
    {
        $normalizedRequestPath = rtrim($requestPath, '/');
        $normalizedRequestPath = $normalizedRequestPath === '' ? '/' : $normalizedRequestPath;

        foreach ($pages as $page) {
            $pagePath = rtrim($page->urlPath, '/');
            $pagePath = $pagePath === '' ? '/' : $pagePath;
            if ($pagePath === $normalizedRequestPath) {
                return $page;
            }
        }

        return null;
    }

    /**
     * Filter discovered pages according to build configuration.
     *
     * @param array<\Glaze\Content\ContentPage> $pages Discovered pages.
     * @param \Glaze\Config\BuildConfig $config Build configuration.
     * @return array<\Glaze\Content\ContentPage>
     */
    protected function filterPages(array $pages, BuildConfig $config): array
    {
        if ($config->includeDrafts) {
            return $pages;
        }

        return array_values(array_filter($pages, static fn(ContentPage $page): bool => !$page->draft));
    }
}
