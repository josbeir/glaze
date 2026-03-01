<?php
declare(strict_types=1);

namespace Docs\Extensions;

use DateTimeImmutable;
use Glaze\Build\Event\BuildCompletedEvent;
use Glaze\Build\Event\BuildEvent;
use Glaze\Build\Event\ContentDiscoveredEvent;
use Glaze\Build\Event\PageWrittenEvent;
use Glaze\Content\ContentPage;
use Glaze\Template\Extension\GlazeExtension;
use Glaze\Template\Extension\ListensTo;
use SimpleXMLElement;

/**
 * Generates a simple sitemap.xml file after a successful build.
 *
 * The extension collects page URLs as pages are written and emits a sitemap
 * document in the project's output directory using SimpleXML.
 */
#[GlazeExtension]
final class SitemapExtension
{
    /**
     * Collected sitemap entries keyed by URL path.
     *
     * @var array<string, array{path: string, lastmod: string|null}>
     */
    protected array $entries = [];

    /**
     * Register the sitemap.xml virtual page so it appears in build progress output.
     *
     * @param \Glaze\Build\Event\ContentDiscoveredEvent $event Event payload.
     */
    #[ListensTo(BuildEvent::ContentDiscovered)]
    public function registerVirtualPage(ContentDiscoveredEvent $event): void
    {
        $event->pages[] = ContentPage::virtual('/sitemap.xml', 'sitemap.xml', 'Sitemap');
    }

    /**
     * Collect URL paths for written pages.
     *
     * @param \Glaze\Build\Event\PageWrittenEvent $event Event payload.
     */
    #[ListensTo(BuildEvent::PageWritten)]
    public function collect(PageWrittenEvent $event): void
    {
        $this->entries[$event->page->urlPath] = [
            'path' => $event->page->urlPath,
            'lastmod' => $this->resolveLastModified($event),
        ];
    }

    /**
     * Write sitemap.xml to the current build output directory.
     *
     * @param \Glaze\Build\Event\BuildCompletedEvent $event Event payload.
     */
    #[ListensTo(BuildEvent::BuildCompleted)]
    public function write(BuildCompletedEvent $event): void
    {
        $entries = array_values($this->entries);
        usort($entries, static fn(array $left, array $right): int => strcmp($left['path'], $right['path']));

        $fallbackLastModified = gmdate(DATE_ATOM);
        $urls = [];
        foreach ($entries as $entry) {
            $urls[] = [
                'loc' => $this->absoluteUrl($entry['path'], $event),
                'lastmod' => $entry['lastmod'] ?? $fallbackLastModified,
            ];
        }

        $xml = $this->buildSitemapXml($urls);
        file_put_contents($event->config->outputPath() . '/sitemap.xml', $xml);
    }

    /**
     * Resolve page last-modified timestamp for sitemap metadata.
     *
     * Resolution order:
     * 1. Frontmatter keys: `lastmod`, `lastModified`, `updatedAt`, `date`
     * 2. Source file modification time
     * 3. `null` (caller provides fallback)
     *
     * @param \Glaze\Build\Event\PageWrittenEvent $event Event payload.
     */
    protected function resolveLastModified(PageWrittenEvent $event): ?string
    {
        foreach (['lastmod', 'lastModified', 'updatedAt', 'date'] as $key) {
            $value = $event->page->meta[$key] ?? null;
            if (!is_string($value)) {
                continue;
            }

            $normalized = $this->normalizeDate($value);
            if (is_string($normalized)) {
                return $normalized;
            }
        }

        if (is_file($event->page->sourcePath)) {
            $timestamp = filemtime($event->page->sourcePath);
            if (is_int($timestamp) && $timestamp > 0) {
                return gmdate(DATE_ATOM, $timestamp);
            }
        }

        return null;
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
     * Create a minimal XML sitemap payload.
     *
     * @param array<int, array{loc: string, lastmod: string}> $urls URL and lastmod records.
     */
    protected function buildSitemapXml(array $urls): string
    {
        $root = new SimpleXMLElement('<urlset/>');
        $root->addAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');

        foreach ($urls as $url) {
            $urlElement = $root->addChild('url');
            $urlElement->addChild('loc', $url['loc']);
            $urlElement->addChild('lastmod', $url['lastmod']);
        }

        $xml = $root->asXML();

        return is_string($xml) ? $xml : '';
    }

    /**
     * Normalize an arbitrary date string to ISO-8601 format.
     *
     * @param string $value Raw date value.
     */
    protected function normalizeDate(string $value): ?string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($trimmed))->format(DATE_ATOM);
        } catch (\Throwable) {
            return null;
        }
    }
}
