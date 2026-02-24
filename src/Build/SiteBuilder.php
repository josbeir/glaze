<?php
declare(strict_types=1);

namespace Glaze\Build;

use Glaze\Config\BuildConfig;
use Glaze\Content\ContentDiscoveryService;
use Glaze\Content\ContentPage;
use Glaze\Render\DjotRenderer;
use Glaze\Render\SugarPageRenderer;
use RuntimeException;

/**
 * Coordinates content discovery, rendering, and file output.
 */
final class SiteBuilder
{
    /**
     * Constructor.
     *
     * @param \Glaze\Content\ContentDiscoveryService|null $discoveryService Content discovery service.
     * @param \Glaze\Render\DjotRenderer|null $djotRenderer Djot renderer service.
     * @param \Glaze\Build\ContentAssetPublisher|null $assetPublisher Asset publishing service.
     */
    public function __construct(
        ?ContentDiscoveryService $discoveryService = null,
        ?DjotRenderer $djotRenderer = null,
        ?ContentAssetPublisher $assetPublisher = null,
    ) {
        $this->discoveryService = $discoveryService ?? new ContentDiscoveryService();
        $this->djotRenderer = $djotRenderer ?? new DjotRenderer();
        $this->assetPublisher = $assetPublisher ?? new ContentAssetPublisher();
    }

    protected ContentDiscoveryService $discoveryService;

    protected DjotRenderer $djotRenderer;

    protected ContentAssetPublisher $assetPublisher;

    /**
     * Render a page for a request path in live mode.
     *
     * @param \Glaze\Config\BuildConfig $config Build configuration.
     * @param string $requestPath Request URI path.
     * @return string|null Rendered HTML or null when no page matches.
     */
    public function renderRequest(BuildConfig $config, string $requestPath): ?string
    {
        $pages = $this->filterPages($this->discoveryService->discover($config->contentPath()), $config);
        $page = $this->matchPageByPath($pages, $requestPath);
        if (!$page instanceof ContentPage) {
            return null;
        }

        return $this->renderPage($config, $page, true);
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

        $pages = $this->filterPages($this->discoveryService->discover($config->contentPath()), $config);
        $pageRenderer = new SugarPageRenderer(
            templatePath: $config->templatePath(),
            cachePath: $config->cachePath(),
            template: $config->pageTemplate,
        );

        $writtenFiles = [];
        foreach ($pages as $page) {
            $html = $this->renderPage(
                config: $config,
                page: $page,
                debug: false,
                pageRenderer: $pageRenderer,
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
     */
    protected function renderPage(
        BuildConfig $config,
        ContentPage $page,
        bool $debug,
        ?SugarPageRenderer $pageRenderer = null,
    ): string {
        $pageRenderer ??= new SugarPageRenderer(
            templatePath: $config->templatePath(),
            cachePath: $config->cachePath(),
            template: $config->pageTemplate,
            debug: $debug,
        );

        $htmlContent = $this->djotRenderer->render($page->source);

        return $pageRenderer->render([
            'title' => $page->title,
            'url' => $page->urlPath,
            'content' => $htmlContent,
            'page' => $page,
            'meta' => $page->meta,
        ]);
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
