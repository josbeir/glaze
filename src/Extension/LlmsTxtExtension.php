<?php
declare(strict_types=1);

namespace Glaze\Extension;

use Glaze\Build\Event\BuildCompletedEvent;
use Glaze\Build\Event\BuildEvent;
use Glaze\Build\Event\ContentDiscoveredEvent;
use Glaze\Build\Event\PageWrittenEvent;
use Glaze\Content\ContentPage;
use Glaze\Template\Extension\ConfigurableExtension;
use Glaze\Template\Extension\GlazeExtension;
use Glaze\Template\Extension\ListensTo;

/**
 * Generates a simple llms.txt file listing all built pages.
 *
 * The generated file is intended as a lightweight machine-readable index for
 * LLM ingestion workflows and includes page titles, descriptions, and
 * canonical URLs.
 *
 * Opt in via `glaze.neon`:
 * ```neon
 * extensions:
 *     - llms-txt
 * ```
 *
 * Optional configuration:
 * ```neon
 * extensions:
 *     llms-txt:
 *         title: My Project
 *         pitch: A static site generator for PHP developers.
 *         context: Use the links below for full documentation.
 *         exclude:
 *             - /drafts
 * ```
 */
#[GlazeExtension('llms-txt')]
final class LlmsTxtExtension implements ConfigurableExtension
{
    /**
     * Collected pages keyed by URL path for uniqueness.
     *
     * @var array<string, array{title: string, description: string, urlPath: string, source: string}>
     */
    protected array $pages = [];

    /**
     * Constructor.
     *
     * @param string|null $title Override the `# heading` line (falls back to `site.title`).
     * @param string|null $pitch Override the `> quote` line (falls back to `site.description`).
     * @param string|null $context Override the context paragraph (omits auto-resolved text when set).
     * @param list<string> $exclude URL path prefixes whose pages are excluded from the listing.
     */
    public function __construct(
        protected readonly ?string $title = null,
        protected readonly ?string $pitch = null,
        protected readonly ?string $context = null,
        protected readonly array $exclude = [],
    ) {
    }

    /**
     * Create an instance from configuration options.
     *
     * Accepted keys: `title` (string), `pitch` (string), `context` (string),
     * `exclude` (list of URL-path prefix strings).
     *
     * @param array<string, mixed> $options Per-extension option map from `glaze.neon`.
     */
    public static function fromConfig(array $options): static
    {
        $rawTitle = $options['title'] ?? null;
        $title = is_string($rawTitle) ? (trim($rawTitle) ?: null) : null;
        $rawPitch = $options['pitch'] ?? null;
        $pitch = is_string($rawPitch) ? (trim($rawPitch) ?: null) : null;
        $rawContext = $options['context'] ?? null;
        $context = is_string($rawContext) ? (trim($rawContext) ?: null) : null;

        $exclude = [];
        if (is_array($options['exclude'] ?? null)) {
            foreach ($options['exclude'] as $prefix) {
                if (is_string($prefix) && $prefix !== '') {
                    $exclude[] = $prefix;
                }
            }
        }

        return new self($title, $pitch, $context, $exclude);
    }

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
     * Pages whose URL path starts with one of the configured `exclude` prefixes are skipped.
     *
     * @param \Glaze\Build\Event\PageWrittenEvent $event Event payload.
     */
    #[ListensTo(BuildEvent::PageWritten)]
    public function collect(PageWrittenEvent $event): void
    {
        foreach ($this->exclude as $prefix) {
            if (str_starts_with($event->page->urlPath, $prefix)) {
                return;
            }
        }

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
        $lines[] = '# ' . ($this->title ?? $event->config->site->title ?? 'Glaze Site');
        $lines[] = '';
        $lines[] = '> ' . ($this->pitch ?? $this->resolveProjectPitch($event, $documentationPages));
        $lines[] = '';
        $lines[] = $this->context ?? $this->resolveProjectContext($event, $documentationPages);
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
     * Resolve a brief source-based fallback context sentence.
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
