<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Content;

use Glaze\Content\ContentPage;
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
