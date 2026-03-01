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
     * @var array<string, array{title: string, description: string, urlPath: string, source: string}>
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
            'source' => $event->page->source,
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

        $documentationPages = $pages;

        $lines = [];
        $lines[] = '# ' . ($event->config->site->title ?? 'Glaze Site');
        $lines[] = '';
        $lines[] = '> ' . $this->resolveProjectPitch($event, $documentationPages);
        $lines[] = '';
        $lines[] = $this->resolveProjectContext($event, $documentationPages);
        $lines[] = '';

        $lines[] = '## Documentation';
        if ($documentationPages === []) {
            $lines[] = '- No documentation pages found.';
        } else {
            foreach ($documentationPages as $page) {
                $lines[] = $this->formatPageEntry($page, $event);
            }
        }

        if (is_file($event->config->outputPath() . '/llms-full.txt')) {
            $lines[] = '';
            $lines[] = '## Optional: llms-full.txt';
            $lines[] = '- [Full Documentation]('
                . $this->absoluteUrl('/llms-full.txt', $event)
                . '): A single file containing all content for deep context.';
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

    /**
     * Resolve a one-sentence project pitch for the llms.txt header quote.
     *
     * Reads `site.meta.llmsPitch` when configured and falls back to the site
     * description or a generic project sentence.
     *
     * @param \Glaze\Build\Event\BuildCompletedEvent $event Build context.
     * @param array<array{title: string, description: string, urlPath: string, source: string}> $documentationPages Documentation pages.
     */
    protected function resolveProjectPitch(BuildCompletedEvent $event, array $documentationPages): string
    {
        $pitch = $event->config->site->siteMeta('llmsPitch');
        if (is_string($pitch) && trim($pitch) !== '') {
            return trim($pitch);
        }

        if (is_string($event->config->site->description) && trim($event->config->site->description) !== '') {
            return trim($event->config->site->description);
        }

        $bestDescription = $this->bestDescription($documentationPages);
        if ($bestDescription !== null) {
            return $bestDescription;
        }

        return 'Documentation index for ' . ($event->config->site->title ?? 'this project') . '.';
    }

    /**
     * Resolve the contextual project paragraph for llms.txt.
     *
     * Reads `site.meta.llmsContext` when configured and otherwise provides a
     * default explanation of architecture and intended use.
     *
     * @param \Glaze\Build\Event\BuildCompletedEvent $event Build context.
     * @param array<array{title: string, description: string, urlPath: string, source: string}> $documentationPages Documentation pages.
     */
    protected function resolveProjectContext(BuildCompletedEvent $event, array $documentationPages): string
    {
        $context = $event->config->site->siteMeta('llmsContext');
        if (is_string($context) && trim($context) !== '') {
            return trim($context);
        }

        $descriptionSegments = [];
        foreach ($documentationPages as $page) {
            $description = trim($page['description']);
            if ($description === '') {
                continue;
            }

            if ($description === 'No description provided.') {
                continue;
            }

            $descriptionSegments[] = $description;
            if (count($descriptionSegments) >= 3) {
                break;
            }
        }

        if ($descriptionSegments !== []) {
            return implode(' ', $descriptionSegments);
        }

        $sourceSummary = $this->bestSourceSummary($documentationPages);
        if ($sourceSummary !== null) {
            return $sourceSummary;
        }

        return 'Use the documentation links below for architecture, usage, and implementation details.';
    }

    /**
     * Format a page as a structured markdown list entry.
     *
     * @param array{title: string, description: string, urlPath: string, source: string} $page Page data.
     * @param \Glaze\Build\Event\BuildCompletedEvent $event Build context.
     */
    protected function formatPageEntry(array $page, BuildCompletedEvent $event): string
    {
        return '- ['
            . $page['title']
            . ']('
            . $this->absoluteUrl($page['urlPath'], $event)
            . '): '
            . $page['description'];
    }

    /**
     * Resolve the best available page description for header pitch fallback.
     *
     * @param array<array{title: string, description: string, urlPath: string, source: string}> $pages Candidate pages.
     */
    protected function bestDescription(array $pages): ?string
    {
        foreach ($pages as $page) {
            $description = trim($page['description']);
            if ($description === '') {
                continue;
            }

            if ($description === 'No description provided.') {
                continue;
            }

            return $description;
        }

        return null;
    }

    /**
     * Resolve a compact contextual summary from page source content.
     *
     * @param array<array{title: string, description: string, urlPath: string, source: string}> $pages Candidate pages.
     */
    protected function bestSourceSummary(array $pages): ?string
    {
        foreach ($pages as $page) {
            $source = trim(preg_replace('/\s+/', ' ', $page['source']) ?? '');
            if ($source === '') {
                continue;
            }

            $summary = mb_substr($source, 0, 260);
            if ($summary === '') {
                continue;
            }

            return rtrim($summary, ' .,;:') . '.';
        }

        return null;
    }
}
