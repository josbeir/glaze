<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Content;

use Glaze\Content\ContentPage;
use Glaze\Render\Djot\TocEntry;
use PHPUnit\Framework\TestCase;

/**
 * Tests for ContentPage metadata access helpers.
 */
final class ContentPageTest extends TestCase
{
    /**
     * Ensure dotted meta paths can be read with defaults.
     */
    public function testMetaSupportsDottedPathAccess(): void
    {
        $page = $this->createPage([
            'hero' => [
                'title' => 'Hero title',
                'primaryAction' => [
                    'href' => '/installation',
                ],
                'highlights' => [
                    ['title' => 'Fast'],
                ],
            ],
        ]);

        $this->assertSame('Hero title', $page->meta('hero.title'));
        $this->assertSame('/installation', $page->meta('hero.primaryAction.href'));
        $this->assertSame([['title' => 'Fast']], $page->meta('hero.highlights'));
        $this->assertSame('fallback', $page->meta('hero.subtitle', 'fallback'));
        $this->assertSame($page->meta, $page->meta(''));
    }

    /**
     * Ensure hasMeta reports nested key existence reliably.
     */
    public function testHasMetaSupportsDottedPathChecks(): void
    {
        $page = $this->createPage([
            'hero' => [
                'title' => 'Hero title',
            ],
        ]);

        $this->assertTrue($page->hasMeta('hero.title'));
        $this->assertFalse($page->hasMeta('hero.subtitle'));
        $this->assertTrue($page->hasMeta(''));

        $emptyPage = $this->createPage([]);
        $this->assertFalse($emptyPage->hasMeta(''));
    }

    /**
     * Ensure toc defaults to an empty list when not provided.
     */
    public function testTocDefaultsToEmptyList(): void
    {
        $page = $this->createPage([]);

        $this->assertSame([], $page->toc);
    }

    /**
     * Ensure withToc() returns a new instance with the supplied entries, leaving the original unchanged.
     */
    public function testWithTocReturnsNewInstanceWithEntries(): void
    {
        $page = $this->createPage([]);
        $entries = [
            new TocEntry(level: 1, id: 'intro', text: 'Introduction'),
            new TocEntry(level: 2, id: 'setup', text: 'Setup'),
        ];

        $enriched = $page->withToc($entries);

        $this->assertNotSame($page, $enriched);
        $this->assertSame([], $page->toc);
        $this->assertSame($entries, $enriched->toc);
        $this->assertSame(1, $enriched->toc[0]->level);
        $this->assertSame('intro', $enriched->toc[0]->id);
        $this->assertSame('Introduction', $enriched->toc[0]->text);
    }

    /**
     * Ensure withToc() preserves all other page properties unchanged.
     */
    public function testWithTocPreservesAllOtherProperties(): void
    {
        $page = $this->createPage(['weight' => 5]);
        $enriched = $page->withToc([new TocEntry(level: 2, id: 'setup', text: 'Setup')]);

        $this->assertSame($page->sourcePath, $enriched->sourcePath);
        $this->assertSame($page->relativePath, $enriched->relativePath);
        $this->assertSame($page->slug, $enriched->slug);
        $this->assertSame($page->urlPath, $enriched->urlPath);
        $this->assertSame($page->title, $enriched->title);
        $this->assertSame($page->source, $enriched->source);
        $this->assertSame($page->draft, $enriched->draft);
        $this->assertSame($page->meta, $enriched->meta);
        $this->assertSame($page->virtual, $enriched->virtual);
    }

    /**
     * Ensure ContentPage::virtual() creates a page with the expected defaults.
     */
    public function testVirtualPageFactory(): void
    {
        $page = ContentPage::virtual('/sitemap.xml', 'sitemap.xml', 'Sitemap');

        $this->assertTrue($page->virtual);
        $this->assertFalse($page->draft);
        $this->assertSame('/sitemap.xml', $page->urlPath);
        $this->assertSame('sitemap.xml', $page->outputRelativePath);
        $this->assertSame('sitemap.xml', $page->relativePath);
        $this->assertSame('Sitemap', $page->title);
        $this->assertSame('sitemap.xml', $page->slug);
        $this->assertSame('', $page->sourcePath);
        $this->assertSame('', $page->source);
    }

    /**
     * Ensure ContentPage::virtual() falls back to the slug as title when no title is given.
     */
    public function testVirtualPageFactoryFallsBackToSlugAsTitle(): void
    {
        $page = ContentPage::virtual('/llms.txt', 'llms.txt');

        $this->assertSame('llms.txt', $page->title);
    }

    /**
     * Ensure ContentPage::virtual() forwards custom metadata to the page object.
     */
    public function testVirtualPageFactoryForwardsMetadata(): void
    {
        $page = ContentPage::virtual('/feed.xml', 'feed.xml', 'Feed', ['type' => 'rss']);

        $this->assertSame(['type' => 'rss'], $page->meta);
    }

    /**
     * Ensure withToc() preserves the virtual flag on virtual pages.
     */
    public function testWithTocPreservesVirtualFlag(): void
    {
        $page = ContentPage::virtual('/sitemap.xml', 'sitemap.xml', 'Sitemap');
        $enriched = $page->withToc([]);

        $this->assertTrue($enriched->virtual);
    }

    /**
     * Create a page fixture for metadata helper tests.
     *
     * @param array<string, mixed> $meta Metadata map.
     */
    protected function createPage(array $meta): ContentPage
    {
        return new ContentPage(
            sourcePath: '/tmp/content/index.dj',
            relativePath: 'index.dj',
            slug: 'index',
            urlPath: '/',
            outputRelativePath: 'index.html',
            title: 'Home',
            source: '# Home',
            draft: false,
            meta: $meta,
            taxonomies: [],
        );
    }
}
