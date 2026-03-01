<?php
declare(strict_types=1);

namespace Docs\Extensions;

use Glaze\Build\Event\BuildCompletedEvent;
use Glaze\Build\Event\BuildEvent;
use Glaze\Build\Event\ContentDiscoveredEvent;
use Glaze\Build\Event\PageWrittenEvent;
use Glaze\Content\ContentPage;
use Glaze\Template\Extension\GlazeExtension;
use Glaze\Template\Extension\ListensTo;

/**
 * Generates a simple llms.txt file listing all built pages.
 *
 * The generated file is intended as a lightweight machine-readable index for
 * LLM ingestion workflows and includes page titles, descriptions, and
 * canonical URLs.
 */
#[GlazeExtension]
final class LlmsTxtExtension
{
    /**
     * Collected pages keyed by URL path for uniqueness.
     *
     * @var array<string, array{title: string, description: string, urlPath: string}>
     */
    protected array $pages = [];

    /**
     * Register the llms.txt virtual page so it appears in build progress output.
     *
     * @param \Glaze\Build\Event\ContentDiscoveredEvent $event Event payload.
     */
    #[ListensTo(BuildEvent::ContentDiscovered)]
    public function registerVirtualPage(ContentDiscoveredEvent $event): void
    {
        $event->pages[] = ContentPage::virtual('/llms.txt', 'llms.txt', 'LLMs index');
    }

    /**
     * Collect page metadata as each page is written.
     *
     * @param \Glaze\Build\Event\PageWrittenEvent $event Event payload.
     */
    #[ListensTo(BuildEvent::PageWritten)]
    public function collect(PageWrittenEvent $event): void
    {
        $this->pages[$event->page->urlPath] = [
            'title' => $event->page->title,
            'description' => $this->resolveDescription($event),
            'urlPath' => $event->page->urlPath,
        ];
    }

    /**
     * Write llms.txt to the current build output directory.
     *
     * @param \Glaze\Build\Event\BuildCompletedEvent $event Event payload.
     */
    #[ListensTo(BuildEvent::BuildCompleted)]
    public function write(BuildCompletedEvent $event): void
    {
        $pages = array_values($this->pages);
        usort($pages, static fn(array $left, array $right): int => strcmp($left['urlPath'], $right['urlPath']));

        $lines = [];
        $title = $event->config->site->title ?? 'Glaze Site';
        $lines[] = '# ' . $title;

        if (is_string($event->config->site->description) && trim($event->config->site->description) !== '') {
            $lines[] = '';
            $lines[] = $event->config->site->description;
        }

        $lines[] = '';
        $lines[] = '## Pages';

        foreach ($pages as $page) {
            $lines[] = '- ' . $page['title']
                . ': ' . $this->absoluteUrl($page['urlPath'], $event)
                . ' — ' . $page['description'];
        }

        file_put_contents(
            $event->config->outputPath() . '/llms.txt',
            implode(PHP_EOL, $lines) . PHP_EOL,
        );
    }

    /**
     * Build an absolute URL for a page path using site base URL and base path.
     *
     * @param string $path Page URL path.
     * @param \Glaze\Build\Event\BuildCompletedEvent $event Build context.
     */
    protected function absoluteUrl(string $path, BuildCompletedEvent $event): string
    {
        $normalizedPath = $path === '' ? '/' : $path;
        if ($normalizedPath[0] !== '/') {
            $normalizedPath = '/' . $normalizedPath;
        }

        $basePath = $event->config->site->basePath;
        if (is_string($basePath) && $basePath !== '' && !str_starts_with($normalizedPath, $basePath . '/')) {
            if ($normalizedPath === '/') {
                $normalizedPath = $basePath . '/';
            } else {
                $normalizedPath = $basePath . $normalizedPath;
            }
        }

        $baseUrl = $event->config->site->baseUrl;
        if (!is_string($baseUrl) || trim($baseUrl) === '') {
            return $normalizedPath;
        }

        return rtrim($baseUrl, '/') . $normalizedPath;
    }

    /**
     * Resolve the best-effort description for a page.
     *
     * @param \Glaze\Build\Event\PageWrittenEvent $event Event payload.
     */
    protected function resolveDescription(PageWrittenEvent $event): string
    {
        foreach (['description', 'summary', 'excerpt'] as $key) {
            $value = $event->page->meta[$key] ?? null;
            if (!is_string($value)) {
                continue;
            }

            $normalized = trim(preg_replace('/\s+/', ' ', $value) ?? '');
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return 'No description provided.';
    }
}
