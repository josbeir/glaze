<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Build;

use Glaze\Build\TaxonomyPageFactory;
use Glaze\Config\TaxonomyConfig;
use Glaze\Content\ContentPage;
use PHPUnit\Framework\TestCase;

/**
 * Tests for taxonomy list and term page generation.
 */
final class TaxonomyPageFactoryTest extends TestCase
{
    /**
     * Ensure no pages are generated when no taxonomy has generatePages enabled.
     */
    public function testGenerateReturnsEmptyWhenNoTaxonomyEnabled(): void
    {
        $factory = new TaxonomyPageFactory();
        $pages = $factory->generate(
            [$this->makePage(['tags' => ['php', 'cake']])],
            ['tags' => new TaxonomyConfig('tags', generatePages: false)],
        );

        $this->assertSame([], $pages);
    }

    /**
     * Ensure generate returns empty when taxonomy map is empty.
     */
    public function testGenerateReturnsEmptyForEmptyTaxonomyMap(): void
    {
        $factory = new TaxonomyPageFactory();
        $pages = $factory->generate([$this->makePage(['tags' => ['php']])], []);

        $this->assertSame([], $pages);
    }

    /**
     * Ensure a list page and one term page are generated per distinct term.
     */
    public function testGenerateCreatesListAndTermPages(): void
    {
        $factory = new TaxonomyPageFactory();
        $pages = $factory->generate(
            [
                $this->makePage(['tags' => ['php', 'cake']]),
                $this->makePage(['tags' => ['php', 'tutorial']]),
            ],
            ['tags' => new TaxonomyConfig('tags', generatePages: true)],
        );

        // 1 list page + 3 distinct terms
        $this->assertCount(4, $pages);

        $slugs = array_map(static fn(ContentPage $p): string => $p->slug, $pages);
        $this->assertContains('tags', $slugs);
        $this->assertContains('tags/cake', $slugs);
        $this->assertContains('tags/php', $slugs);
        $this->assertContains('tags/tutorial', $slugs);
    }

    /**
     * Ensure the list page carries correct URL path and output path.
     */
    public function testListPageProperties(): void
    {
        $factory = new TaxonomyPageFactory();
        $pages = $factory->generate(
            [$this->makePage(['tags' => ['php']])],
            ['tags' => new TaxonomyConfig('tags', generatePages: true)],
        );

        $listPage = $this->findBySlug($pages, 'tags');
        $this->assertInstanceOf(ContentPage::class, $listPage);
        $this->assertSame('/tags/', $listPage->urlPath);
        $this->assertSame('tags/index.html', $listPage->outputRelativePath);
        $this->assertSame('Tags', $listPage->title);
        $this->assertTrue($listPage->unlisted);
        $this->assertFalse($listPage->draft);
        $this->assertFalse($listPage->virtual);
        $this->assertSame('', $listPage->source);
        $this->assertSame('tags', $listPage->meta['taxonomy']);
        $this->assertSame('taxonomy/list', $listPage->meta['template']);
        $this->assertArrayNotHasKey('term', $listPage->meta);
    }

    /**
     * Ensure term pages carry correct URL path and output path.
     */
    public function testTermPageProperties(): void
    {
        $factory = new TaxonomyPageFactory();
        $pages = $factory->generate(
            [$this->makePage(['tags' => ['php']])],
            ['tags' => new TaxonomyConfig('tags', generatePages: true)],
        );

        $termPage = $this->findBySlug($pages, 'tags/php');
        $this->assertInstanceOf(ContentPage::class, $termPage);
        $this->assertSame('/tags/php/', $termPage->urlPath);
        $this->assertSame('tags/php/index.html', $termPage->outputRelativePath);
        $this->assertSame('php', $termPage->title);
        $this->assertTrue($termPage->unlisted);
        $this->assertFalse($termPage->draft);
        $this->assertFalse($termPage->virtual);
        $this->assertSame('', $termPage->source);
        $this->assertSame('tags', $termPage->meta['taxonomy']);
        $this->assertSame('php', $termPage->meta['term']);
        $this->assertSame('taxonomy/term', $termPage->meta['template']);
    }

    /**
     * Ensure term slug derived from raw term value is slugified using Text::slug.
     */
    public function testTermSlugification(): void
    {
        $factory = new TaxonomyPageFactory();
        $pages = $factory->generate(
            [$this->makePage(['tags' => ['PHP 8', 'C++', 'My Tag']])],
            ['tags' => new TaxonomyConfig('tags', generatePages: true)],
        );

        $slugs = array_map(static fn(ContentPage $p): string => $p->slug, $pages);
        $this->assertContains('tags/php-8', $slugs);
        $this->assertContains('tags/c', $slugs);
        $this->assertContains('tags/my-tag', $slugs);
    }

    /**
     * Ensure raw term value (not slug) is stored in page meta term key.
     */
    public function testTermPagePreservesRawTermInMeta(): void
    {
        $factory = new TaxonomyPageFactory();
        $pages = $factory->generate(
            [$this->makePage(['tags' => ['PHP 8']])],
            ['tags' => new TaxonomyConfig('tags', generatePages: true)],
        );

        $termPage = $this->findBySlug($pages, 'tags/php-8');
        $this->assertInstanceOf(ContentPage::class, $termPage);
        $this->assertSame('PHP 8', $termPage->meta['term']);
        $this->assertSame('PHP 8', $termPage->title);
    }

    /**
     * Ensure duplicate terms across pages produce a single term page.
     */
    public function testDuplicateTermsProduceSingleTermPage(): void
    {
        $factory = new TaxonomyPageFactory();
        $pages = $factory->generate(
            [
                $this->makePage(['tags' => ['php']]),
                $this->makePage(['tags' => ['php']]),
                $this->makePage(['tags' => ['php']]),
            ],
            ['tags' => new TaxonomyConfig('tags', generatePages: true)],
        );

        // 1 list + 1 term
        $this->assertCount(2, $pages);
    }

    /**
     * Ensure terms are sorted alphabetically in the generated order.
     */
    public function testTermsAreGeneratedInAlphabeticalOrder(): void
    {
        $factory = new TaxonomyPageFactory();
        $pages = $factory->generate(
            [$this->makePage(['tags' => ['zebra', 'apple', 'mango']])],
            ['tags' => new TaxonomyConfig('tags', generatePages: true)],
        );

        // Skip the list page (first), then check term order
        $termSlugs = array_map(
            static fn(ContentPage $p): string => $p->slug,
            array_slice($pages, 1),
        );
        $this->assertSame(['tags/apple', 'tags/mango', 'tags/zebra'], $termSlugs);
    }

    /**
     * Ensure no pages are generated when content pages have no matching taxonomy terms.
     */
    public function testGenerateWithNoTermsProducesOnlyListPage(): void
    {
        $factory = new TaxonomyPageFactory();
        $pages = $factory->generate(
            [$this->makePage([])],
            ['tags' => new TaxonomyConfig('tags', generatePages: true)],
        );

        // Only the list page — no terms
        $this->assertCount(1, $pages);
        $this->assertSame('tags', $pages[0]->slug);
    }

    /**
     * Ensure custom basePath changes URL prefix of generated pages.
     */
    public function testCustomBasePathIsReflectedInGeneratedUrls(): void
    {
        $factory = new TaxonomyPageFactory();
        $pages = $factory->generate(
            [$this->makePage(['tags' => ['php']])],
            ['tags' => new TaxonomyConfig('tags', generatePages: true, basePath: '/blog/tags')],
        );

        $listPage = $this->findBySlug($pages, 'blog/tags');
        $termPage = $this->findBySlug($pages, 'blog/tags/php');

        $this->assertInstanceOf(ContentPage::class, $listPage);
        $this->assertSame('/blog/tags/', $listPage->urlPath);
        $this->assertSame('blog/tags/index.html', $listPage->outputRelativePath);

        $this->assertInstanceOf(ContentPage::class, $termPage);
        $this->assertSame('/blog/tags/php/', $termPage->urlPath);
        $this->assertSame('blog/tags/php/index.html', $termPage->outputRelativePath);
    }

    /**
     * Ensure custom termTemplate and listTemplate are reflected in page meta.
     */
    public function testCustomTemplatesAreEmbeddedInMeta(): void
    {
        $factory = new TaxonomyPageFactory();
        $config = new TaxonomyConfig(
            'tags',
            generatePages: true,
            termTemplate: 'taxonomy/tags',
            listTemplate: 'taxonomy/tags-list',
        );

        $pages = $factory->generate([$this->makePage(['tags' => ['php']])], ['tags' => $config]);

        $listPage = $this->findBySlug($pages, 'tags');
        $termPage = $this->findBySlug($pages, 'tags/php');

        $this->assertInstanceOf(ContentPage::class, $listPage);
        $this->assertSame('taxonomy/tags-list', $listPage->meta['template']);

        $this->assertInstanceOf(ContentPage::class, $termPage);
        $this->assertSame('taxonomy/tags', $termPage->meta['template']);
    }

    /**
     * Ensure multiple enabled taxonomies each generate their own page set.
     */
    public function testMultipleTaxonomiesGenerateSeparatePages(): void
    {
        $factory = new TaxonomyPageFactory();
        $pages = $factory->generate(
            [$this->makePage(['tags' => ['php'], 'categories' => ['tutorial']])],
            [
                'tags' => new TaxonomyConfig('tags', generatePages: true),
                'categories' => new TaxonomyConfig('categories', generatePages: true),
            ],
        );

        // tags: list + 1 term = 2; categories: list + 1 term = 2 → total 4
        $this->assertCount(4, $pages);

        $slugs = array_map(static fn(ContentPage $p): string => $p->slug, $pages);
        $this->assertContains('tags', $slugs);
        $this->assertContains('tags/php', $slugs);
        $this->assertContains('categories', $slugs);
        $this->assertContains('categories/tutorial', $slugs);
    }

    /**
     * Ensure a taxonomy with generatePages disabled does not generate pages
     * even when another enabled taxonomy is present.
     */
    public function testMixedEnabledAndDisabledTaxonomies(): void
    {
        $factory = new TaxonomyPageFactory();
        $pages = $factory->generate(
            [$this->makePage(['tags' => ['php'], 'categories' => ['tutorial']])],
            [
                'tags' => new TaxonomyConfig('tags', generatePages: true),
                'categories' => new TaxonomyConfig('categories', generatePages: false),
            ],
        );

        // Only tags generates: list + 1 term = 2
        $this->assertCount(2, $pages);
        $slugs = array_map(static fn(ContentPage $p): string => $p->slug, $pages);
        $this->assertContains('tags', $slugs);
        $this->assertContains('tags/php', $slugs);
        $this->assertNotContains('categories', $slugs);
    }

    /**
     * Ensure empty string terms are ignored.
     */
    public function testEmptyStringTermsAreIgnored(): void
    {
        $factory = new TaxonomyPageFactory();
        $pages = $factory->generate(
            [$this->makePage(['tags' => ['', 'php', '']])],
            ['tags' => new TaxonomyConfig('tags', generatePages: true)],
        );

        // List + 1 valid term (php)
        $this->assertCount(2, $pages);
        $slugs = array_map(static fn(ContentPage $p): string => $p->slug, $pages);
        $this->assertContains('tags/php', $slugs);
        $this->assertNotContains('tags/', $slugs);
    }

    /**
     * Find a page by slug within a list of generated pages.
     *
     * @param array<\Glaze\Content\ContentPage> $pages Generated pages.
     * @param string $slug Slug to search for.
     */
    protected function findBySlug(array $pages, string $slug): ?ContentPage
    {
        foreach ($pages as $page) {
            if ($page->slug === $slug) {
                return $page;
            }
        }

        return null;
    }

    /**
     * Create a minimal content page with the given taxonomy values.
     *
     * @param array<string, array<string>> $taxonomies Taxonomy term values.
     */
    protected function makePage(array $taxonomies): ContentPage
    {
        return new ContentPage(
            sourcePath: '/content/page.dj',
            relativePath: 'page.dj',
            slug: 'page',
            urlPath: '/page/',
            outputRelativePath: 'page/index.html',
            title: 'Page',
            source: '# Page',
            draft: false,
            meta: [],
            taxonomies: $taxonomies,
        );
    }
}
