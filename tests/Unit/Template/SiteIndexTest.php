<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Template;

use Closure;
use Glaze\Content\ContentPage;
use Glaze\Template\Section;
use Glaze\Template\SiteIndex;
use Glaze\Tests\Helper\I18nTestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Tests for site index lookups and taxonomy mapping.
 */
final class SiteIndexTest extends TestCase
{
    use I18nTestTrait;

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
     * Validate regularPages() excludes unlisted pages by default.
     */
    public function testRegularPagesExcludesUnlistedPages(): void
    {
        $index = new SiteIndex([
            $this->makePage('index', '/', 'index.dj', ['weight' => 1], 'Home'),
            $this->makeUnlistedPage('blog', '/blog/', 'blog/_index.dj', ['weight' => 5], 'Blog Overview'),
            $this->makePage('blog/post-a', '/blog/post-a/', 'blog/post-a.dj', ['weight' => 10], 'Post A'),
            $this->makePage('blog/post-b', '/blog/post-b/', 'blog/post-b.dj', ['weight' => 20], 'Post B'),
        ]);

        $regular = $index->regularPages();
        $titles = array_map(static fn(ContentPage $p): string => $p->title, $regular->all());

        $this->assertCount(3, $regular);
        $this->assertNotContains('Blog Overview', $titles);
        $this->assertContains('Home', $titles);
        $this->assertContains('Post A', $titles);
        $this->assertContains('Post B', $titles);
    }

    /**
     * Validate allPages() includes unlisted pages.
     */
    public function testAllPagesIncludesUnlistedPages(): void
    {
        $index = new SiteIndex([
            $this->makePage('index', '/', 'index.dj', ['weight' => 1], 'Home'),
            $this->makeUnlistedPage('blog', '/blog/', 'blog/_index.dj', ['weight' => 5], 'Blog Overview'),
            $this->makePage('blog/post', '/blog/post/', 'blog/post.dj', ['weight' => 10], 'Post'),
        ]);

        $allPages = $index->allPages();

        $this->assertCount(3, $allPages);
        $titles = array_map(static fn(ContentPage $p): string => $p->title, $allPages->all());
        $this->assertContains('Blog Overview', $titles);
    }

    /**
     * Validate unlisted pages are still findable by slug and URL path.
     */
    public function testUnlistedPagesAreStillFindableBySlugAndUrl(): void
    {
        $index = new SiteIndex([
            $this->makeUnlistedPage('blog', '/blog/', 'blog/_index.dj', ['weight' => 5], 'Blog Overview'),
            $this->makePage('blog/post', '/blog/post/', 'blog/post.dj', ['weight' => 10], 'Post'),
        ]);

        $this->assertSame('Blog Overview', $index->findBySlug('blog')?->title);
        $this->assertSame('Blog Overview', $index->findByUrlPath('/blog/')?->title);
    }

    /**
     * Validate sections() returns top-level section nodes ordered by section weight.
     */
    public function testSectionsReturnsOrderedSectionMap(): void
    {
        $index = new SiteIndex([
            $this->makePage('index', '/', 'index.dj', ['weight' => 1], 'Home'),
            $this->makePage('reference/commands', '/reference/commands/', 'reference/commands.dj', ['weight' => 100], 'Commands'),
            $this->makePage('getting-started/index', '/getting-started/', 'getting-started/index.dj', ['weight' => 5], 'Getting started'),
            $this->makePage('getting-started/install', '/getting-started/install/', 'getting-started/install.dj', ['weight' => 20], 'Install'),
            $this->makePage('content/routing', '/content/routing/', 'content/routing.dj', ['weight' => 45], 'Routing'),
        ]);

        $sections = $index->sections();

        $this->assertCount(3, $sections);
        $this->assertSame(['getting-started', 'content', 'reference'], array_keys($sections));
        $this->assertSame('Getting started', $sections['getting-started']->label());
        $this->assertCount(2, $sections['getting-started']->pages());
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
     * Validate rootPages() returns only pages in the content root section.
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
     * Validate section nodes expose index metadata and nested child sections.
     */
    public function testSectionNodeExposesIndexAndChildren(): void
    {
        $guideIndex = $this->makePage('docs/guides/index', '/docs/guides/', 'docs/guides/index.dj', ['weight' => 9], 'Guides');
        $guidePage = $this->makePage('docs/guides/first', '/docs/guides/first/', 'docs/guides/first.dj', ['weight' => 11], 'First Guide');
        $docsIndex = $this->makePage('docs/index', '/docs/', 'docs/index.dj', ['weight' => 10], 'Documentation');

        $index = new SiteIndex([$guideIndex, $guidePage, $docsIndex]);

        $docs = $index->sectionNode('docs') ?? $this->fail('Missing docs section');
        $guides = $index->sectionNode('docs/guides') ?? $this->fail('Missing docs/guides section');

        $this->assertSame('Documentation', $docs->label());
        $this->assertSame($docsIndex->slug, $docs->index()?->slug);
        $this->assertTrue($docs->hasChildren());
        $this->assertSame($guides, $docs->child('guides'));

        $this->assertSame('Guides', $guides->label());
        $this->assertSame($guideIndex->slug, $guides->index()?->slug);
        $this->assertCount(2, $guides->pages());
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
        $this->assertSame('Reference Docs', $index->sections()['reference']->label());
    }

    /**
     * Validate tree flattening can resolve nested section paths.
     */
    public function testSectionNodeSupportsNestedPaths(): void
    {
        $index = new SiteIndex([
            $this->makePage('my-section/page', '/my-section/page/', 'my-section/page.dj', ['weight' => 10], 'Section Page'),
            $this->makePage('my-section/sub-section/page', '/my-section/sub-section/page/', 'my-section/sub-section/page.dj', ['weight' => 20], 'Subsection Page'),
        ]);

        $parent = $index->sectionNode('my-section');
        $child = $index->sectionNode('my-section/sub-section');

        $this->assertInstanceOf(Section::class, $parent);
        $this->assertInstanceOf(Section::class, $child);
        $this->assertSame($child, $parent->child('sub-section'));
    }

    /**
     * Validate unlisted _index pages serve as section index providing label and weight.
     *
     * An unlisted `_index.dj` page should provide the section label and weight
     * without appearing in the section's pages() collection.
     */
    public function testUnlistedIndexPageProvidesSectionLabelAndWeight(): void
    {
        $blogIndex = $this->makeUnlistedPage('blog', '/blog/', 'blog/_index.dj', ['weight' => 5], 'Blog Articles');
        $postA = $this->makePage('blog/post-a', '/blog/post-a/', 'blog/post-a.dj', ['weight' => 10], 'Post A');
        $postB = $this->makePage('blog/post-b', '/blog/post-b/', 'blog/post-b.dj', ['weight' => 20], 'Post B');

        $index = new SiteIndex([$blogIndex, $postA, $postB]);

        $blog = $index->sectionNode('blog') ?? $this->fail('Missing blog section');

        // Label comes from the unlisted _index page
        $this->assertSame('Blog Articles', $blog->label());

        // Weight comes from the unlisted _index page
        $this->assertSame(5, $blog->weight());

        // Index page reference is set
        $this->assertSame('blog', $blog->index()?->slug);

        // Unlisted _index page does not appear in section pages
        $this->assertCount(2, $blog->pages());
        $titles = array_map(static fn(ContentPage $p): string => $p->title, $blog->pages()->all());
        $this->assertNotContains('Blog Articles', $titles);
        $this->assertSame(['Post A', 'Post B'], $titles);
    }

    /**
     * Validate section ordering uses unlisted _index page weights.
     *
     * Sections with unlisted index pages should be ordered by the index page
     * weight, just like sections with listed index pages.
     */
    public function testSectionOrderingUsesUnlistedIndexPageWeight(): void
    {
        $refIndex = $this->makeUnlistedPage('reference', '/reference/', 'reference/_index.dj', ['weight' => 5], 'Reference');
        $commands = $this->makePage('reference/commands', '/reference/commands/', 'reference/commands.dj', ['weight' => 100], 'Commands');
        $install = $this->makePage('getting-started/install', '/getting-started/install/', 'getting-started/install.dj', ['weight' => 20], 'Install');
        $routing = $this->makePage('content/routing', '/content/routing/', 'content/routing.dj', ['weight' => 45], 'Routing');

        $index = new SiteIndex([$refIndex, $commands, $install, $routing]);

        $sectionKeys = array_keys($index->sections());
        // reference first (unlisted _index weight 5), then getting-started (min weight 20), then content (min weight 45)
        $this->assertSame(['reference', 'getting-started', 'content'], $sectionKeys);
        $this->assertSame('Reference', $index->sections()['reference']->label());
    }

    /**
     * Validate unlisted _index pages in nested sections work correctly.
     */
    public function testNestedUnlistedIndexPages(): void
    {
        $docsIndex = $this->makeUnlistedPage('docs', '/docs/', 'docs/_index.dj', ['weight' => 1], 'Documentation');
        $guidesIndex = $this->makeUnlistedPage('docs/guides', '/docs/guides/', 'docs/guides/_index.dj', ['weight' => 9], 'Guides');
        $guidePage = $this->makePage('docs/guides/first', '/docs/guides/first/', 'docs/guides/first.dj', ['weight' => 11], 'First Guide');

        $index = new SiteIndex([$docsIndex, $guidesIndex, $guidePage]);

        $docs = $index->sectionNode('docs') ?? $this->fail('Missing docs section');
        $guides = $index->sectionNode('docs/guides') ?? $this->fail('Missing docs/guides section');

        // Labels come from unlisted _index pages
        $this->assertSame('Documentation', $docs->label());
        $this->assertSame('Guides', $guides->label());

        // Index references are set
        $this->assertSame('docs', $docs->index()?->slug);
        $this->assertSame('docs/guides', $guides->index()?->slug);

        // Unlisted _index pages don't appear in section pages
        $this->assertCount(0, $docs->pages());
        $this->assertCount(1, $guides->pages());
        $this->assertSame('First Guide', $guides->pages()->first()?->title);
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
        $this->assertNotInstanceOf(ContentPage::class, $index->previous($intro));
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
        $this->assertNotInstanceOf(ContentPage::class, $index->next($commands));
    }

    /**
     * Validate previous()/next() can skip pages using a predicate.
     */
    public function testPreviousAndNextSkipHiddenPagesWithPredicate(): void
    {
        $intro = $this->makePage('intro', '/intro/', 'intro.dj', ['weight' => 0], 'Intro');
        $hidden = $this->makePage('docs/hidden', '/docs/hidden/', 'docs/hidden.dj', ['weight' => 10, 'navigation' => false], 'Hidden');
        $visible = $this->makePage('docs/visible', '/docs/visible/', 'docs/visible.dj', ['weight' => 20], 'Visible');

        $index = new SiteIndex([$intro, $hidden, $visible]);

        $isNavigable = static fn(ContentPage $page): bool => (bool)($page->meta('navigation') ?? true);

        $this->assertSame('Visible', $index->next($intro, $isNavigable)?->title);
        $this->assertSame('Intro', $index->previous($visible, $isNavigable)?->title);
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
        $this->assertNotInstanceOf(ContentPage::class, $index->previous($outside));
        $this->assertNotInstanceOf(ContentPage::class, $index->next($outside));
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

    /**
     * Create an unlisted content page for test scenarios.
     *
     * @param string $slug Page slug.
     * @param string $urlPath Page URL path.
     * @param string $relativePath Relative source path.
     * @param array<string, mixed> $meta Page metadata.
     * @param string $title Page title.
     * @param array<string, array<string>> $taxonomies Page taxonomies.
     */
    protected function makeUnlistedPage(
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
            unlisted: true,
        );
    }

    // -------------------------------------------------------------------------
    // i18n: forLanguage()
    // -------------------------------------------------------------------------

    /**
     * Validate forLanguage() returns pages for the specified language only.
     */
    public function testForLanguageFiltersPagesByLanguage(): void
    {
        $enAbout = $this->makeLocalizedPage('about', '/about/', 'about.dj', 'en', 'about.dj', 'About');
        $enHome = $this->makeLocalizedPage('index', '/', 'index.dj', 'en', 'index.dj', 'Home');
        $nlAbout = $this->makeLocalizedPage('nl/about', '/nl/about/', 'about.dj', 'nl', 'about.dj', 'Over ons');

        $index = new SiteIndex([$enAbout, $enHome, $nlAbout]);

        $enPages = $index->forLanguage('en');
        $this->assertCount(2, $enPages);
        foreach ($enPages->all() as $page) {
            $this->assertSame('en', $page->language);
        }

        $nlPages = $index->forLanguage('nl');
        $this->assertCount(1, $nlPages);
        $this->assertSame('nl', $nlPages->first()?->language);
    }

    /**
     * Validate forLanguage() returns an empty collection for an unknown language.
     */
    public function testForLanguageReturnsEmptyForUnknownLanguage(): void
    {
        $enHome = $this->makeLocalizedPage('index', '/', 'index.dj', 'en', 'index.dj', 'Home');
        $index = new SiteIndex([$enHome]);

        $frPages = $index->forLanguage('fr');
        $this->assertCount(0, $frPages);
    }

    /**
     * Validate forLanguage() returns an empty collection when i18n is disabled (no language tags).
     */
    public function testForLanguageReturnsEmptyWhenNoLanguageTags(): void
    {
        $page = $this->makePage('index', '/', 'index.dj', [], 'Home');
        $index = new SiteIndex([$page]);

        $this->assertCount(0, $index->forLanguage('en'));
    }

    // -------------------------------------------------------------------------
    // i18n: translations()
    // -------------------------------------------------------------------------

    /**
     * Validate translations() returns all pages sharing the same translationKey.
     */
    public function testTranslationsReturnsAllMatchingTranslations(): void
    {
        $enAbout = $this->makeLocalizedPage('about', '/about/', 'about.dj', 'en', 'about.dj', 'About');
        $nlAbout = $this->makeLocalizedPage('nl/about', '/nl/about/', 'about.dj', 'nl', 'about.dj', 'Over ons');
        $enHome = $this->makeLocalizedPage('index', '/', 'index.dj', 'en', 'index.dj', 'Home');

        $index = new SiteIndex([$enAbout, $nlAbout, $enHome]);

        $translations = $index->translations($enAbout);

        $this->assertArrayHasKey('en', $translations);
        $this->assertArrayHasKey('nl', $translations);
        $this->assertSame('About', $translations['en']->title);
        $this->assertSame('Over ons', $translations['nl']->title);
    }

    /**
     * Validate translations() returns an empty array when the page has no translationKey.
     */
    public function testTranslationsReturnsEmptyForPageWithNoTranslationKey(): void
    {
        $page = $this->makePage('about', '/about/', 'about.dj', [], 'About');
        $index = new SiteIndex([$page]);

        $this->assertSame([], $index->translations($page));
    }

    /**
     * Validate translations() excludes pages with an empty language field.
     */
    public function testTranslationsExcludesPagesWithEmptyLanguage(): void
    {
        // Page with translationKey but no language tag (non-i18n site)
        $nonI18nPage = new ContentPage(
            sourcePath: '/tmp/about.dj',
            relativePath: 'about.dj',
            slug: 'about',
            urlPath: '/about/',
            outputRelativePath: 'about/index.html',
            title: 'About',
            source: '# About',
            draft: false,
            meta: [],
            language: '',
            translationKey: 'about.dj',
        );

        $index = new SiteIndex([$nonI18nPage]);

        $this->assertSame([], $index->translations($nonI18nPage));
    }

    // -------------------------------------------------------------------------
    // i18n: translation()
    // -------------------------------------------------------------------------

    /**
     * Validate translation() returns the correct page for the requested language.
     */
    public function testTranslationReturnsCorrectPageForLanguage(): void
    {
        $enAbout = $this->makeLocalizedPage('about', '/about/', 'about.dj', 'en', 'about.dj', 'About');
        $nlAbout = $this->makeLocalizedPage('nl/about', '/nl/about/', 'about.dj', 'nl', 'about.dj', 'Over ons');

        $index = new SiteIndex([$enAbout, $nlAbout]);

        $this->assertSame($nlAbout, $index->translation($enAbout, 'nl'));
        $this->assertSame($enAbout, $index->translation($nlAbout, 'en'));
    }

    /**
     * Validate translation() returns null when no match exists for the requested language.
     */
    public function testTranslationReturnsNullWhenNoMatchForLanguage(): void
    {
        $enAbout = $this->makeLocalizedPage('about', '/about/', 'about.dj', 'en', 'about.dj', 'About');
        $index = new SiteIndex([$enAbout]);

        $this->assertNotInstanceOf(ContentPage::class, $index->translation($enAbout, 'fr'));
    }
}
