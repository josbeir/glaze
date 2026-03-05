<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Extension;

use Cake\Chronos\Chronos;
use DOMDocument;
use DOMNodeList;
use DOMXPath;
use Glaze\Build\Event\BuildCompletedEvent;
use Glaze\Build\Event\ContentDiscoveredEvent;
use Glaze\Build\Event\PageWrittenEvent;
use Glaze\Config\BuildConfig;
use Glaze\Config\SiteConfig;
use Glaze\Content\ContentPage;
use Glaze\Extension\RssFeedExtension;
use Glaze\Tests\Helper\FilesystemTestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Tests for RssFeedExtension including options, filtering, XML output, and date handling.
 */
final class RssFeedExtensionTest extends TestCase
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
        $ext = new RssFeedExtension();

        $this->assertInstanceOf(RssFeedExtension::class, $ext);
    }

    /**
     * fromConfig() with an empty array returns a default instance.
     */
    public function testFromConfigWithEmptyOptionsReturnsDefault(): void
    {
        $ext = RssFeedExtension::fromConfig([]);

        $this->assertInstanceOf(RssFeedExtension::class, $ext);
    }

    /**
     * fromConfig() accepts a custom filename.
     */
    public function testFromConfigAcceptsFilename(): void
    {
        $ext = RssFeedExtension::fromConfig(['filename' => 'blog.xml']);

        [$projectRoot, $config] = $this->makeConfig();
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $this->assertFileExists($projectRoot . '/public/blog.xml');
        $this->assertFileDoesNotExist($projectRoot . '/public/feed.xml');
    }

    /**
     * fromConfig() treats blank-only filename as the default feed.xml.
     */
    public function testFromConfigIgnoresBlankFilename(): void
    {
        $ext = RssFeedExtension::fromConfig(['filename' => '   ']);

        [$projectRoot, $config] = $this->makeConfig();
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $this->assertFileExists($projectRoot . '/public/feed.xml');
    }

    /**
     * fromConfig() accepts a title override.
     */
    public function testFromConfigAcceptsTitle(): void
    {
        $ext = RssFeedExtension::fromConfig(['title' => 'Custom Feed Title']);

        [$projectRoot, $config] = $this->makeConfig();
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $xml = (string)file_get_contents($projectRoot . '/public/feed.xml');
        $this->assertStringContainsString('Custom Feed Title', $xml);
    }

    /**
     * fromConfig() treats blank-only title as null (falls back to site.title).
     */
    public function testFromConfigIgnoresBlankTitle(): void
    {
        $ext = RssFeedExtension::fromConfig(['title' => '   ']);

        [$projectRoot, $config] = $this->makeConfig(siteTitle: 'Site Title Fallback');
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $xml = (string)file_get_contents($projectRoot . '/public/feed.xml');
        $this->assertStringContainsString('Site Title Fallback', $xml);
    }

    /**
     * fromConfig() accepts a description override.
     */
    public function testFromConfigAcceptsDescription(): void
    {
        $ext = RssFeedExtension::fromConfig(['description' => 'Custom description.']);

        [$projectRoot, $config] = $this->makeConfig();
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $xml = (string)file_get_contents($projectRoot . '/public/feed.xml');
        $this->assertStringContainsString('Custom description.', $xml);
    }

    /**
     * fromConfig() accepts a list of content type names.
     */
    public function testFromConfigAcceptsTypes(): void
    {
        $ext = RssFeedExtension::fromConfig(['types' => ['blog', 'news']]);

        [$projectRoot, $config] = $this->makeConfig();
        $ext->collect($this->makePageFor('/blog/post', $config, type: 'blog'));
        $ext->collect($this->makePageFor('/docs/guide', $config, type: 'docs'));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $xml = (string)file_get_contents($projectRoot . '/public/feed.xml');
        $this->assertStringContainsString('/blog/post', $xml);
        $this->assertStringNotContainsString('/docs/guide', $xml);
    }

    /**
     * fromConfig() ignores invalid type entries (non-string, empty string).
     */
    public function testFromConfigIgnoresInvalidTypeEntries(): void
    {
        $ext = RssFeedExtension::fromConfig(['types' => ['', null, 42, 'blog']]);

        [$projectRoot, $config] = $this->makeConfig();
        $ext->collect($this->makePageFor('/blog/post', $config, type: 'blog'));
        $ext->collect($this->makePageFor('/misc/page', $config, type: 'misc'));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $xml = (string)file_get_contents($projectRoot . '/public/feed.xml');
        $this->assertStringContainsString('/blog/post', $xml);
        $this->assertStringNotContainsString('/misc/page', $xml);
    }

    /**
     * fromConfig() accepts a list of URL exclude prefixes.
     */
    public function testFromConfigAcceptsExcludeList(): void
    {
        $ext = RssFeedExtension::fromConfig(['exclude' => ['/private', '/drafts']]);

        [$projectRoot, $config] = $this->makeConfig();
        $ext->collect($this->makePageFor('/private/secret', $config));
        $ext->collect($this->makePageFor('/docs/guide', $config));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $xml = (string)file_get_contents($projectRoot . '/public/feed.xml');
        $this->assertStringNotContainsString('/private', $xml);
        $this->assertStringContainsString('/docs/guide', $xml);
    }

    /**
     * fromConfig() ignores invalid exclude entries (non-string, empty string).
     */
    public function testFromConfigIgnoresInvalidExcludeEntries(): void
    {
        $ext = RssFeedExtension::fromConfig(['exclude' => ['', null, 42, '/valid']]);

        [$projectRoot, $config] = $this->makeConfig();
        $ext->collect($this->makePageFor('/valid/page', $config));
        $ext->collect($this->makePageFor('/other/page', $config));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $xml = (string)file_get_contents($projectRoot . '/public/feed.xml');
        $this->assertStringNotContainsString('/valid', $xml);
        $this->assertStringContainsString('/other/page', $xml);
    }

    // -------------------------------------------------------------------------
    // registerVirtualPage
    // -------------------------------------------------------------------------

    /**
     * registerVirtualPage() appends a virtual page for the feed file.
     */
    public function testRegisterVirtualPageAddsVirtualPage(): void
    {
        $ext = new RssFeedExtension();
        [, $config] = $this->makeConfig();

        $event = new ContentDiscoveredEvent([], $config);
        $ext->registerVirtualPage($event);

        $paths = array_map(static fn(ContentPage $p): string => $p->urlPath, $event->pages);
        $this->assertContains('/feed.xml', $paths);
    }

    /**
     * registerVirtualPage() uses the configured filename for the virtual page URL.
     */
    public function testRegisterVirtualPageUsesConfiguredFilename(): void
    {
        $ext = new RssFeedExtension(filename: 'blog.xml');
        [, $config] = $this->makeConfig();

        $event = new ContentDiscoveredEvent([], $config);
        $ext->registerVirtualPage($event);

        $paths = array_map(static fn(ContentPage $p): string => $p->urlPath, $event->pages);
        $this->assertContains('/blog.xml', $paths);
    }

    // -------------------------------------------------------------------------
    // collect() filtering
    // -------------------------------------------------------------------------

    /**
     * collect() skips virtual pages.
     */
    public function testCollectSkipsVirtualPages(): void
    {
        $ext = new RssFeedExtension();
        [$projectRoot, $config] = $this->makeConfig();

        $virtualPage = ContentPage::virtual('/sitemap.xml', 'sitemap.xml', 'Sitemap');
        $ext->collect(new PageWrittenEvent($virtualPage, '', $config));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $xml = (string)file_get_contents($projectRoot . '/public/feed.xml');
        $items = $this->extractItems($xml);
        $this->assertCount(0, $items);
    }

    /**
     * collect() skips unlisted pages.
     */
    public function testCollectSkipsUnlistedPages(): void
    {
        $ext = new RssFeedExtension();
        [$projectRoot, $config] = $this->makeConfig();

        $ext->collect($this->makePageFor('/about', $config, unlisted: true));
        $ext->collect($this->makePageFor('/blog/post', $config));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $xml = (string)file_get_contents($projectRoot . '/public/feed.xml');
        $this->assertStringNotContainsString('/about', $xml);
        $this->assertStringContainsString('/blog/post', $xml);
    }

    /**
     * collect() skips pages matching exclude prefixes.
     */
    public function testCollectSkipsExcludedPrefixes(): void
    {
        $ext = new RssFeedExtension(exclude: ['/private']);

        [$projectRoot, $config] = $this->makeConfig();
        $ext->collect($this->makePageFor('/private/page', $config));
        $ext->collect($this->makePageFor('/public/page', $config));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $xml = (string)file_get_contents($projectRoot . '/public/feed.xml');
        $this->assertStringNotContainsString('/private', $xml);
        $this->assertStringContainsString('/public/page', $xml);
    }

    /**
     * collect() excludes pages whose type is not in the types list when types are set.
     */
    public function testCollectFiltersPagesByType(): void
    {
        $ext = new RssFeedExtension(types: ['blog']);

        [$projectRoot, $config] = $this->makeConfig();
        $ext->collect($this->makePageFor('/blog/post', $config, type: 'blog'));
        $ext->collect($this->makePageFor('/docs/guide', $config, type: 'docs'));
        $ext->collect($this->makePageFor('/about', $config));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $xml = (string)file_get_contents($projectRoot . '/public/feed.xml');
        $this->assertStringContainsString('/blog/post', $xml);
        $this->assertStringNotContainsString('/docs/guide', $xml);
        $this->assertStringNotContainsString('/about', $xml);
    }

    /**
     * collect() includes all non-excluded pages when types list is empty.
     */
    public function testCollectIncludesAllPagesWhenTypesEmpty(): void
    {
        $ext = new RssFeedExtension();

        [$projectRoot, $config] = $this->makeConfig();
        $ext->collect($this->makePageFor('/blog/post', $config, type: 'blog'));
        $ext->collect($this->makePageFor('/docs/guide', $config, type: 'docs'));
        $ext->collect($this->makePageFor('/about', $config));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $xml = (string)file_get_contents($projectRoot . '/public/feed.xml');
        $this->assertStringContainsString('/blog/post', $xml);
        $this->assertStringContainsString('/docs/guide', $xml);
        $this->assertStringContainsString('/about', $xml);
    }

    /**
     * collect() deduplicates entries — last write for a URL path wins.
     */
    public function testCollectDeduplicatesEntriesByUrlPath(): void
    {
        $ext = new RssFeedExtension();

        [$projectRoot, $config] = $this->makeConfig();
        $ext->collect($this->makePageFor('/blog/post', $config, title: 'First'));
        $ext->collect($this->makePageFor('/blog/post', $config, title: 'Second'));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $xml = (string)file_get_contents($projectRoot . '/public/feed.xml');
        $items = $this->extractItems($xml);
        $this->assertCount(1, $items);
        $this->assertStringContainsString('Second', $xml);
        $this->assertStringNotContainsString('First', $xml);
    }

    // -------------------------------------------------------------------------
    // write() — XML structure
    // -------------------------------------------------------------------------

    /**
     * write() always creates a feed.xml file.
     */
    public function testWriteCreatesFile(): void
    {
        $ext = new RssFeedExtension();
        [$projectRoot, $config] = $this->makeConfig();
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $this->assertFileExists($projectRoot . '/public/feed.xml');
    }

    /**
     * write() produces well-formed XML.
     */
    public function testWriteProducesValidXml(): void
    {
        $ext = new RssFeedExtension();
        [$projectRoot, $config] = $this->makeConfig();
        $ext->collect($this->makePageFor('/post', $config));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $xml = (string)file_get_contents($projectRoot . '/public/feed.xml');
        $dom = new DOMDocument();
        $this->assertTrue($dom->loadXML($xml), 'Generated feed is not valid XML');
    }

    /**
     * write() emits RSS 2.0 root element with atom namespace.
     */
    public function testWriteEmitsRss2Root(): void
    {
        $ext = new RssFeedExtension();
        [$projectRoot, $config] = $this->makeConfig();
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $xml = (string)file_get_contents($projectRoot . '/public/feed.xml');
        $this->assertStringContainsString('version="2.0"', $xml);
        $this->assertStringContainsString('xmlns:atom=', $xml);
    }

    /**
     * write() uses site.title as the channel <title> when no override is set.
     */
    public function testWriteUsesSiteTitleAsChannelTitle(): void
    {
        $ext = new RssFeedExtension();
        [$projectRoot, $config] = $this->makeConfig(siteTitle: 'My Blog');
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $xml = (string)file_get_contents($projectRoot . '/public/feed.xml');
        $this->assertStringContainsString('My Blog', $xml);
    }

    /**
     * write() uses the title override instead of site.title when configured.
     */
    public function testWriteUsesTitleOverride(): void
    {
        $ext = new RssFeedExtension(title: 'Override Title');
        [$projectRoot, $config] = $this->makeConfig(siteTitle: 'Site Title');
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $xml = (string)file_get_contents($projectRoot . '/public/feed.xml');
        $this->assertStringContainsString('Override Title', $xml);
        $this->assertStringNotContainsString('Site Title', $xml);
    }

    /**
     * write() uses site.description as the channel <description> when no override is set.
     */
    public function testWriteUsesSiteDescriptionAsChannelDescription(): void
    {
        $ext = new RssFeedExtension();

        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/public', 0755, true);
        $config = new BuildConfig(
            projectRoot: $projectRoot,
            site: new SiteConfig(description: 'Site-level description.'),
        );
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $xml = (string)file_get_contents($projectRoot . '/public/feed.xml');
        $this->assertStringContainsString('Site-level description.', $xml);
    }

    /**
     * write() uses the description override instead of site.description when configured.
     */
    public function testWriteUsesDescriptionOverride(): void
    {
        $ext = new RssFeedExtension(description: 'Custom channel description.');
        [$projectRoot, $config] = $this->makeConfig();
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $xml = (string)file_get_contents($projectRoot . '/public/feed.xml');
        $this->assertStringContainsString('Custom channel description.', $xml);
    }

    /**
     * write() emits an atom:link self-referential element pointing to the feed file.
     */
    public function testWriteEmitsAtomLinkSelf(): void
    {
        $ext = new RssFeedExtension();
        [$projectRoot, $config] = $this->makeConfig(baseUrl: 'https://example.com');
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $xml = (string)file_get_contents($projectRoot . '/public/feed.xml');
        $this->assertStringContainsString('rel="self"', $xml);
        $this->assertStringContainsString('https://example.com/feed.xml', $xml);
    }

    /**
     * write() produces an empty channel (no <item> elements) when no pages collected.
     */
    public function testWriteWithNoPagesProducesEmptyChannel(): void
    {
        $ext = new RssFeedExtension();
        [$projectRoot, $config] = $this->makeConfig();
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $xml = (string)file_get_contents($projectRoot . '/public/feed.xml');
        $dom = new DOMDocument();
        $this->assertTrue($dom->loadXML($xml));
        $this->assertStringNotContainsString('<item', $xml);
    }

    // -------------------------------------------------------------------------
    // write() — item fields
    // -------------------------------------------------------------------------

    /**
     * write() emits <title>, <link>, <description>, and <guid> for each item.
     */
    public function testWriteEmitsItemFields(): void
    {
        $ext = new RssFeedExtension();
        [$projectRoot, $config] = $this->makeConfig(baseUrl: 'https://example.com');

        $ext->collect($this->makePageFor('/blog/post', $config, title: 'Post Title', meta: ['description' => 'The post desc.']));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $xml = (string)file_get_contents($projectRoot . '/public/feed.xml');
        $this->assertStringContainsString('Post Title', $xml);
        $this->assertStringContainsString('https://example.com/blog/post', $xml);
        $this->assertStringContainsString('The post desc.', $xml);
        $this->assertStringContainsString('<guid', $xml);
    }

    /**
     * write() sets isPermaLink="true" on <guid> elements.
     */
    public function testWriteGuidHasIsPermaLinkAttribute(): void
    {
        $ext = new RssFeedExtension();
        [$projectRoot, $config] = $this->makeConfig(baseUrl: 'https://example.com');

        $ext->collect($this->makePageFor('/post', $config));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $xml = (string)file_get_contents($projectRoot . '/public/feed.xml');
        $this->assertStringContainsString('isPermaLink="true"', $xml);
    }

    /**
     * write() omits <pubDate> when no resolvable date is found.
     */
    public function testWriteOmitsPubDateWhenNotAvailable(): void
    {
        $ext = new RssFeedExtension();
        [$projectRoot, $config] = $this->makeConfig();

        $ext->collect($this->makePageFor('/post', $config, meta: []));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $xml = (string)file_get_contents($projectRoot . '/public/feed.xml');
        $this->assertStringNotContainsString('<pubDate>', $xml);
    }

    /**
     * write() includes <pubDate> when the 'date' frontmatter key is a DateTimeInterface.
     */
    public function testWriteIncludesPubDateFromDateTimeInterfaceMeta(): void
    {
        $ext = new RssFeedExtension();
        [$projectRoot, $config] = $this->makeConfig();

        $ext->collect($this->makePageFor('/post', $config, meta: ['date' => new Chronos('2026-01-15 10:00:00')]));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $xml = (string)file_get_contents($projectRoot . '/public/feed.xml');
        $this->assertStringContainsString('<pubDate>', $xml);
        $this->assertStringContainsString('2026', $xml);
    }

    /**
     * write() includes <pubDate> from a raw string 'date' frontmatter key.
     */
    public function testWriteIncludesPubDateFromStringDate(): void
    {
        $ext = new RssFeedExtension();
        [$projectRoot, $config] = $this->makeConfig();

        $ext->collect($this->makePageFor('/post', $config, meta: ['date' => '2026-03-01T10:00:00+01:00']));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $xml = (string)file_get_contents($projectRoot . '/public/feed.xml');
        $this->assertStringContainsString('<pubDate>', $xml);
    }

    /**
     * write() resolves date from 'pubDate' frontmatter key as fallback.
     */
    public function testWriteResolvesPubDateFromPubDateKey(): void
    {
        $ext = new RssFeedExtension();
        [$projectRoot, $config] = $this->makeConfig();

        $ext->collect($this->makePageFor('/post', $config, meta: ['pubDate' => '2026-02-01']));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $xml = (string)file_get_contents($projectRoot . '/public/feed.xml');
        $this->assertStringContainsString('<pubDate>', $xml);
    }

    /**
     * write() resolves date from 'publishedAt' frontmatter key as last fallback.
     */
    public function testWriteResolvesPubDateFromPublishedAtKey(): void
    {
        $ext = new RssFeedExtension();
        [$projectRoot, $config] = $this->makeConfig();

        $ext->collect($this->makePageFor('/post', $config, meta: ['publishedAt' => '2026-01-01']));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $xml = (string)file_get_contents($projectRoot . '/public/feed.xml');
        $this->assertStringContainsString('<pubDate>', $xml);
    }

    /**
     * write() skips an unparseable string date without emitting a pubDate.
     */
    public function testWriteSkipsUnparseableStringDate(): void
    {
        $ext = new RssFeedExtension();
        [$projectRoot, $config] = $this->makeConfig();

        $ext->collect($this->makePageFor('/post', $config, meta: ['date' => 'not-a-date']));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $xml = (string)file_get_contents($projectRoot . '/public/feed.xml');
        $this->assertStringNotContainsString('<pubDate>', $xml);
    }

    // -------------------------------------------------------------------------
    // write() — item description resolution
    // -------------------------------------------------------------------------

    /**
     * write() uses 'description' frontmatter key for item description.
     */
    public function testWriteUsesDescriptionFrontmatterKey(): void
    {
        $ext = new RssFeedExtension();
        [$projectRoot, $config] = $this->makeConfig();

        $ext->collect($this->makePageFor('/post', $config, meta: ['description' => 'Post description.']));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $xml = (string)file_get_contents($projectRoot . '/public/feed.xml');
        $this->assertStringContainsString('Post description.', $xml);
    }

    /**
     * write() falls back to 'summary' when no 'description' is present.
     */
    public function testWriteUsesSummaryFrontmatterKey(): void
    {
        $ext = new RssFeedExtension();
        [$projectRoot, $config] = $this->makeConfig();

        $ext->collect($this->makePageFor('/post', $config, meta: ['summary' => 'A summary.']));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $xml = (string)file_get_contents($projectRoot . '/public/feed.xml');
        $this->assertStringContainsString('A summary.', $xml);
    }

    /**
     * write() falls back to 'excerpt' as final description key.
     */
    public function testWriteUsesExcerptFrontmatterKey(): void
    {
        $ext = new RssFeedExtension();
        [$projectRoot, $config] = $this->makeConfig();

        $ext->collect($this->makePageFor('/post', $config, meta: ['excerpt' => 'An excerpt.']));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $xml = (string)file_get_contents($projectRoot . '/public/feed.xml');
        $this->assertStringContainsString('An excerpt.', $xml);
    }

    // -------------------------------------------------------------------------
    // write() — URL generation
    // -------------------------------------------------------------------------

    /**
     * write() builds absolute item URLs using site.baseUrl and site.basePath.
     */
    public function testWriteBuildsAbsoluteItemUrls(): void
    {
        $ext = new RssFeedExtension();
        [$projectRoot, $config] = $this->makeConfig(baseUrl: 'https://example.com', basePath: '/docs');

        $ext->collect($this->makePageFor('/guide', $config));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $xml = (string)file_get_contents($projectRoot . '/public/feed.xml');
        $this->assertStringContainsString('https://example.com/docs/guide', $xml);
    }

    /**
     * write() uses relative paths when no baseUrl is configured.
     */
    public function testWriteUsesRelativeUrlsWhenNoBaseUrl(): void
    {
        $ext = new RssFeedExtension();

        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/public', 0755, true);
        $config = new BuildConfig(projectRoot: $projectRoot);

        $ext->collect($this->makePageFor('/blog/post', $config));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $xml = (string)file_get_contents($projectRoot . '/public/feed.xml');
        $this->assertStringContainsString('/blog/post', $xml);
        $this->assertStringNotContainsString('https://', $xml);
    }

    // -------------------------------------------------------------------------
    // write() — sorting
    // -------------------------------------------------------------------------

    /**
     * write() sorts items newest-first by publication date.
     */
    public function testWriteSortsItemsNewestFirst(): void
    {
        $ext = new RssFeedExtension();
        [$projectRoot, $config] = $this->makeConfig();

        $ext->collect($this->makePageFor('/old', $config, title: 'Old Post', meta: ['date' => '2025-01-01']));
        $ext->collect($this->makePageFor('/new', $config, title: 'New Post', meta: ['date' => '2026-03-01']));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $xml = (string)file_get_contents($projectRoot . '/public/feed.xml');
        $posNew = strpos($xml, 'New Post');
        $posOld = strpos($xml, 'Old Post');
        $this->assertNotFalse($posNew);
        $this->assertNotFalse($posOld);
        $this->assertLessThan($posOld, $posNew);
    }

    /**
     * write() places undated items after all dated items.
     */
    public function testWritePlacesUndatedItemsLast(): void
    {
        $ext = new RssFeedExtension();
        [$projectRoot, $config] = $this->makeConfig();

        $ext->collect($this->makePageFor('/undated', $config, title: 'No Date'));
        $ext->collect($this->makePageFor('/dated', $config, title: 'With Date', meta: ['date' => '2026-01-01']));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $xml = (string)file_get_contents($projectRoot . '/public/feed.xml');
        $posDated = strpos($xml, 'With Date');
        $posUndated = strpos($xml, 'No Date');
        $this->assertNotFalse($posDated);
        $this->assertNotFalse($posUndated);
        $this->assertLessThan($posUndated, $posDated);
    }

    /**
     * write() sorts undated items by URL path alphabetically.
     */
    public function testWriteSortsUndatedItemsByUrlPath(): void
    {
        $ext = new RssFeedExtension();
        [$projectRoot, $config] = $this->makeConfig();

        $ext->collect($this->makePageFor('/z-last', $config));
        $ext->collect($this->makePageFor('/a-first', $config));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $xml = (string)file_get_contents($projectRoot . '/public/feed.xml');
        $posFirst = strpos($xml, '/a-first');
        $posLast = strpos($xml, '/z-last');
        $this->assertNotFalse($posFirst);
        $this->assertNotFalse($posLast);
        $this->assertLessThan($posLast, $posFirst);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Create a temp project root with a public output directory and matching BuildConfig.
     *
     * Returns `[$projectRoot, $config]`.
     *
     * @param string|null $siteTitle Optional site title.
     * @param string|null $baseUrl Optional site base URL.
     * @param string|null $basePath Optional site base path.
     * @return array{0: string, 1: \Glaze\Config\BuildConfig}
     */
    private function makeConfig(
        ?string $siteTitle = null,
        ?string $baseUrl = null,
        ?string $basePath = null,
    ): array {
        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/public', 0755, true);

        $config = new BuildConfig(
            projectRoot: $projectRoot,
            site: new SiteConfig(title: $siteTitle, baseUrl: $baseUrl, basePath: $basePath),
        );

        return [$projectRoot, $config];
    }

    /**
     * Create a PageWrittenEvent for a URL path with optional title, meta, type, and unlisted flag.
     *
     * @param string $urlPath URL path for the fake page.
     * @param \Glaze\Config\BuildConfig $config Build config.
     * @param string $title Optional page title.
     * @param array<string, mixed> $meta Optional frontmatter meta.
     * @param string|null $type Optional content type.
     * @param bool $unlisted Whether the page is unlisted.
     */
    private function makePageFor(
        string $urlPath,
        BuildConfig $config,
        string $title = 'Test Page',
        array $meta = [],
        ?string $type = null,
        bool $unlisted = false,
    ): PageWrittenEvent {
        $page = new ContentPage(
            sourcePath: '',
            relativePath: ltrim($urlPath, '/') . '.dj',
            slug: ltrim($urlPath, '/'),
            urlPath: $urlPath,
            outputRelativePath: ltrim($urlPath, '/') . '/index.html',
            title: $title,
            source: '',
            draft: false,
            meta: $meta,
            type: $type,
            unlisted: $unlisted,
        );

        return new PageWrittenEvent($page, '/tmp/output' . $urlPath . '/index.html', $config);
    }

    /**
     * Extract <item> elements from raw RSS XML using DOMXPath.
     *
     * @param string $xml RSS XML string.
     * @return \DOMNodeList<\DOMElement>
     */
    private function extractItems(string $xml): DOMNodeList
    {
        $dom = new DOMDocument();
        $dom->loadXML($xml);

        $xpath = new DOMXPath($dom);

        /** @var \DOMNodeList<\DOMElement> $items */
        $items = $xpath->query('//item');

        return $items;
    }
}
