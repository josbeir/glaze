<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Extension;

use Glaze\Build\Event\BuildCompletedEvent;
use Glaze\Build\Event\ContentDiscoveredEvent;
use Glaze\Build\Event\PageRenderedEvent;
use Glaze\Config\BuildConfig;
use Glaze\Config\SiteConfig;
use Glaze\Content\ContentPage;
use Glaze\Extension\SearchIndexExtension;
use Glaze\Tests\Helper\FilesystemTestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SearchIndexExtension including options, exclude filtering, content extraction and JSON output.
 */
final class SearchIndexExtensionTest extends TestCase
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
        $ext = new SearchIndexExtension();

        $this->assertInstanceOf(SearchIndexExtension::class, $ext);
    }

    /**
     * fromConfig() with an empty array returns a default instance.
     */
    public function testFromConfigWithEmptyOptionsReturnsDefault(): void
    {
        $ext = SearchIndexExtension::fromConfig([]);

        $this->assertInstanceOf(SearchIndexExtension::class, $ext);
    }

    /**
     * fromConfig() accepts a custom filename.
     */
    public function testFromConfigAcceptsCustomFilename(): void
    {
        [$projectRoot, $config] = $this->makeConfig();
        $ext = SearchIndexExtension::fromConfig(['filename' => 'my-index.json']);
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $this->assertFileExists($projectRoot . '/public/my-index.json');
    }

    /**
     * fromConfig() with blank filename falls back to the default.
     */
    public function testFromConfigIgnoresBlankFilename(): void
    {
        [$projectRoot, $config] = $this->makeConfig();
        $ext = SearchIndexExtension::fromConfig(['filename' => '   ']);
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $this->assertFileExists($projectRoot . '/public/search-index.json');
    }

    /**
     * fromConfig() accepts a list of exclude prefixes.
     */
    public function testFromConfigAcceptsExcludeList(): void
    {
        $ext = SearchIndexExtension::fromConfig(['exclude' => ['/private', '/drafts']]);

        [$projectRoot, $config] = $this->makeConfig();
        $ext->collect($this->makePageRenderedEvent('/private/secret', '<p>Secret</p>', $config));
        $ext->collect($this->makePageRenderedEvent('/docs/guide', '<p>Guide</p>', $config));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $json = (string)file_get_contents($projectRoot . '/public/search-index.json');
        $this->assertStringNotContainsString('/private/secret', $json);
        $this->assertStringContainsString('/docs/guide', $json);
    }

    /**
     * fromConfig() ignores non-string items in exclude list.
     */
    public function testFromConfigIgnoresNonStringExcludeItems(): void
    {
        $ext = SearchIndexExtension::fromConfig(['exclude' => [123, null, '/valid']]);

        [$projectRoot, $config] = $this->makeConfig();
        $ext->collect($this->makePageRenderedEvent('/valid/page', '<p>Page</p>', $config));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $json = (string)file_get_contents($projectRoot . '/public/search-index.json');
        $this->assertStringNotContainsString('/valid/page', $json);
    }

    // -------------------------------------------------------------------------
    // registerVirtualPage
    // -------------------------------------------------------------------------

    /**
     * registerVirtualPage() appends a virtual page to the pages list.
     */
    public function testRegisterVirtualPageAppendsVirtualPage(): void
    {
        [, $config] = $this->makeConfig();
        $ext = new SearchIndexExtension();
        $event = new ContentDiscoveredEvent([], $config);

        $ext->registerVirtualPage($event);

        $this->assertCount(1, $event->pages);
        $this->assertTrue($event->pages[0]->virtual);
    }

    /**
     * registerVirtualPage() uses the configured filename as the URL path.
     */
    public function testRegisterVirtualPageUsesConfiguredFilename(): void
    {
        [, $config] = $this->makeConfig();
        $ext = new SearchIndexExtension(filename: 'custom-search.json');
        $event = new ContentDiscoveredEvent([], $config);

        $ext->registerVirtualPage($event);

        $this->assertSame('/custom-search.json', $event->pages[0]->urlPath);
    }

    // -------------------------------------------------------------------------
    // collect
    // -------------------------------------------------------------------------

    /**
     * collect() skips virtual pages.
     */
    public function testCollectSkipsVirtualPages(): void
    {
        $ext = new SearchIndexExtension();
        [$projectRoot, $config] = $this->makeConfig();

        $virtualPage = ContentPage::virtual('/search-index.json', 'search-index.json', 'Search index');
        $ext->collect(new PageRenderedEvent($virtualPage, '<p>Ignored</p>', $config));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $documents = $this->readDocuments($projectRoot);
        $this->assertCount(0, $documents);
    }

    /**
     * collect() skips pages matching an exclude prefix.
     */
    public function testCollectSkipsExcludedPrefixes(): void
    {
        $ext = new SearchIndexExtension(exclude: ['/drafts']);
        [$projectRoot, $config] = $this->makeConfig();

        $ext->collect($this->makePageRenderedEvent('/drafts/wip', '<p>WIP</p>', $config));
        $ext->collect($this->makePageRenderedEvent('/docs/intro', '<p>Intro</p>', $config));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $documents = $this->readDocuments($projectRoot);
        $urls = array_column($documents, 'url');
        $this->assertNotContains('/drafts/wip', $urls);
        $this->assertContains('/docs/intro', $urls);
    }

    /**
     * collect() only keeps the last version of a URL when the same path is collected twice.
     */
    public function testCollectDeduplicatesByUrlPath(): void
    {
        $ext = new SearchIndexExtension();
        [$projectRoot, $config] = $this->makeConfig();

        $ext->collect($this->makePageRenderedEvent('/page', '<p>First</p>', $config));
        $ext->collect($this->makePageRenderedEvent('/page', '<p>Second</p>', $config));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $documents = $this->readDocuments($projectRoot);
        $this->assertCount(1, $documents);
        $this->assertStringContainsString('Second', $this->getDocumentField($documents[0], 'content'));
    }

    /**
     * collect() stores the page URL path in the document.
     */
    public function testCollectStoresUrlPath(): void
    {
        $ext = new SearchIndexExtension();
        [$projectRoot, $config] = $this->makeConfig();

        $ext->collect($this->makePageRenderedEvent('/about', '<p>About us</p>', $config));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $documents = $this->readDocuments($projectRoot);
        $this->assertSame('/about', $documents[0]['url']);
    }

    /**
     * collect() prefixes URLs with site basePath when configured.
     */
    public function testCollectStoresUrlPathWithConfiguredBasePath(): void
    {
        $ext = new SearchIndexExtension();
        [$projectRoot, $config] = $this->makeConfig('/glaze');

        $ext->collect($this->makePageRenderedEvent('/about', '<p>About us</p>', $config));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $documents = $this->readDocuments($projectRoot);
        $this->assertSame('/glaze/about', $documents[0]['url']);
    }

    /**
     * collect() stores the page title.
     */
    public function testCollectStoresTitle(): void
    {
        $ext = new SearchIndexExtension();
        [$projectRoot, $config] = $this->makeConfig();

        $ext->collect($this->makePageRenderedEvent('/about', '<p>About</p>', $config, title: 'About Us'));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $documents = $this->readDocuments($projectRoot);
        $this->assertSame('About Us', $documents[0]['title']);
    }

    /**
     * collect() assigns incrementing integer IDs starting from 1.
     */
    public function testCollectAssignsIncrementingIds(): void
    {
        $ext = new SearchIndexExtension();
        [$projectRoot, $config] = $this->makeConfig();

        $ext->collect($this->makePageRenderedEvent('/a', '<p>A</p>', $config));
        $ext->collect($this->makePageRenderedEvent('/b', '<p>B</p>', $config));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $documents = $this->readDocuments($projectRoot);
        $ids = array_column($documents, 'id');
        $this->assertContains(1, $ids);
        $this->assertContains(2, $ids);
    }

    // -------------------------------------------------------------------------
    // Description resolution
    // -------------------------------------------------------------------------

    /**
     * collect() extracts description from the `description` frontmatter key.
     */
    public function testCollectResolvesDescriptionField(): void
    {
        $ext = new SearchIndexExtension();
        [$projectRoot, $config] = $this->makeConfig();

        $ext->collect($this->makePageRenderedEvent('/page', '<p>Content</p>', $config, meta: ['description' => 'A page about Glaze.']));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $documents = $this->readDocuments($projectRoot);
        $this->assertSame('A page about Glaze.', $documents[0]['description']);
    }

    /**
     * collect() falls back to the `summary` frontmatter key when `description` is absent.
     */
    public function testCollectFallsBackToSummaryField(): void
    {
        $ext = new SearchIndexExtension();
        [$projectRoot, $config] = $this->makeConfig();

        $ext->collect($this->makePageRenderedEvent('/page', '<p>Content</p>', $config, meta: ['summary' => 'A summarised page.']));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $documents = $this->readDocuments($projectRoot);
        $this->assertSame('A summarised page.', $documents[0]['description']);
    }

    /**
     * collect() falls back to the `excerpt` frontmatter key.
     */
    public function testCollectFallsBackToExcerptField(): void
    {
        $ext = new SearchIndexExtension();
        [$projectRoot, $config] = $this->makeConfig();

        $ext->collect($this->makePageRenderedEvent('/page', '<p>Content</p>', $config, meta: ['excerpt' => 'Short excerpt.']));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $documents = $this->readDocuments($projectRoot);
        $this->assertSame('Short excerpt.', $documents[0]['description']);
    }

    /**
     * collect() stores an empty string when no description-like meta key is present.
     */
    public function testCollectStoresEmptyDescriptionWhenNoMetaPresent(): void
    {
        $ext = new SearchIndexExtension();
        [$projectRoot, $config] = $this->makeConfig();

        $ext->collect($this->makePageRenderedEvent('/page', '<p>Content</p>', $config));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $documents = $this->readDocuments($projectRoot);
        $this->assertSame('', $documents[0]['description']);
    }

    // -------------------------------------------------------------------------
    // Content extraction
    // -------------------------------------------------------------------------

    /**
     * collect() strips HTML tags from rendered output for the content field.
     */
    public function testCollectStripsHtmlTagsFromContent(): void
    {
        $ext = new SearchIndexExtension();
        [$projectRoot, $config] = $this->makeConfig();

        $ext->collect($this->makePageRenderedEvent('/page', '<h1>Title</h1><p>Body text here.</p>', $config));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $documents = $this->readDocuments($projectRoot);
        $this->assertStringNotContainsString('<h1>', $this->getDocumentField($documents[0], 'content'));
        $this->assertStringContainsString('Title', $this->getDocumentField($documents[0], 'content'));
        $this->assertStringContainsString('Body text here.', $this->getDocumentField($documents[0], 'content'));
    }

    /**
     * collect() decodes HTML entities in content.
     */
    public function testCollectDecodesHtmlEntitiesInContent(): void
    {
        $ext = new SearchIndexExtension();
        [$projectRoot, $config] = $this->makeConfig();

        $ext->collect($this->makePageRenderedEvent('/page', '<p>Hello &amp; world &mdash; test.</p>', $config));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $documents = $this->readDocuments($projectRoot);
        $this->assertStringContainsString('Hello & world', $this->getDocumentField($documents[0], 'content'));
        $this->assertStringContainsString('—', $this->getDocumentField($documents[0], 'content'));
    }

    /**
     * collect() normalises multiple whitespace characters to single spaces.
     */
    public function testCollectNormalisesWhitespaceInContent(): void
    {
        $ext = new SearchIndexExtension();
        [$projectRoot, $config] = $this->makeConfig();

        $ext->collect($this->makePageRenderedEvent('/page', "<p>Hello   world\n\ntab\there.</p>", $config));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $documents = $this->readDocuments($projectRoot);
        $this->assertSame('Hello world tab here.', $documents[0]['content']);
    }

    // -------------------------------------------------------------------------
    // write
    // -------------------------------------------------------------------------

    /**
     * write() creates the search index file in the output directory.
     */
    public function testWriteCreatesSearchIndexFile(): void
    {
        [$projectRoot, $config] = $this->makeConfig();
        $ext = new SearchIndexExtension();
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $this->assertFileExists($projectRoot . '/public/search-index.json');
    }

    /**
     * write() produces valid JSON.
     */
    public function testWriteProducesValidJson(): void
    {
        [$projectRoot, $config] = $this->makeConfig();
        $ext = new SearchIndexExtension();
        $ext->collect($this->makePageRenderedEvent('/page', '<p>Test</p>', $config));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $json = (string)file_get_contents($projectRoot . '/public/search-index.json');
        $this->assertNotFalse(json_decode($json, true));
    }

    /**
     * write() produces a JSON array at the top level.
     */
    public function testWriteProducesJsonArray(): void
    {
        [$projectRoot, $config] = $this->makeConfig();
        $ext = new SearchIndexExtension();
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $documents = $this->readDocuments($projectRoot);
        $this->assertCount(0, $documents);
    }

    /**
     * write() sorts documents by URL path for stable output.
     */
    public function testWriteSortsDocumentsByUrlPath(): void
    {
        $ext = new SearchIndexExtension();
        [$projectRoot, $config] = $this->makeConfig();

        $ext->collect($this->makePageRenderedEvent('/z-last', '<p>Last</p>', $config));
        $ext->collect($this->makePageRenderedEvent('/a-first', '<p>First</p>', $config));
        $ext->collect($this->makePageRenderedEvent('/m-middle', '<p>Middle</p>', $config));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $documents = $this->readDocuments($projectRoot);
        $urls = array_column($documents, 'url');
        $this->assertSame(['/a-first', '/m-middle', '/z-last'], $urls);
    }

    /**
     * write() each document contains the required MiniSearch fields.
     */
    public function testWriteDocumentContainsRequiredFields(): void
    {
        $ext = new SearchIndexExtension();
        [$projectRoot, $config] = $this->makeConfig();

        $ext->collect($this->makePageRenderedEvent('/page', '<p>Hello</p>', $config, title: 'Hello'));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $document = $this->readDocuments($projectRoot)[0];
        $this->assertArrayHasKey('id', $document);
        $this->assertArrayHasKey('title', $document);
        $this->assertArrayHasKey('description', $document);
        $this->assertArrayHasKey('url', $document);
        $this->assertArrayHasKey('content', $document);
    }

    /**
     * write() uses the configured filename when set via constructor.
     */
    public function testWriteUsesConfiguredFilename(): void
    {
        $ext = new SearchIndexExtension(filename: 'site-search.json');
        [$projectRoot, $config] = $this->makeConfig();

        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $this->assertFileExists($projectRoot . '/public/site-search.json');
        $this->assertFileDoesNotExist($projectRoot . '/public/search-index.json');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Create a temp project root with a public output directory and matching BuildConfig.
     *
     * Returns `[$projectRoot, $config]`.
     *
     * @return array{0: string, 1: \Glaze\Config\BuildConfig}
     */
    private function makeConfig(?string $basePath = null): array
    {
        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/public', 0755, true);
        $config = new BuildConfig(projectRoot: $projectRoot, site: new SiteConfig(basePath: $basePath));

        return [$projectRoot, $config];
    }

    /**
     * Create a minimal PageRenderedEvent for the given URL path and HTML content.
     *
     * @param string $urlPath URL path for the fake page.
     * @param string $html Rendered HTML string.
     * @param \Glaze\Config\BuildConfig $config Build config.
     * @param string $title Page title.
     * @param array<string, mixed> $meta Frontmatter meta values.
     */
    private function makePageRenderedEvent(
        string $urlPath,
        string $html,
        BuildConfig $config,
        string $title = 'Test Page',
        array $meta = [],
    ): PageRenderedEvent {
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
        );

        return new PageRenderedEvent($page, $html, $config);
    }

    /**
     * Extract a string field from a decoded document, asserting it is indeed a string.
     *
     * @param array<string, mixed> $document
     */
    private function getDocumentField(array $document, string $key): string
    {
        $value = $document[$key] ?? '';
        $this->assertIsString($value);

        return $value;
    }

    /**
     * Read and decode the search-index.json documents array from the output directory.
     *
     * @param string $projectRoot Temporary project root.
     * @return array<int, array<string, mixed>>
     */
    private function readDocuments(string $projectRoot): array
    {
        $path = $projectRoot . '/public/search-index.json';
        $this->assertFileExists($path);
        $decoded = json_decode((string)file_get_contents($path), true);
        $this->assertIsArray($decoded);

        /** @var array<int, array<string, mixed>> $decoded */
        return $decoded;
    }
}
