<?php
declare(strict_types=1);

namespace Glaze\Extension;

use Glaze\Build\Event\BuildCompletedEvent;
use Glaze\Build\Event\BuildEvent;
use Glaze\Build\Event\ContentDiscoveredEvent;
use Glaze\Build\Event\PageRenderedEvent;
use Glaze\Content\ContentPage;
use Glaze\Support\ResourcePathRewriter;
use Glaze\Template\Extension\ConfigurableExtension;
use Glaze\Template\Extension\GlazeExtension;
use Glaze\Template\Extension\ListensTo;

/**
 * Generates a MiniSearch-compatible JSON search index after a successful build.
 *
 * Each built page is represented as a document object with `id`, `title`, `url`,
 * `description`, and `content` fields. The resulting JSON array can be loaded
 * client-side and fed to MiniSearch via `addAllAsync()`.
 *
 * Opt in via `glaze.neon`:
 * ```neon
 * extensions:
 *     - search-index
 * ```
 *
 * Optional configuration:
 * ```neon
 * extensions:
 *     search-index:
 *         filename: search-index.json
 *         exclude:
 *             - /drafts
 * ```
 *
 * Client-side usage example:
 * ```js
 * import MiniSearch from 'minisearch'
 *
 * const response = await fetch('/search-index.json')
 * const documents = await response.json()
 *
 * const search = new MiniSearch({
 *     fields: ['title', 'description', 'content'],
 *     storeFields: ['title', 'url', 'description'],
 * })
 *
 * await search.addAllAsync(documents)
 * ```
 */
#[GlazeExtension('search-index')]
final class SearchIndexExtension implements ConfigurableExtension
{
    /**
     * Collected search index documents keyed by URL path for deduplication.
     *
     * @var array<string, array{id: int, title: string, description: string, url: string, content: string}>
     */
    protected array $documents = [];

    /**
     * Auto-increment counter used to assign sequential document IDs.
     */
    protected int $nextId = 1;

    /**
     * Constructor.
     *
     * @param string $filename Output filename written under the build output directory.
     * @param list<string> $exclude URL path prefixes whose pages are excluded from the index.
     * @param \Glaze\Support\ResourcePathRewriter $pathRewriter Shared URL path rewriter for basePath application.
     */
    public function __construct(
        protected readonly string $filename = 'search-index.json',
        protected readonly array $exclude = [],
        protected readonly ResourcePathRewriter $pathRewriter = new ResourcePathRewriter(),
    ) {
    }

    /**
     * Create an instance from configuration options.
     *
     * Accepted keys: `filename` (string), `exclude` (list of URL-path prefix strings).
     *
     * @param array<string, mixed> $options Per-extension option map from `glaze.neon`.
     */
    public static function fromConfig(array $options): static
    {
        $rawFilename = $options['filename'] ?? null;
        $filename = is_string($rawFilename) && trim($rawFilename) !== ''
            ? trim($rawFilename)
            : 'search-index.json';

        $exclude = [];
        if (is_array($options['exclude'] ?? null)) {
            foreach ($options['exclude'] as $prefix) {
                if (is_string($prefix) && $prefix !== '') {
                    $exclude[] = $prefix;
                }
            }
        }

        return new self($filename, $exclude);
    }

    /**
     * Register the search index virtual page so it appears in build progress output.
     *
     * @param \Glaze\Build\Event\ContentDiscoveredEvent $event Event payload.
     */
    #[ListensTo(BuildEvent::ContentDiscovered)]
    public function registerVirtualPage(ContentDiscoveredEvent $event): void
    {
        $event->pages[] = ContentPage::virtual(
            '/' . ltrim($this->filename, '/'),
            $this->filename,
            'Search index',
        );
    }

    /**
     * Capture and index rendered page HTML as a search document.
     *
     * Pages whose URL path starts with one of the configured `exclude` prefixes
     * are skipped. Content is extracted by stripping all HTML tags from the
     * rendered output and normalising whitespace.
     *
     * @param \Glaze\Build\Event\PageRenderedEvent $event Event payload.
     */
    #[ListensTo(BuildEvent::PageRendered)]
    public function collect(PageRenderedEvent $event): void
    {
        if ($event->page->virtual) {
            return;
        }

        foreach ($this->exclude as $prefix) {
            if (str_starts_with($event->page->urlPath, $prefix)) {
                return;
            }
        }

        $this->documents[$event->page->urlPath] = [
            'id' => $this->nextId++,
            'title' => $event->page->title,
            'description' => $this->resolveDescription($event),
            'url' => $this->resolveDocumentUrl($event),
            'content' => $this->extractContent($event->html),
        ];
    }

    /**
     * Resolve the document URL used by client-side search results.
     *
     * Applies the configured site `basePath` when present so generated links
     * remain valid when the site is hosted under a sub-path.
     *
     * @param \Glaze\Build\Event\PageRenderedEvent $event Event payload.
     */
    protected function resolveDocumentUrl(PageRenderedEvent $event): string
    {
        return $this->pathRewriter->applyBasePathToPath($event->page->urlPath, $event->config->site);
    }

    /**
     * Write the search index JSON file to the build output directory.
     *
     * Documents are sorted by URL path for stable output across builds.
     *
     * @param \Glaze\Build\Event\BuildCompletedEvent $event Event payload.
     */
    #[ListensTo(BuildEvent::BuildCompleted)]
    public function write(BuildCompletedEvent $event): void
    {
        $documents = array_values($this->documents);
        usort(
            $documents,
            static fn(array $left, array $right): int => strcmp($left['url'], $right['url']),
        );

        $json = json_encode($documents, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

        file_put_contents(
            $event->config->outputPath() . '/' . ltrim($this->filename, '/'),
            $json . PHP_EOL,
        );
    }

    /**
     * Resolve the page description from frontmatter, checking common field names.
     *
     * Falls back to an empty string when no description-like field is present.
     *
     * @param \Glaze\Build\Event\PageRenderedEvent $event Event payload.
     */
    protected function resolveDescription(PageRenderedEvent $event): string
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

        return '';
    }

    /**
     * Extract searchable plain text from rendered HTML.
     *
     * Strips all HTML tags, decodes common HTML entities, and collapses
     * whitespace runs to single spaces.
     *
     * @param string $html Full rendered HTML output from the template.
     */
    protected function extractContent(string $html): string
    {
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return trim($text);
    }
}
