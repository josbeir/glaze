<?php
declare(strict_types=1);

namespace Glaze\Extension;

use DateTimeImmutable;
use DateTimeInterface;
use DOMDocument;
use DOMElement;
use Glaze\Build\Enum\BuildEvent;
use Glaze\Build\Event\BuildCompletedEvent;
use Glaze\Build\Event\ContentDiscoveredEvent;
use Glaze\Build\Event\PageWrittenEvent;
use Glaze\Content\ContentPage;
use Glaze\Support\ResourcePathRewriter;
use Glaze\Template\Extension\ConfigurableExtension;
use Glaze\Template\Extension\GlazeExtension;
use Glaze\Template\Extension\ListensTo;
use Throwable;

/**
 * Generates an RSS 2.0 feed file after a successful build.
 *
 * The feed lists built pages as `<item>` entries with title, link, description,
 * publication date, and a permalink `<guid>`. The channel metadata is resolved
 * from `site.title` and `site.description` in `glaze.neon` and can be overridden
 * per-extension.
 *
 * When `types` is configured, only pages whose content type matches one of the
 * listed values are included. When omitted, all non-excluded pages are included.
 *
 * Items are sorted newest-first by publication date. Pages with no resolvable
 * date appear at the end in URL-path order.
 *
 * Opt in via `glaze.neon`:
 * ```neon
 * extensions:
 *     - rss
 * ```
 *
 * Optional configuration:
 * ```neon
 * extensions:
 *     rss:
 *         filename: feed.xml
 *         title: My Blog
 *         description: Latest posts from My Blog.
 *         types:
 *             - blog
 *             - news
 *         exclude:
 *             - /drafts
 * ```
 */
#[GlazeExtension('rss')]
final class RssFeedExtension implements ConfigurableExtension
{
    /**
     * Collected feed entries keyed by URL path for uniqueness.
     *
     * @var array<string, array{title: string, urlPath: string, description: string, pubDate: string|null, timestamp: int|null, type: string|null}>
     */
    protected array $entries = [];

    /**
     * Constructor.
     *
     * @param string $filename Output filename written in the build output directory.
     * @param string|null $title Overrides the feed channel `<title>`. Falls back to `site.title`.
     * @param string|null $description Overrides the channel `<description>`. Falls back to `site.description`.
     * @param list<string> $types Content type names to include. When empty, all pages are included.
     * @param list<string> $exclude URL path prefixes whose pages are excluded from the feed.
     * @param \Glaze\Support\ResourcePathRewriter $pathRewriter URL rewriter for basePath application.
     */
    public function __construct(
        protected readonly string $filename = 'feed.xml',
        protected readonly ?string $title = null,
        protected readonly ?string $description = null,
        protected readonly array $types = [],
        protected readonly array $exclude = [],
        protected readonly ResourcePathRewriter $pathRewriter = new ResourcePathRewriter(),
    ) {
    }

    /**
     * Create an instance from configuration options.
     *
     * Accepted keys: `filename` (string), `title` (string), `description` (string),
     * `types` (list of content type name strings), `exclude` (list of URL-path prefix strings).
     *
     * @param array<string, mixed> $options Per-extension option map from `glaze.neon`.
     */
    public static function fromConfig(array $options): static
    {
        $rawFilename = $options['filename'] ?? null;
        $filename = is_string($rawFilename) && trim($rawFilename) !== ''
            ? trim($rawFilename)
            : 'feed.xml';

        $rawTitle = $options['title'] ?? null;
        $title = is_string($rawTitle) ? (trim($rawTitle) ?: null) : null;

        $rawDescription = $options['description'] ?? null;
        $description = is_string($rawDescription) ? (trim($rawDescription) ?: null) : null;

        $types = [];
        if (is_array($options['types'] ?? null)) {
            foreach ($options['types'] as $type) {
                if (is_string($type) && $type !== '') {
                    $types[] = $type;
                }
            }
        }

        $exclude = [];
        if (is_array($options['exclude'] ?? null)) {
            foreach ($options['exclude'] as $prefix) {
                if (is_string($prefix) && $prefix !== '') {
                    $exclude[] = $prefix;
                }
            }
        }

        return new self($filename, $title, $description, $types, $exclude);
    }

    /**
     * Register the feed virtual page so it appears in build progress output.
     *
     * @param \Glaze\Build\Event\ContentDiscoveredEvent $event Event payload.
     */
    #[ListensTo(BuildEvent::ContentDiscovered)]
    public function registerVirtualPage(ContentDiscoveredEvent $event): void
    {
        $event->pages[] = ContentPage::virtual(
            '/' . ltrim($this->filename, '/'),
            $this->filename,
            'RSS feed',
        );
    }

    /**
     * Collect page metadata as each page is written.
     *
     * Virtual and unlisted pages are skipped. Pages whose URL path starts with
     * one of the configured `exclude` prefixes are skipped. When `types` is
     * non-empty, only pages whose resolved content type is in that list are included.
     *
     * @param \Glaze\Build\Event\PageWrittenEvent $event Event payload.
     */
    #[ListensTo(BuildEvent::PageWritten)]
    public function collect(PageWrittenEvent $event): void
    {
        if ($event->page->virtual || $event->page->unlisted) {
            return;
        }

        foreach ($this->exclude as $prefix) {
            if (str_starts_with($event->page->urlPath, $prefix)) {
                return;
            }
        }

        if ($this->types !== [] && !in_array($event->page->type, $this->types, true)) {
            return;
        }

        [$pubDate, $timestamp] = $this->resolveItemDate($event);

        $this->entries[$event->page->urlPath] = [
            'title' => $event->page->title,
            'urlPath' => $event->page->urlPath,
            'description' => $this->resolveDescription($event),
            'pubDate' => $pubDate,
            'timestamp' => $timestamp,
            'type' => $event->page->type,
        ];
    }

    /**
     * Write the RSS feed XML file to the build output directory.
     *
     * Items are sorted newest-first by resolved publication date. Items with no
     * date appear last, ordered by URL path.
     *
     * @param \Glaze\Build\Event\BuildCompletedEvent $event Event payload.
     */
    #[ListensTo(BuildEvent::BuildCompleted)]
    public function write(BuildCompletedEvent $event): void
    {
        $entries = array_values($this->entries);

        usort($entries, static function (array $left, array $right): int {
            if ($left['timestamp'] !== null && $right['timestamp'] !== null) {
                return $right['timestamp'] <=> $left['timestamp'];
            }

            if ($left['timestamp'] !== null) {
                return -1;
            }

            if ($right['timestamp'] !== null) {
                return 1;
            }

            return strcmp($left['urlPath'], $right['urlPath']);
        });

        $xml = $this->buildFeedXml($entries, $event);

        file_put_contents(
            $event->config->outputPath() . '/' . ltrim($this->filename, '/'),
            $xml,
        );
    }

    /**
     * Build an absolute URL for a page path using the configured site base path and base URL.
     *
     * @param string $path Page URL path.
     * @param \Glaze\Build\Event\BuildCompletedEvent $event Build context.
     */
    protected function absoluteUrl(string $path, BuildCompletedEvent $event): string
    {
        $withBasePath = $this->pathRewriter->applyBasePathToPath(
            $path === '' ? '/' : $path,
            $event->config->site,
        );

        $baseUrl = $event->config->site->baseUrl;
        if (!is_string($baseUrl) || trim($baseUrl) === '') {
            return $withBasePath;
        }

        return rtrim($baseUrl, '/') . $withBasePath;
    }

    /**
     * Resolve the item publication date from frontmatter.
     *
     * Checks `date`, `pubDate`, and `publishedAt` in order. Handles both
     * `DateTimeInterface` instances (as emitted by the NEON parser) and raw
     * date strings. Returns a `[formatted RFC-2822 date, unix timestamp]` pair,
     * or `[null, null]` when no resolvable date is found.
     *
     * @param \Glaze\Build\Event\PageWrittenEvent $event Event payload.
     * @return array{0: string|null, 1: int|null}
     */
    protected function resolveItemDate(PageWrittenEvent $event): array
    {
        foreach (['date', 'pubDate', 'publishedAt'] as $key) {
            $value = $event->page->meta[$key] ?? null;

            if ($value instanceof DateTimeInterface) {
                return [$value->format(DATE_RSS), $value->getTimestamp()];
            }

            if (is_string($value) && trim($value) !== '') {
                try {
                    $dt = new DateTimeImmutable($value);

                    return [$dt->format(DATE_RSS), $dt->getTimestamp()];
                } catch (Throwable) {
                    continue;
                }
            }
        }

        return [null, null];
    }

    /**
     * Resolve the item description from page frontmatter.
     *
     * Checks `description`, `summary`, and `excerpt` in order. Returns an empty
     * string when none are present.
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

        return '';
    }

    /**
     * Build the RSS 2.0 XML document from collected entries.
     *
     * Emits a standard RSS 2.0 `<channel>` with an `atom:link` self-referential
     * element for feed reader compatibility. Each entry becomes an `<item>` with
     * `<title>`, `<link>`, `<description>`, `<guid>`, and `<pubDate>` (when available).
     *
     * @param array<array{title: string, urlPath: string, description: string, pubDate: string|null, timestamp: int|null, type: string|null}> $entries Sorted feed entries.
     * @param \Glaze\Build\Event\BuildCompletedEvent $event Build context.
     */
    protected function buildFeedXml(array $entries, BuildCompletedEvent $event): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->formatOutput = true;

        $rss = $dom->createElement('rss');
        $rss->setAttribute('version', '2.0');
        $rss->setAttribute('xmlns:atom', 'http://www.w3.org/2005/Atom');

        $dom->appendChild($rss);

        $channel = $dom->createElement('channel');
        $rss->appendChild($channel);

        $this->appendTextElement($dom, $channel, 'title', $this->title ?? $event->config->site->title ?? '');
        $this->appendTextElement($dom, $channel, 'link', $this->absoluteUrl('/', $event));
        $channelDescription = $this->description ?? $event->config->site->description ?? '';
        $this->appendTextElement($dom, $channel, 'description', $channelDescription);
        $this->appendTextElement($dom, $channel, 'lastBuildDate', gmdate(DATE_RSS));
        $this->appendTextElement($dom, $channel, 'generator', 'Glaze');

        $atomLink = $dom->createElementNS('http://www.w3.org/2005/Atom', 'atom:link');
        $atomLink->setAttribute('href', $this->absoluteUrl('/' . ltrim($this->filename, '/'), $event));
        $atomLink->setAttribute('rel', 'self');
        $atomLink->setAttribute('type', 'application/rss+xml');

        $channel->appendChild($atomLink);

        foreach ($entries as $entry) {
            $item = $dom->createElement('item');
            $channel->appendChild($item);

            $this->appendCdataElement($dom, $item, 'title', $entry['title']);
            $this->appendTextElement($dom, $item, 'link', $this->absoluteUrl($entry['urlPath'], $event));
            $this->appendCdataElement($dom, $item, 'description', $entry['description']);

            if ($entry['pubDate'] !== null) {
                $this->appendTextElement($dom, $item, 'pubDate', $entry['pubDate']);
            }

            $guid = $this->appendTextElement($dom, $item, 'guid', $this->absoluteUrl($entry['urlPath'], $event));
            $guid->setAttribute('isPermaLink', 'true');
        }

        return $dom->saveXML() ?: '';
    }

    /**
     * Append a child element containing a plain text node.
     *
     * @param \DOMDocument $dom Owner document.
     * @param \DOMElement $parent Parent element.
     * @param string $name Tag name.
     * @param string $value Text content.
     */
    protected function appendTextElement(DOMDocument $dom, DOMElement $parent, string $name, string $value): DOMElement
    {
        $el = $dom->createElement($name);
        $el->appendChild($dom->createTextNode($value));

        $parent->appendChild($el);

        return $el;
    }

    /**
     * Append a child element containing a CDATA section.
     *
     * Used for fields that may contain special characters or HTML snippets
     * (title, description) to avoid XML entity escaping.
     *
     * @param \DOMDocument $dom Owner document.
     * @param \DOMElement $parent Parent element.
     * @param string $name Tag name.
     * @param string $value Raw text content.
     */
    protected function appendCdataElement(DOMDocument $dom, DOMElement $parent, string $name, string $value): DOMElement
    {
        $el = $dom->createElement($name);
        $el->appendChild($dom->createCDATASection($value));

        $parent->appendChild($el);

        return $el;
    }
}
