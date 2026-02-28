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
     * Validate computed index collections are memoized and reused.
     */
    public function testSiteIndexMemoizesComputedCollections(): void
    {
        $index = new SiteIndex([
            $this->makePage('index', '/', 'index.dj', ['weight' => 1], 'Home', ['tags' => ['home']]),
            $this->makePage('blog/post', '/blog/post/', 'blog/post.dj', ['weight' => 2], 'Post', ['tags' => ['php']]),
        ]);

        $regularPages = $index->regularPages();
        $regularPagesAgain = $index->regularPages();
        $blogSection = $index->section('blog');
        $blogSectionAgain = $index->section('blog');
        $tags = $index->taxonomy('tags');
        $tagsAgain = $index->taxonomy('tags');

        $this->assertSame($regularPages, $regularPagesAgain);
        $this->assertSame($blogSection, $blogSectionAgain);
        $this->assertSame($tags, $tagsAgain);
    }

    /**
     * Validate sections() returns non-root sections ordered by minimum page weight.
     */
    public function testSectionsReturnsOrderedSectionMap(): void
    {
        $index = new SiteIndex([
            $this->makePage('index', '/', 'index.dj', ['weight' => 1], 'Home'),
            $this->makePage('reference/commands', '/reference/commands/', 'reference/commands.dj', ['weight' => 100], 'Commands'),
            $this->makePage('getting-started/install', '/getting-started/install/', 'getting-started/install.dj', ['weight' => 20], 'Install'),
            $this->makePage('getting-started/quick-start', '/getting-started/quick-start.dj', 'getting-started/quick-start.dj', ['weight' => 30], 'Quick Start'),
            $this->makePage('content/routing', '/content/routing/', 'content/routing.dj', ['weight' => 45], 'Routing'),
        ]);

        $sections = $index->sections();

        $this->assertCount(3, $sections);
        $sectionKeys = array_keys($sections);
        $this->assertSame(['getting-started', 'content', 'reference'], $sectionKeys);
        $this->assertCount(2, $sections['getting-started']);
        $this->assertCount(1, $sections['content']);
        $this->assertCount(1, $sections['reference']);
    }

    /**
     * Validate sections() is memoized on repeated calls.
     */
    public function testSectionsIsMemoized(): void
    {
        $index = new SiteIndex([
            $this->makePage('docs/a', '/docs/a/', 'docs/a.dj', ['weight' => 1], 'A'),
        ]);

        $first = $index->sections();
        $second = $index->sections();

        $this->assertSame($first, $second);
    }

    /**
     * Validate rootPages() returns only pages without a section.
     */
    public function testRootPagesReturnsUnsectionedPages(): void
    {
        $index = new SiteIndex([
            $this->makePage('index', '/', 'index.dj', ['weight' => 1], 'Home'),
            $this->makePage('about', '/about/', 'about.dj', ['weight' => 5], 'About'),
            $this->makePage('docs/intro', '/docs/intro/', 'docs/intro.dj', ['weight' => 10], 'Intro'),
        ]);

        $root = $index->rootPages();
        $this->assertCount(2, $root);
        $titles = array_map(static fn(ContentPage $p): string => $p->title, $root->all());
        $this->assertSame(['Home', 'About'], $titles);
    }

    /**
     * Validate sectionLabel() humanizes section keys.
     */
    public function testSectionLabelHumanizesKeys(): void
    {
        $index = new SiteIndex([]);

        $this->assertSame('Getting Started', $index->sectionLabel('getting-started'));
        $this->assertSame('Content', $index->sectionLabel('content'));
        $this->assertSame('Reference', $index->sectionLabel('reference'));
    }

    /**
     * Validate sectionLabel() uses the index page title when present.
     */
    public function testSectionLabelUsesIndexPageTitle(): void
    {
        $indexPage = $this->makePage('getting-started', '/getting-started/', 'getting-started/index.dj', ['weight' => 10], 'Getting started');
        $install = $this->makePage('getting-started/install', '/getting-started/install/', 'getting-started/install.dj', ['weight' => 20], 'Install');

        $index = new SiteIndex([$indexPage, $install]);

        // Uses the index page title (lowercase "s") instead of humanized
        $this->assertSame('Getting started', $index->sectionLabel('getting-started'));
    }

    /**
     * Validate findSectionIndex() returns the index page for a section.
     */
    public function testFindSectionIndexReturnsIndexPage(): void
    {
        $indexPage = $this->makePage('getting-started', '/getting-started/', 'getting-started/index.dj', ['weight' => 10], 'Getting started');
        $install = $this->makePage('getting-started/install', '/getting-started/install/', 'getting-started/install.dj', ['weight' => 20], 'Install');
        $routing = $this->makePage('content/routing', '/content/routing/', 'content/routing.dj', ['weight' => 45], 'Routing');

        $index = new SiteIndex([$indexPage, $install, $routing]);

        $this->assertSame($indexPage, $index->findSectionIndex('getting-started'));
        $this->assertNull($index->findSectionIndex('content'));
        $this->assertNull($index->findSectionIndex(''));
        $this->assertNull($index->findSectionIndex('nonexistent'));
    }

    /**
     * Validate sections are ordered by index page weight when present.
     */
    public function testSectionsOrderedByIndexPageWeight(): void
    {
        // "reference" gets its own index page with weight 5, overriding its only page's weight of 100
        $refIndex = $this->makePage('reference', '/reference/', 'reference/index.dj', ['weight' => 5], 'Reference Docs');
        $commands = $this->makePage('reference/commands', '/reference/commands/', 'reference/commands.dj', ['weight' => 100], 'Commands');
        $install = $this->makePage('getting-started/install', '/getting-started/install/', 'getting-started/install.dj', ['weight' => 20], 'Install');
        $routing = $this->makePage('content/routing', '/content/routing/', 'content/routing.dj', ['weight' => 45], 'Routing');

        $index = new SiteIndex([$refIndex, $commands, $install, $routing]);

        $sectionKeys = array_keys($index->sections());
        // reference first (index weight 5), then getting-started (min weight 20), then content (min weight 45)
        $this->assertSame(['reference', 'getting-started', 'content'], $sectionKeys);

        // Label comes from the index page title
        $this->assertSame('Reference Docs', $index->sectionLabel('reference'));
    }

    /**
     * Validate previous()/next() navigate across section boundaries with weight interleaving.
     */
    public function testPreviousAndNextCrossSectionBoundaries(): void
    {
        $intro = $this->makePage('what-is-glaze', '/what-is-glaze/', 'what-is-glaze.dj', ['weight' => 0], 'What is Glaze?');
        $install = $this->makePage('getting-started/install', '/getting-started/install/', 'getting-started/install.dj', ['weight' => 20], 'Install');
        $quickStart = $this->makePage('getting-started/quick-start', '/getting-started/quick-start/', 'getting-started/quick-start.dj', ['weight' => 30], 'Quick Start');
        $routing = $this->makePage('content/routing', '/content/routing/', 'content/routing.dj', ['weight' => 45], 'Routing');
        $commands = $this->makePage('reference/commands', '/reference/commands/', 'reference/commands.dj', ['weight' => 100], 'Commands');

        $index = new SiteIndex([$intro, $install, $quickStart, $routing, $commands]);

        // Root page with weight 0 comes first — no previous
        $this->assertNull($index->previous($intro));
        $this->assertSame('Install', $index->next($intro)?->title);

        // Install follows intro (root page → section boundary)
        $this->assertSame('What is Glaze?', $index->previous($install)?->title);
        $this->assertSame('Quick Start', $index->next($install)?->title);

        // Quick Start → Routing crosses getting-started → content boundary
        $this->assertSame('Install', $index->previous($quickStart)?->title);
        $this->assertSame('Routing', $index->next($quickStart)?->title);

        // Routing → Commands crosses content → reference boundary
        $this->assertSame('Quick Start', $index->previous($routing)?->title);
        $this->assertSame('Commands', $index->next($routing)?->title);

        // Commands is last — no next
        $this->assertSame('Routing', $index->previous($commands)?->title);
        $this->assertNull($index->next($commands));
    }

    /**
     * Validate previous()/next() return null for a page not in the index.
     */
    public function testPreviousAndNextReturnNullForUnknownPage(): void
    {
        $index = new SiteIndex([
            $this->makePage('docs/a', '/docs/a/', 'docs/a.dj', ['weight' => 1], 'A'),
        ]);

        $outside = $this->makePage('docs/outside', '/docs/outside/', 'docs/outside.dj', [], 'Outside');
        $this->assertNull($index->previous($outside));
        $this->assertNull($index->next($outside));
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
