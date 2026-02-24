<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Template;

use Glaze\Content\ContentPage;
use Glaze\Template\PageCollection;
use Glaze\Template\Pager;
use PHPUnit\Framework\TestCase;

/**
 * Tests for pager behavior and navigation helpers.
 */
final class PagerTest extends TestCase
{
    /**
     * Ensure pager exposes expected page slices and navigation metadata.
     */
    public function testPagerNavigationAndMetrics(): void
    {
        $collection = new PageCollection([
            $this->makePage('blog/a', '/blog/a/'),
            $this->makePage('blog/b', '/blog/b/'),
            $this->makePage('blog/c', '/blog/c/'),
        ]);

        $pager = new Pager($collection, 2, 2, '/blog/');

        $this->assertSame(2, $pager->totalPages());
        $this->assertSame(2, $pager->pageNumber());
        $this->assertSame(1, $pager->numberOfElements());
        $this->assertSame(3, $pager->totalNumberOfElements());
        $this->assertSame(2, $pager->pageSize());
        $this->assertSame(2, $pager->pagerSize());
        $this->assertSame('/blog/page/2/', $pager->url());
        $this->assertSame('/blog/', $pager->prevUrl());
        $this->assertNull($pager->nextUrl());
        $this->assertTrue($pager->hasPrev());
        $this->assertFalse($pager->hasNext());
        $this->assertSame('/blog/', $pager->first()->url());
        $this->assertSame('/blog/page/2/', $pager->last()->url());
        $this->assertSame('/blog/', $pager->prev()?->url());
        $this->assertNotInstanceOf(Pager::class, $pager->next());
        $this->assertCount(2, $pager->pagers());
        $this->assertSame('blog/c', $pager->pages()->first()?->slug);
    }

    /**
     * Ensure source and next-page helpers are covered when a next page exists.
     */
    public function testPagerSourceAndNextUrlWithNextPage(): void
    {
        $collection = new PageCollection([
            $this->makePage('a', '/a/'),
            $this->makePage('b', '/b/'),
            $this->makePage('c', '/c/'),
            $this->makePage('d', '/d/'),
        ]);

        $pager = new Pager($collection, 2, 1, '/blog');

        $this->assertSame($collection, $pager->source());
        $this->assertInstanceOf(Pager::class, $pager->next());
        $this->assertSame('/blog/page/2/', $pager->nextUrl());
    }

    /**
     * Ensure edge paths and empty collections are normalized safely.
     */
    public function testPagerHandlesEmptyAndPathEdgeCases(): void
    {
        $empty = new PageCollection([]);
        $pager = new Pager($empty, 0, 99, '', '');

        $this->assertSame(1, $pager->totalPages());
        $this->assertSame(1, $pager->pageNumber());
        $this->assertSame('/', $pager->url());
        $this->assertFalse($pager->hasPrev());
        $this->assertFalse($pager->hasNext());
        $this->assertNull($pager->prevUrl());
        $this->assertNull($pager->nextUrl());
        $this->assertNotInstanceOf(Pager::class, $pager->prev());
        $this->assertNotInstanceOf(Pager::class, $pager->next());

        $collection = new PageCollection([
            $this->makePage('a', '/a/'),
            $this->makePage('b', '/b/'),
        ]);
        $custom = new Pager($collection, 1, 2, '///docs///', '');

        $this->assertSame('/docs/2/', $custom->url());
        $this->assertSame('/docs/', $custom->prevUrl());
    }

    /**
     * Build a minimal page object.
     */
    protected function makePage(string $slug, string $url): ContentPage
    {
        return new ContentPage(
            sourcePath: '/tmp/' . $slug . '.dj',
            relativePath: $slug . '.dj',
            slug: $slug,
            urlPath: $url,
            outputRelativePath: $slug . '/index.html',
            title: strtoupper($slug),
            source: '# ' . strtoupper($slug),
            draft: false,
            meta: [],
        );
    }
}
