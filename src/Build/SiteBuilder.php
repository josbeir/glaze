<?php
declare(strict_types=1);

namespace Glaze\Build;

use ArrayObject;
use Glaze\Config\BuildConfig;
use Glaze\Content\ContentDiscoveryService;
use Glaze\Content\ContentPage;
use Glaze\Render\DjotRenderer;
use Glaze\Render\SugarPageRenderer;
use Glaze\Template\SiteContext;
use Glaze\Template\SiteIndex;
use RuntimeException;

/**
 * Coordinates content discovery, rendering, and file output.
 */
final class SiteBuilder
{
    protected ContentDiscoveryService $discoveryService;

    protected DjotRenderer $djotRenderer;

    protected ContentAssetPublisher $assetPublisher;

    protected PageMetaResolver $pageMetaResolver;

    /**
     * Constructor.
     *
     * @param \Glaze\Content\ContentDiscoveryService|null $discoveryService Content discovery service.
     * @param \Glaze\Render\DjotRenderer|null $djotRenderer Djot renderer service.
     * @param \Glaze\Build\ContentAssetPublisher|null $assetPublisher Asset publishing service.
     * @param \Glaze\Build\PageMetaResolver|null $pageMetaResolver Page meta resolver.
     */
    public function __construct(
        ?ContentDiscoveryService $discoveryService = null,
        ?DjotRenderer $djotRenderer = null,
        ?ContentAssetPublisher $assetPublisher = null,
        ?PageMetaResolver $pageMetaResolver = null,
    ) {
        $this->discoveryService = $discoveryService ?? new ContentDiscoveryService();
        $this->djotRenderer = $djotRenderer ?? new DjotRenderer();
        $this->assetPublisher = $assetPublisher ?? new ContentAssetPublisher();
        $this->pageMetaResolver = $pageMetaResolver ?? new PageMetaResolver();
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
            $this->discoveryService->discover($config->contentPath(), $config->taxonomies),
            $config,
        );
        $page = $this->matchPageByPath($pages, $requestPath);
        if (!$page instanceof ContentPage) {
            return null;
        }

        $siteIndex = new SiteIndex($pages);

        return $this->renderPage($config, $page, true, null, $siteIndex);
    }

    /**
     * Build static pages and write them to disk.
     *
     * @param \Glaze\Config\BuildConfig $config Build configuration.
     * @param bool $cleanOutput Whether to clear output directory before build.
     * @return array<string> Generated file paths.
     */
    public function build(BuildConfig $config, bool $cleanOutput = false): array
    {
        if ($cleanOutput) {
            $this->removeDirectory($config->outputPath());
        }

        $pages = $this->filterPages(
            $this->discoveryService->discover($config->contentPath(), $config->taxonomies),
            $config,
        );
        $pageRenderer = new SugarPageRenderer(
            templatePath: $config->templatePath(),
            cachePath: $config->cachePath(),
            template: $config->pageTemplate,
        );
        $siteIndex = new SiteIndex($pages);

        $writtenFiles = [];
        foreach ($pages as $page) {
            $html = $this->renderPage(
                config: $config,
                page: $page,
                debug: false,
                pageRenderer: $pageRenderer,
                siteIndex: $siteIndex,
            );

            $destination = $config->outputPath() . DIRECTORY_SEPARATOR . $page->outputRelativePath;
            $this->writeFile($destination, $html);
            $writtenFiles[] = $destination;
        }

        $this->assetPublisher->publish(
            contentPath: $config->contentPath(),
            outputPath: $config->outputPath(),
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
     */
    protected function renderPage(
        BuildConfig $config,
        ContentPage $page,
        bool $debug,
        ?SugarPageRenderer $pageRenderer = null,
        ?SiteIndex $siteIndex = null,
    ): string {
        $pageRenderer ??= new SugarPageRenderer(
            templatePath: $config->templatePath(),
            cachePath: $config->cachePath(),
            template: $config->pageTemplate,
            debug: $debug,
        );
        $siteIndex ??= new SiteIndex([$page]);
        $templateContext = new SiteContext(
            siteIndex: $siteIndex,
            currentPage: $page,
        );

        $htmlContent = $this->djotRenderer->render($page->source);
        $htmlContent = $this->rewriteRelativeImageSources($htmlContent, $page);

        return $pageRenderer->render([
            'title' => $page->title,
            'url' => $page->urlPath,
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
     * Rewrite relative image sources to content-root absolute paths.
     *
     * @param string $html Rendered Djot HTML.
     * @param \Glaze\Content\ContentPage $page Source page metadata.
     */
    protected function rewriteRelativeImageSources(string $html, ContentPage $page): string
    {
        $rewritten = preg_replace_callback(
            '/<img\b([^>]*?)\bsrc=("|\')(.*?)\2([^>]*)>/i',
            function (array $matches) use ($page): string {
                $source = $matches[3];
                if ($source === '') {
                    return $matches[0];
                }

                $rewrittenSource = $this->toContentAbsoluteResourcePath($source, $page->relativePath);
                if ($rewrittenSource === $source) {
                    return $matches[0];
                }

                return str_replace($source, $rewrittenSource, $matches[0]);
            },
            $html,
        );

        return is_string($rewritten) ? $rewritten : $html;
    }

    /**
     * Convert a relative resource path to an absolute path from content root.
     *
     * @param string $resourcePath Resource path from rendered HTML.
     * @param string $relativePagePath Relative source page path.
     */
    protected function toContentAbsoluteResourcePath(string $resourcePath, string $relativePagePath): string
    {
        if ($this->isAbsoluteResourcePath($resourcePath)) {
            return $resourcePath;
        }

        preg_match('/^([^?#]*)(.*)$/', $resourcePath, $parts);
        $pathPart = $parts[1];
        $suffix = $parts[2];

        $baseDirectory = dirname(str_replace('\\', '/', $relativePagePath));
        $baseDirectory = $baseDirectory === '.' ? '' : trim($baseDirectory, '/');

        $combinedPath = ($baseDirectory !== '' ? $baseDirectory . '/' : '') . ltrim($pathPart, '/');
        $normalizedPath = $this->normalizePathSegments($combinedPath);

        return '/' . ltrim($normalizedPath, '/') . $suffix;
    }

    /**
     * Detect whether a resource path is already absolute or external.
     *
     * @param string $resourcePath Resource path from rendered HTML.
     */
    protected function isAbsoluteResourcePath(string $resourcePath): bool
    {
        if ($resourcePath === '') {
            return true;
        }

        if (str_starts_with($resourcePath, '/')) {
            return true;
        }

        if (str_starts_with($resourcePath, '#')) {
            return true;
        }

        if (str_starts_with($resourcePath, '//')) {
            return true;
        }

        return preg_match('/^[a-z][a-z0-9+.-]*:/i', $resourcePath) === 1;
    }

    /**
     * Normalize dot segments in a path.
     *
     * @param string $path Relative path to normalize.
     */
    protected function normalizePathSegments(string $path): string
    {
        $segments = explode('/', str_replace('\\', '/', $path));
        $normalized = [];

        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }

            if ($segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($normalized);
                continue;
            }

            $normalized[] = $segment;
        }

        return implode('/', $normalized);
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
