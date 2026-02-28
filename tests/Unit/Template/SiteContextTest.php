<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Template;

use Glaze\Content\ContentPage;
use Glaze\Template\Extension\ExtensionRegistry;
use Glaze\Template\SiteContext;
use Glaze\Template\SiteIndex;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for template context facade behavior.
 */
final class SiteContextTest extends TestCase
{
    /**
     * Validate context query and pagination helpers.
     */
    public function testContextExposesQueryAndPaginationHelpers(): void
    {
        $index = new SiteIndex([
            $this->makePage('index', '/', 'index.dj', ['type' => 'page'], ['tags' => ['home']]),
            $this->makePage('blog/a', '/blog/a/', 'blog/a.dj', ['date' => '2026-01-01', 'type' => 'blog'], ['tags' => ['php']]),
            $this->makePage('blog/b', '/blog/b/', 'blog/b.dj', ['date' => '2026-01-02', 'type' => 'blog'], ['tags' => ['php']]),
        ]);

        $context = new SiteContext($index, $index->findBySlug('blog/a') ?? $this->fail('Missing current page'));
        $blogSection = $context->section('blog') ?? $this->fail('Missing blog section');

        $this->assertSame('blog/a', $context->page()->slug);
        $this->assertCount(3, $context->regularPages());
        $this->assertCount(2, $blogSection);
        $this->assertCount(2, $context->type('blog'));
        $this->assertCount(2, $context->taxonomy('tags')->term('php'));
        $this->assertCount(2, $context->taxonomyTerm('tags', 'php'));
        $this->assertSame('blog/b', $context->previousInSection()?->slug);

        $pager = $context->paginate($blogSection, 1, 2, '/blog/');
        $this->assertSame(2, $pager->totalPages());
        $this->assertSame('/blog/page/2/', $pager->url());
        $this->assertSame('/blog/', $pager->prevUrl());
        $this->assertNull($pager->nextUrl());
    }

    /**
     * Validate current URL matching and where helper.
     */
    public function testContextCurrentAndWhereHelpers(): void
    {
        $index = new SiteIndex([
            $this->makePage('index', '/', 'index.dj', [], ['tags' => ['home']]),
            $this->makePage('docs/intro', '/docs/intro/', 'docs/intro.dj', [], ['tags' => ['docs']]),
        ]);

        $context = new SiteContext($index, $index->findBySlug('docs/intro') ?? $this->fail('Missing page'));

        $filtered = $context->where($context->regularPages(), 'slug', 'docs/intro');

        $this->assertTrue($context->isCurrent('/docs/intro/'));
        $this->assertFalse($context->isCurrent('/'));
        $this->assertCount(1, $filtered);
        $this->assertSame('docs/intro', $filtered->first()?->slug);

        $this->assertCount(2, $context->pages());
        $this->assertSame('docs/intro', $context->bySlug('docs/intro')?->slug);
        $this->assertSame('docs/intro', $context->byUrl('/docs/intro/')?->slug);
        $this->assertNotInstanceOf(ContentPage::class, $context->bySlug('missing'));
        $this->assertNotInstanceOf(ContentPage::class, $context->byUrl('/missing/'));

        $arrayFiltered = $context->where($context->regularPages()->all(), 'slug', 'docs/intro');
        $this->assertCount(1, $arrayFiltered);

        $operatorFiltered = $context->where($context->regularPages(), 'slug', 'eq', 'docs/intro');
        $this->assertCount(1, $operatorFiltered);

        $docsSection = $context->section('docs') ?? $this->fail('Missing docs section');
        $defaultPager = $context->paginate($docsSection, 1, 1);
        $this->assertSame('/docs/intro/', $defaultPager->url());
        $this->assertSame($context, $context->site());

        $homeContext = new SiteContext($index, $index->findBySlug('index') ?? $this->fail('Missing home page'));
        $this->assertTrue($homeContext->isCurrent('/index'));
        $this->assertTrue($homeContext->isCurrent('   '));
    }

    /**
     * Validate that the extension() method delegates to the injected ExtensionRegistry.
     */
    public function testExtensionMethodDelegatesToRegistry(): void
    {
        $registry = new ExtensionRegistry();
        $registry->register('greet', fn(string $who) => sprintf('Hello, %s!', $who));

        $index = new SiteIndex([
            $this->makePage('index', '/', 'index.dj', []),
        ]);

        $context = new SiteContext(
            $index,
            $index->findBySlug('index') ?? $this->fail('Missing index page'),
            $registry,
        );

        $this->assertSame('Hello, World!', $context->extension('greet', 'World'));
    }

    /**
     * Validate that extension() throws RuntimeException for unknown names.
     */
    public function testExtensionMethodThrowsForUnknownName(): void
    {
        $index = new SiteIndex([
            $this->makePage('index', '/', 'index.dj', []),
        ]);

        $context = new SiteContext(
            $index,
            $index->findBySlug('index') ?? $this->fail('Missing index page'),
        );

        $this->expectException(RuntimeException::class);

        $context->extension('nonexistent');
    }

    /**
     * Validate sections(), rootPages(), and tree/section access delegation.
     */
    public function testContextExposesSectionsAndRootPages(): void
    {
        $index = new SiteIndex([
            $this->makePage('index', '/', 'index.dj', ['weight' => 1]),
            $this->makePage('docs/intro', '/docs/intro/', 'docs/intro.dj', ['weight' => 10]),
            $this->makePage('docs/setup', '/docs/setup/', 'docs/setup.dj', ['weight' => 20]),
            $this->makePage('blog/post', '/blog/post/', 'blog/post.dj', ['weight' => 30]),
        ]);

        $context = new SiteContext($index, $index->findBySlug('index') ?? $this->fail('Missing page'));

        $sections = $context->sections();
        $this->assertCount(2, $sections);
        $this->assertArrayHasKey('docs', $sections);
        $this->assertArrayHasKey('blog', $sections);
        $this->assertCount(2, $sections['docs']);
        $this->assertSame('Docs', $sections['docs']->label());
        $this->assertSame('docs', $sections['docs']->path());
        $this->assertSame($context->tree(), $context->section(''));

        $root = $context->rootPages();
        $this->assertCount(1, $root);
    }

    /**
     * Validate previous()/next() delegation through SiteContext with weight interleaving.
     */
    public function testContextExposesPreviousAndNext(): void
    {
        $index = new SiteIndex([
            $this->makePage('intro', '/intro/', 'intro.dj', ['weight' => 0]),
            $this->makePage('docs/a', '/docs/a/', 'docs/a.dj', ['weight' => 10]),
            $this->makePage('docs/b', '/docs/b/', 'docs/b.dj', ['weight' => 20]),
        ]);

        // Root page with weight 0 comes first
        $introContext = new SiteContext($index, $index->findBySlug('intro') ?? $this->fail('Missing page'));
        $this->assertNotInstanceOf(ContentPage::class, $introContext->previous());
        $this->assertSame('docs/a', $introContext->next()?->slug);

        $aContext = new SiteContext($index, $index->findBySlug('docs/a') ?? $this->fail('Missing page'));
        $this->assertSame('intro', $aContext->previous()?->slug);
        $this->assertSame('docs/b', $aContext->next()?->slug);

        $bContext = new SiteContext($index, $index->findBySlug('docs/b') ?? $this->fail('Missing page'));
        $this->assertSame('docs/a', $bContext->previous()?->slug);
        $this->assertNotInstanceOf(ContentPage::class, $bContext->next());
    }

    /**
     * Validate previous()/next() support skipping pages via predicate callbacks.
     */
    public function testContextPreviousAndNextSupportPredicate(): void
    {
        $index = new SiteIndex([
            $this->makePage('intro', '/intro/', 'intro.dj', ['weight' => 0]),
            $this->makePage('docs/hidden', '/docs/hidden/', 'docs/hidden.dj', ['weight' => 10, 'navigation' => false]),
            $this->makePage('docs/visible', '/docs/visible/', 'docs/visible.dj', ['weight' => 20]),
        ]);

        $introContext = new SiteContext($index, $index->findBySlug('intro') ?? $this->fail('Missing page'));
        $visibleContext = new SiteContext($index, $index->findBySlug('docs/visible') ?? $this->fail('Missing page'));

        $isNavigable = static fn(ContentPage $page): bool => (bool)($page->meta('navigation') ?? true);

        $this->assertSame('docs/visible', $introContext->next($isNavigable)?->slug);
        $this->assertSame('intro', $visibleContext->previous($isNavigable)?->slug);
    }

    /**
     * Create a content page object for test scenarios.
     *
     * @param string $slug Page slug.
     * @param string $urlPath Page URL path.
     * @param string $relativePath Relative source path.
     * @param array<string, mixed> $meta Page metadata.
     * @param array<string, array<string>> $taxonomies Page taxonomies.
     */
    protected function makePage(
        string $slug,
        string $urlPath,
        string $relativePath,
        array $meta,
        array $taxonomies = [],
    ): ContentPage {
        return new ContentPage(
            sourcePath: '/tmp/' . str_replace('/', '-', $slug) . '.dj',
            relativePath: $relativePath,
            slug: $slug,
            urlPath: $urlPath,
            outputRelativePath: $slug === 'index' ? 'index.html' : trim($slug, '/') . '/index.html',
            title: ucfirst(basename($slug)),
            source: '# ' . $slug,
            draft: false,
            meta: $meta,
            taxonomies: $taxonomies,
        );
    }
}
