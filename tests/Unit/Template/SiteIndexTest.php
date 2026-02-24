<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Template;

use Closure;
use Glaze\Content\ContentPage;
use Glaze\Template\SiteIndex;
use PHPUnit\Framework\TestCase;

/**
 * Tests for site index lookups and taxonomy mapping.
 */
final class SiteIndexTest extends TestCase
{
    /**
     * Validate section selection and page lookups.
     */
    public function testSiteIndexSectionAndFindOperations(): void
    {
        $index = new SiteIndex([
            $this->makePage('index', '/', 'index.dj', ['weight' => 1, 'date' => '2026-01-01'], 'Home'),
            $this->makePage('blog/post-b', '/blog/post-b/', 'blog/post-b.dj', ['weight' => 3, 'date' => '2026-01-03'], 'Post B'),
            $this->makePage('blog/post-a', '/blog/post-a/', 'blog/post-a.dj', ['weight' => 2, 'date' => '2026-01-02'], 'Post A'),
        ]);

        $this->assertSame('Home', $index->findBySlug('index')?->title);
        $this->assertSame('Post B', $index->findByUrlPath('/blog/post-b/')?->title);
        $this->assertCount(2, $index->section('blog'));

        $sortedBlogTitles = array_map(static fn(ContentPage $page): string => $page->title, $index->section('blog')->all());
        $this->assertSame(['Post A', 'Post B'], $sortedBlogTitles);
    }

    /**
     * Validate taxonomy and section-neighbor navigation helpers.
     */
    public function testSiteIndexTaxonomyAndAdjacentPages(): void
    {
        $postA = $this->makePage(
            slug: 'blog/post-a',
            urlPath: '/blog/post-a/',
            relativePath: 'blog/post-a.dj',
            meta: ['weight' => 2],
            title: 'Post A',
            taxonomies: ['tags' => ['php', 'cake'], 'categories' => ['tutorial']],
        );
        $postB = $this->makePage(
            slug: 'blog/post-b',
            urlPath: '/blog/post-b/',
            relativePath: 'blog/post-b.dj',
            meta: ['weight' => 3],
            title: 'Post B',
            taxonomies: ['tags' => ['php'], 'categories' => ['tutorial']],
        );
        $postC = $this->makePage(
            slug: 'blog/post-c',
            urlPath: '/blog/post-c/',
            relativePath: 'blog/post-c.dj',
            meta: ['weight' => 4],
            title: 'Post C',
            taxonomies: ['tags' => ['docs'], 'categories' => ['reference']],
        );

        $index = new SiteIndex([$postA, $postB, $postC]);

        $tags = $index->taxonomy('tags');
        $this->assertTrue($tags->hasTerm('php'));
        $this->assertCount(2, $tags->term('php'));
        $this->assertCount(2, $index->taxonomy('categories')->term('tutorial'));

        $this->assertSame('Post A', $index->previousInSection($postB)?->title);
        $this->assertSame('Post C', $index->nextInSection($postB)?->title);
    }

    /**
     * Validate additional edge cases for path normalization and taxonomy values.
     */
    public function testSiteIndexHandlesEdgeCases(): void
    {
        $home = $this->makePage('index', '/', 'index.dj', ['weight' => 1], 'Home');
        $docs = $this->makePage(
            'docs/guide',
            '/docs/guide/',
            'articles/guide.dj',
            ['section' => 'docs', 'date' => 'bad-date'],
            'Guide',
            ['tags' => ['guide']],
        );
        $blog = $this->makePage(
            'blog/post',
            '/blog/post/',
            'blog/post.dj',
            ['weight' => 2],
            'Post',
            ['tags' => ['php']],
        );

        $index = new SiteIndex([$home, $docs, $blog]);

        $this->assertCount(3, $index->all());
        $this->assertSame('Home', $index->findBySlug('')?->title);
        $this->assertSame('Home', $index->findByUrlPath('')?->title);
        $this->assertSame('Home', $index->findByUrlPath('/index')?->title);
        $this->assertNotInstanceOf(ContentPage::class, $index->findBySlug('missing'));
        $this->assertNotInstanceOf(ContentPage::class, $index->findByUrlPath('/missing/'));
        $this->assertCount(1, $index->section('docs'));
        $this->assertNotInstanceOf(ContentPage::class, $index->previousInSection($docs));
        $this->assertNotInstanceOf(ContentPage::class, $index->nextInSection($docs));
        $outsidePage = $this->makePage('blog/outside', '/blog/outside/', 'blog/outside.dj', [], 'Outside');
        $this->assertNotInstanceOf(ContentPage::class, $index->previousInSection($outsidePage));
        $this->assertNotInstanceOf(ContentPage::class, $index->nextInSection($outsidePage));

        $tags = $index->taxonomy('tags');
        $this->assertTrue($tags->hasTerm('php'));
        $this->assertTrue($tags->hasTerm('guide'));
        $this->assertCount(1, $tags->term('php'));
        $this->assertCount(1, $tags->term('guide'));

        $intDate = $this->callProtected($index, 'extractDateTimestamp', $this->makePage(
            'int-date',
            '/int-date/',
            'int-date.dj',
            ['date' => 123],
            'Int Date',
        ));
        $badDate = $this->callProtected($index, 'extractDateTimestamp', $docs);
        $normalizedEmpty = $this->callProtected($index, 'normalizeUrlPath', '   ');

        $this->assertSame(123, $intDate);
        $this->assertSame(0, $badDate);
        $this->assertSame('/', $normalizedEmpty);
    }

    /**
     * Validate stable sorting fallback by relative path when title/date/weight tie.
     */
    public function testSiteIndexRegularPagesSortsByRelativePathAsLastFallback(): void
    {
        $pageA = $this->makePage('blog/a', '/blog/a/', 'blog/z.dj', ['weight' => 1, 'date' => '2026-01-01'], 'Same');
        $pageB = $this->makePage('blog/b', '/blog/b/', 'blog/a.dj', ['weight' => 1, 'date' => '2026-01-01'], 'Same');

        $index = new SiteIndex([$pageA, $pageB]);
        $sorted = $index->regularPages()->all();

        $this->assertSame('blog/b', $sorted[0]->slug);
        $this->assertSame('blog/a', $sorted[1]->slug);
    }

    /**
     * Call a protected method for branch-targeted unit coverage.
     *
     * @param object $object Target object.
     * @param string $method Protected method name.
     * @param mixed ...$args Method arguments.
     */
    protected function callProtected(object $object, string $method, mixed ...$args): mixed
    {
        $closure = Closure::bind(static function (object $instance, string $name, array $arguments): mixed {
            return $instance->{$name}(...$arguments);
        }, null, $object);

        return $closure($object, $method, $args);
    }

    /**
     * Create a content page object for test scenarios.
     *
     * @param string $slug Page slug.
     * @param string $urlPath Page URL path.
     * @param string $relativePath Relative source path.
     * @param array<string, mixed> $meta Page metadata.
     * @param string $title Page title.
     * @param array<string, array<string>> $taxonomies Page taxonomies.
     */
    protected function makePage(
        string $slug,
        string $urlPath,
        string $relativePath,
        array $meta,
        string $title,
        array $taxonomies = [],
    ): ContentPage {
        return new ContentPage(
            sourcePath: '/tmp/' . str_replace('/', '-', $slug) . '.dj',
            relativePath: $relativePath,
            slug: $slug,
            urlPath: $urlPath,
            outputRelativePath: $slug === 'index' ? 'index.html' : trim($slug, '/') . '/index.html',
            title: $title,
            source: '# ' . $title,
            draft: false,
            meta: $meta,
            taxonomies: $taxonomies,
        );
    }
}
