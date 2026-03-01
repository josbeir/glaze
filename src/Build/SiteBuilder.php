<?php
declare(strict_types=1);

namespace Glaze\Build;

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
use Glaze\Render\PageRenderPipeline;
use Glaze\Template\ContentAssetResolver;
use Glaze\Template\Extension\ExtensionLoader;
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
     * @param \Glaze\Render\PageRenderPipeline $pageRenderPipeline Shared page render pipeline.
     * @param \Glaze\Build\ContentAssetPublisher $assetPublisher Asset publishing service.
     */
    public function __construct(
        protected ContentDiscoveryService $discoveryService,
        protected PageRenderPipeline $pageRenderPipeline,
        protected ContentAssetPublisher $assetPublisher,
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

        $assetResolver = new ContentAssetResolver($config->contentPath(), $config->site->basePath);
        $siteIndex = new SiteIndex($pages, $assetResolver);
        $dispatcher = new EventDispatcher();
        $extensionRegistry = ExtensionLoader::load($config, $dispatcher);

        return $this->pageRenderPipeline->render(
            config: $config,
            page: $page,
            pageTemplate: $this->resolvePageTemplate($page, $config),
            debug: true,
            siteIndex: $siteIndex,
            extensionRegistry: $extensionRegistry,
            dispatcher: $dispatcher,
        );
    }

    /**
     * Build static pages and write them to disk.
     *
     * @param \Glaze\Config\BuildConfig $config Build configuration.
     * @param bool $cleanOutput Whether to clear output directory before build.
     * @param callable(int, int, string, float):void|null $progressCallback Optional per-page progress callback. Receives completed count, total count, file path or virtual URL, and per-page duration in seconds. Cached and virtual entries report a duration of `0.0`.
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
        $extensionRegistry = ExtensionLoader::load($config, $dispatcher);

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
        $realPages = array_values(array_filter($pages, static fn(ContentPage $p): bool => !$p->virtual));
        $manifestPath = $config->buildManifestPath();
        $currentManifest = BuildManifest::fromBuild($config, $pages);
        $previousManifest = $cleanOutput ? null : BuildManifest::load($manifestPath);
        $fullBuild = $cleanOutput || $currentManifest->requiresFullBuild($previousManifest);
        $changedOutputPaths = [];
        $orphanedPageOutputPaths = [];
        $orphanedContentAssetOutputPaths = [];
        $orphanedStaticAssetOutputPaths = [];
        if (!$fullBuild && $previousManifest instanceof BuildManifest) {
            $changedOutputPaths = $currentManifest->changedPageOutputPaths($previousManifest);
        }

        if ($previousManifest instanceof BuildManifest) {
            $orphanedPageOutputPaths = $currentManifest->orphanedPageOutputPaths($previousManifest);
            $orphanedContentAssetOutputPaths = $currentManifest->orphanedContentAssetOutputPaths($previousManifest);
            $orphanedStaticAssetOutputPaths = $currentManifest->orphanedStaticAssetOutputPaths($previousManifest);
        }

        $assetResolver = new ContentAssetResolver($config->contentPath(), $config->site->basePath);
        $siteIndex = new SiteIndex($realPages, $assetResolver);

        $writtenFiles = [];
        $totalPages = count($pages);
        $completedPages = 0;
        if (is_callable($progressCallback)) {
            $progressCallback($completedPages, $totalPages, '', 0.0);
        }

        foreach ($pages as $page) {
            if ($page->virtual) {
                $completedPages++;
                if (is_callable($progressCallback)) {
                    $progressCallback($completedPages, $totalPages, $page->urlPath, 0.0);
                }

                continue;
            }

            $destination = $config->outputPath() . '/' . $page->outputRelativePath;
            $shouldRender = $fullBuild
                || isset($changedOutputPaths[$page->outputRelativePath])
                || !is_file($destination);

            if (!$shouldRender) {
                $dispatcher->dispatch(BuildEvent::PageWritten, new PageWrittenEvent($page, $destination, $config));
                $completedPages++;

                if (is_callable($progressCallback)) {
                    $progressCallback($completedPages, $totalPages, $destination, 0.0);
                }

                continue;
            }

            $pageStartTime = hrtime(true);

            $html = $this->pageRenderPipeline->render(
                config: $config,
                page: $page,
                pageTemplate: $this->resolvePageTemplate($page, $config),
                debug: false,
                siteIndex: $siteIndex,
                extensionRegistry: $extensionRegistry,
                dispatcher: $dispatcher,
            );

            $renderedEvent = new PageRenderedEvent($page, $html, $config);
            $dispatcher->dispatch(BuildEvent::PageRendered, $renderedEvent);
            $html = $renderedEvent->html;

            $this->writeFile($destination, $html);
            $dispatcher->dispatch(BuildEvent::PageWritten, new PageWrittenEvent($page, $destination, $config));
            $writtenFiles[] = $destination;
            $completedPages++;
            $pageDuration = (float)(hrtime(true) - $pageStartTime) / 1e9;

            if (is_callable($progressCallback)) {
                $progressCallback($completedPages, $totalPages, $destination, $pageDuration);
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

        $this->pruneOrphanedOutputFiles(
            $config->outputPath(),
            array_values(array_unique(array_merge(
                $orphanedPageOutputPaths,
                $orphanedContentAssetOutputPaths,
                $orphanedStaticAssetOutputPaths,
            ))),
        );

        $dispatcher->dispatch(
            BuildEvent::BuildCompleted,
            new BuildCompletedEvent($writtenFiles, $config, (float)(hrtime(true) - $startTime) / 1e9),
        );
        $currentManifest->save($manifestPath);

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
     * Delete output files that no longer map to discovered content pages.
     *
     * @param string $outputPath Absolute output root path.
     * @param array<string> $orphanedOutputPaths Output-relative orphan file paths.
     */
    protected function pruneOrphanedOutputFiles(string $outputPath, array $orphanedOutputPaths): void
    {
        $normalizedOutputPath = rtrim($outputPath, '/');
        foreach ($orphanedOutputPaths as $orphanedOutputPath) {
            if ($orphanedOutputPath === '') {
                continue;
            }

            $absolutePath = $normalizedOutputPath . '/' . ltrim($orphanedOutputPath, '/');
            if (is_file($absolutePath)) {
                unlink($absolutePath);
                $this->removeEmptyParentDirectories(dirname($absolutePath), $normalizedOutputPath);
            }
        }
    }

    /**
     * Remove empty parent directories up to, but not including, output root.
     *
     * @param string $directory Absolute directory path to start from.
     * @param string $outputRoot Absolute output root path.
     */
    protected function removeEmptyParentDirectories(string $directory, string $outputRoot): void
    {
        $normalizedRoot = rtrim($outputRoot, '/');
        $current = rtrim($directory, '/');

        while ($current !== '' && $current !== $normalizedRoot) {
            if (!is_dir($current)) {
                break;
            }

            $entries = scandir($current);
            if (!is_array($entries)) {
                break;
            }

            $children = array_values(array_diff($entries, ['.', '..']));
            if ($children !== []) {
                break;
            }

            rmdir($current);
            $parent = dirname($current);
            if ($parent === $current) {
                break;
            }

            $current = $parent;
        }
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
