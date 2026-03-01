<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Extension;

use DOMDocument;
use Glaze\Build\Event\BuildCompletedEvent;
use Glaze\Build\Event\ContentDiscoveredEvent;
use Glaze\Build\Event\PageWrittenEvent;
use Glaze\Config\BuildConfig;
use Glaze\Config\SiteConfig;
use Glaze\Content\ContentPage;
use Glaze\Extension\SitemapExtension;
use Glaze\Tests\Helper\FilesystemTestTrait;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SitemapExtension including options, exclude filtering and XML output.
 */
final class SitemapExtensionTest extends TestCase
{
    use FilesystemTestTrait;

    // -------------------------------------------------------------------------
    // Construction and fromConfig
    // -------------------------------------------------------------------------

    /**
     * Default construction produces a valid instance.
     */
    public function testDefaultConstructionIsValid(): void
    {
        $ext = new SitemapExtension();

        $this->assertInstanceOf(SitemapExtension::class, $ext);
    }

    /**
     * fromConfig() with an empty array returns a default instance.
     */
    public function testFromConfigWithEmptyOptionsReturnsDefault(): void
    {
        $ext = SitemapExtension::fromConfig([]);

        $this->assertInstanceOf(SitemapExtension::class, $ext);
    }

    /**
     * fromConfig() accepts every valid changefreq value and writes it to XML.
     */
    public function testFromConfigAcceptsAllValidChangefreqValues(): void
    {
        foreach (['always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never'] as $freq) {
            $ext = SitemapExtension::fromConfig(['changefreq' => $freq]);
            $xml = $this->buildXmlFromExt($ext);

            $this->assertStringContainsString(sprintf('<changefreq>%s</changefreq>', $freq), $xml, 'Missing <changefreq> for ' . $freq);
        }
    }

    /**
     * fromConfig() throws InvalidArgumentException when changefreq is unrecognised.
     */
    public function testFromConfigThrowsOnInvalidChangefreq(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid sitemap changefreq "quarterly"');

        SitemapExtension::fromConfig(['changefreq' => 'quarterly']);
    }

    /**
     * fromConfig() writes priority formatted to one decimal place.
     */
    public function testFromConfigAcceptsPriority(): void
    {
        $ext = SitemapExtension::fromConfig(['priority' => 0.8]);
        $xml = $this->buildXmlFromExt($ext);

        $this->assertStringContainsString('<priority>0.8</priority>', $xml);
    }

    /**
     * fromConfig() clamps priority values below 0 to 0.0.
     */
    public function testFromConfigClampsPriorityBelowZero(): void
    {
        $ext = SitemapExtension::fromConfig(['priority' => -1.5]);
        $xml = $this->buildXmlFromExt($ext);

        $this->assertStringContainsString('<priority>0.0</priority>', $xml);
    }

    /**
     * fromConfig() clamps priority values above 1 to 1.0.
     */
    public function testFromConfigClampsPriorityAboveOne(): void
    {
        $ext = SitemapExtension::fromConfig(['priority' => 5.0]);
        $xml = $this->buildXmlFromExt($ext);

        $this->assertStringContainsString('<priority>1.0</priority>', $xml);
    }

    /**
     * fromConfig() ignores exclude entries that are not non-empty strings.
     */
    public function testFromConfigIgnoresInvalidExcludeEntries(): void
    {
        $ext = SitemapExtension::fromConfig(['exclude' => ['', null, 42, '/valid']]);

        [$projectRoot, $config] = $this->makeConfig();
        $ext->collect($this->makePageWrittenEvent('/valid/page', $config));
        $ext->collect($this->makePageWrittenEvent('/other/page', $config));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $xml = (string)file_get_contents($projectRoot . '/public/sitemap.xml');
        $this->assertStringNotContainsString('/valid', $xml);
        $this->assertStringContainsString('/other/page', $xml);
    }

    // -------------------------------------------------------------------------
    // collect() exclude behaviour
    // -------------------------------------------------------------------------

    /**
     * collect() skips pages whose URL path starts with a configured exclude prefix.
     */
    public function testCollectSkipsExcludedPrefixes(): void
    {
        $ext = new SitemapExtension(exclude: ['/private']);

        [$projectRoot, $config] = $this->makeConfig();
        $ext->collect($this->makePageWrittenEvent('/private/secret', $config));
        $ext->collect($this->makePageWrittenEvent('/public/page', $config));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $xml = (string)file_get_contents($projectRoot . '/public/sitemap.xml');
        $this->assertStringNotContainsString('/private', $xml);
        $this->assertStringContainsString('/public/page', $xml);
    }

    /**
     * collect() includes pages that do not match any exclude prefix.
     */
    public function testCollectIncludesNonExcludedPages(): void
    {
        $ext = new SitemapExtension(exclude: ['/drafts']);

        [$projectRoot, $config] = $this->makeConfig();
        $ext->collect($this->makePageWrittenEvent('/blog/post', $config));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $xml = (string)file_get_contents($projectRoot . '/public/sitemap.xml');
        $this->assertStringContainsString('/blog/post', $xml);
    }

    /**
     * collect() with multiple exclude prefixes skips all matching paths.
     */
    public function testCollectRespectsMultipleExcludePrefixes(): void
    {
        $ext = new SitemapExtension(exclude: ['/internal', '/staging']);

        [$projectRoot, $config] = $this->makeConfig();
        $ext->collect($this->makePageWrittenEvent('/internal/notes', $config));
        $ext->collect($this->makePageWrittenEvent('/staging/preview', $config));
        $ext->collect($this->makePageWrittenEvent('/docs/guide', $config));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $xml = (string)file_get_contents($projectRoot . '/public/sitemap.xml');
        $this->assertStringNotContainsString('/internal', $xml);
        $this->assertStringNotContainsString('/staging', $xml);
        $this->assertStringContainsString('/docs/guide', $xml);
    }

    // -------------------------------------------------------------------------
    // write() XML output
    // -------------------------------------------------------------------------

    /**
     * write() emits valid sitemap XML without optional elements when using defaults.
     */
    public function testWriteEmitsValidXmlWithoutOptionalElements(): void
    {
        $ext = new SitemapExtension();

        [$projectRoot, $config] = $this->makeConfig(baseUrl: 'https://example.com');
        $ext->collect($this->makePageWrittenEvent('/page', $config));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $xml = (string)file_get_contents($projectRoot . '/public/sitemap.xml');
        $this->assertStringContainsString('<loc>https://example.com/page</loc>', $xml);
        $this->assertStringNotContainsString('<changefreq>', $xml);
        $this->assertStringNotContainsString('<priority>', $xml);
    }

    /**
     * write() includes changefreq and priority elements when configured.
     */
    public function testWriteIncludesChangefreqAndPriorityWhenConfigured(): void
    {
        $ext = new SitemapExtension(changefreq: 'monthly', priority: 0.7);

        [$projectRoot, $config] = $this->makeConfig(baseUrl: 'https://example.com');
        $ext->collect($this->makePageWrittenEvent('/about', $config));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $xml = (string)file_get_contents($projectRoot . '/public/sitemap.xml');
        $this->assertStringContainsString('<changefreq>monthly</changefreq>', $xml);
        $this->assertStringContainsString('<priority>0.7</priority>', $xml);
    }

    /**
     * write() generates a well-formed XML document with the sitemap namespace.
     */
    public function testWriteOutputIsWellFormedXml(): void
    {
        $ext = new SitemapExtension();

        [$projectRoot, $config] = $this->makeConfig(baseUrl: 'https://example.com');
        $ext->collect($this->makePageWrittenEvent('/home', $config));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $xml = (string)file_get_contents($projectRoot . '/public/sitemap.xml');

        $dom = new DOMDocument();
        $this->assertTrue($dom->loadXML($xml), 'sitemap.xml is not well-formed XML');
        $this->assertStringContainsString('sitemaps.org/schemas/sitemap/0.9', $xml);
    }

    // -------------------------------------------------------------------------
    // registerVirtualPage
    // -------------------------------------------------------------------------

    /**
     * registerVirtualPage() injects a sitemap.xml virtual page into the event.
     */
    public function testRegisterVirtualPageAddsVirtualPage(): void
    {
        $ext = new SitemapExtension();
        $event = new ContentDiscoveredEvent([], new BuildConfig(projectRoot: sys_get_temp_dir()));

        $ext->registerVirtualPage($event);

        $this->assertCount(1, $event->pages);
        $this->assertSame('/sitemap.xml', $event->pages[0]->urlPath);
        $this->assertTrue($event->pages[0]->virtual);
    }

    // -------------------------------------------------------------------------
    // resolveLastModified / normalizeDate
    // -------------------------------------------------------------------------

    /**
     * collect() uses the `lastmod` frontmatter key as the lastmod value.
     */
    public function testCollectUsesLastmodFrontmatterKey(): void
    {
        $ext = new SitemapExtension();
        [$projectRoot, $config] = $this->makeConfig(baseUrl: 'https://example.com');

        $page = $this->makePageWithMeta('/post', ['lastmod' => '2024-03-15'], $config);
        $ext->collect($page);
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $xml = (string)file_get_contents($projectRoot . '/public/sitemap.xml');
        $this->assertStringContainsString('2024-03-15', $xml);
    }

    /**
     * collect() uses the `updatedAt` frontmatter key when `lastmod` is absent.
     */
    public function testCollectUsesUpdatedAtFrontmatterKey(): void
    {
        $ext = new SitemapExtension();
        [$projectRoot, $config] = $this->makeConfig(baseUrl: 'https://example.com');

        $page = $this->makePageWithMeta('/post', ['updatedAt' => '2025-06-01'], $config);
        $ext->collect($page);
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $xml = (string)file_get_contents($projectRoot . '/public/sitemap.xml');
        $this->assertStringContainsString('2025-06-01', $xml);
    }

    /**
     * collect() uses the file mtime when no frontmatter date is present.
     */
    public function testCollectUsesSourceFileMtime(): void
    {
        $ext = new SitemapExtension();
        [$projectRoot, $config] = $this->makeConfig(baseUrl: 'https://example.com');

        // Create a real file and pin its mtime to a known timestamp.
        $sourceFile = $projectRoot . '/page.dj';
        file_put_contents($sourceFile, '# Test');
        touch($sourceFile, 1000000000); // 2001-09-09

        $page = new ContentPage(
            sourcePath: $sourceFile,
            relativePath: 'page.dj',
            slug: 'page',
            urlPath: '/page',
            outputRelativePath: 'page/index.html',
            title: 'Page',
            source: '',
            draft: false,
            meta: [],
        );
        $ext->collect(new PageWrittenEvent($page, '', $config));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $xml = (string)file_get_contents($projectRoot . '/public/sitemap.xml');
        $this->assertStringContainsString('2001-09-09', $xml);
    }

    /**
     * collect() falls back to the current-time placeholder when no date info is available.
     */
    public function testCollectFallsBackToCurrentDateWhenNoDateInfo(): void
    {
        $ext = new SitemapExtension();
        [$projectRoot, $config] = $this->makeConfig();

        $ext->collect($this->makePageWrittenEvent('/page', $config)); // no meta, no source file
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $xml = (string)file_get_contents($projectRoot . '/public/sitemap.xml');
        // lastmod element must be present (filled with current-time fallback).
        $this->assertStringContainsString('<lastmod>', $xml);
    }

    /**
     * collect() skips a frontmatter date that is invalid and falls through to null.
     */
    public function testCollectSkipsInvalidFrontmatterDate(): void
    {
        $ext = new SitemapExtension();
        [$projectRoot, $config] = $this->makeConfig();

        $page = $this->makePageWithMeta('/post', ['lastmod' => 'not-a-date'], $config);
        $ext->collect($page);
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $xml = (string)file_get_contents($projectRoot . '/public/sitemap.xml');
        // lastmod must still be present (falls back to current-time).
        $this->assertStringContainsString('<lastmod>', $xml);
        $this->assertStringNotContainsString('not-a-date', $xml);
    }

    // -------------------------------------------------------------------------
    // absoluteUrl (via write)
    // -------------------------------------------------------------------------

    /**
     * write() returns path-only URLs when no baseUrl is configured.
     */
    public function testWriteReturnsPathOnlyUrlWithoutBaseUrl(): void
    {
        $ext = new SitemapExtension();
        [$projectRoot, $config] = $this->makeConfig(); // no baseUrl

        $ext->collect($this->makePageWrittenEvent('/about', $config));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $xml = (string)file_get_contents($projectRoot . '/public/sitemap.xml');
        $this->assertStringContainsString('<loc>/about</loc>', $xml);
    }

    /**
     * write() prepends basePath to page URLs.
     */
    public function testWritePrependsBasePathToUrl(): void
    {
        $ext = new SitemapExtension();
        [$projectRoot, $config] = $this->makeConfig(baseUrl: 'https://example.com', basePath: '/docs');

        $ext->collect($this->makePageWrittenEvent('/guide', $config));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $xml = (string)file_get_contents($projectRoot . '/public/sitemap.xml');
        $this->assertStringContainsString('<loc>https://example.com/docs/guide</loc>', $xml);
    }

    /**
     * write() appends a trailing slash when the root path '/' is combined with a basePath.
     */
    public function testWriteHandlesRootPathWithBasePath(): void
    {
        $ext = new SitemapExtension();
        [$projectRoot, $config] = $this->makeConfig(baseUrl: 'https://example.com', basePath: '/docs');

        $ext->collect($this->makePageWrittenEvent('/', $config));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $xml = (string)file_get_contents($projectRoot . '/public/sitemap.xml');
        $this->assertStringContainsString('<loc>https://example.com/docs/</loc>', $xml);
    }

    // -------------------------------------------------------------------------
    // write() with no entries
    // -------------------------------------------------------------------------

    /**
     * write() produces a valid empty urlset when no pages were collected.
     */
    public function testWriteWithNoEntriesProducesValidEmptyXml(): void
    {
        $ext = new SitemapExtension();
        [$projectRoot, $config] = $this->makeConfig();

        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $xml = (string)file_get_contents($projectRoot . '/public/sitemap.xml');
        $dom = new DOMDocument();
        $this->assertTrue($dom->loadXML($xml));
        $this->assertStringNotContainsString('<url>', $xml);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build sitemap XML by collecting one fake page and calling write().
     *
     * @param \Glaze\Extension\SitemapExtension $ext Extension instance.
     */
    private function buildXmlFromExt(SitemapExtension $ext): string
    {
        [$projectRoot, $config] = $this->makeConfig(baseUrl: 'https://example.com');

        $ext->collect($this->makePageWrittenEvent('/test', $config));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $content = file_get_contents($projectRoot . '/public/sitemap.xml');

        return is_string($content) ? $content : '';
    }

    /**
     * Create a temp project root with a public output directory and matching BuildConfig.
     *
     * Returns `[$projectRoot, $config]`.
     *
     * @param string|null $baseUrl Optional site base URL.
     * @param string|null $basePath Optional site base path.
     * @return array{0: string, 1: \Glaze\Config\BuildConfig}
     */
    private function makeConfig(?string $baseUrl = null, ?string $basePath = null): array
    {
        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/public', 0755, true);

        $config = new BuildConfig(
            projectRoot: $projectRoot,
            site: new SiteConfig(baseUrl: $baseUrl, basePath: $basePath),
        );

        return [$projectRoot, $config];
    }

    /**
     * Create a PageWrittenEvent for the given URL path with custom frontmatter metadata.
     *
     * @param string $urlPath URL path for the fake page.
     * @param array<string, mixed> $meta Frontmatter metadata.
     * @param \Glaze\Config\BuildConfig $config Build config.
     */
    private function makePageWithMeta(string $urlPath, array $meta, BuildConfig $config): PageWrittenEvent
    {
        $page = new ContentPage(
            sourcePath: '',
            relativePath: ltrim($urlPath, '/') . '.dj',
            slug: ltrim($urlPath, '/'),
            urlPath: $urlPath,
            outputRelativePath: ltrim($urlPath, '/') . '/index.html',
            title: 'Test Page',
            source: '',
            draft: false,
            meta: $meta,
        );

        return new PageWrittenEvent($page, '', $config);
    }

    /**
     * Create a minimal PageWrittenEvent for the given URL path.
     *
     * @param string $urlPath URL path for the fake page.
     * @param \Glaze\Config\BuildConfig $config Build config.
     */
    private function makePageWrittenEvent(string $urlPath, BuildConfig $config): PageWrittenEvent
    {
        return $this->makePageWithMeta($urlPath, [], $config);
    }
}
