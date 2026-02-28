<?php
declare(strict_types=1);

namespace Glaze\Template;

use Cake\Chronos\Chronos;
use Cake\Utility\Hash;
use Glaze\Content\ContentPage;
use Glaze\Utility\Normalization;
use Throwable;

/**
 * Immutable site-wide index for page, section, and taxonomy lookups.
 */
final class SiteIndex
{
    /**
     * Memoized regular pages collection.
     */
    protected ?PageCollection $regularPagesCache = null;

    /**
     * Memoized root section tree.
     */
    protected ?Section $treeCache = null;

    /**
     * Memoized flattened section lookup by section path.
     *
     * @var array<string, \Glaze\Template\Section>|null
     */
    protected ?array $sectionLookupCache = null;

    /**
     * Memoized taxonomy collections by normalized taxonomy key.
     *
     * @var array<string, \Glaze\Template\TaxonomyCollection>
     */
    protected array $taxonomyCache = [];

    /**
     * Constructor.
     *
     * @param array<\Glaze\Content\ContentPage> $pages All discoverable pages.
     */
    public function __construct(protected array $pages)
    {
        $this->pages = array_values($this->pages);
    }

    /**
     * Return all indexed pages.
     *
     * @return array<\Glaze\Content\ContentPage>
     */
    public function all(): array
    {
        return $this->pages;
    }

    /**
     * Return default-sorted regular page collection.
     */
    public function regularPages(): PageCollection
    {
        if ($this->regularPagesCache instanceof PageCollection) {
            return $this->regularPagesCache;
        }

        $pages = $this->pages;

        usort($pages, function (ContentPage $left, ContentPage $right): int {
            $weightComparison = $this->extractWeight($left) <=> $this->extractWeight($right);
            if ($weightComparison !== 0) {
                return $weightComparison;
            }

            $dateComparison = $this->extractDateTimestamp($right) <=> $this->extractDateTimestamp($left);
            if ($dateComparison !== 0) {
                return $dateComparison;
            }

            $titleComparison = strcmp(mb_strtolower($left->title), mb_strtolower($right->title));
            if ($titleComparison !== 0) {
                return $titleComparison;
            }

            return strcmp($left->relativePath, $right->relativePath);
        });

        $this->regularPagesCache = new PageCollection($pages);

        return $this->regularPagesCache;
    }

    /**
     * Find page by slug.
     *
     * @param string $slug Page slug.
     */
    public function findBySlug(string $slug): ?ContentPage
    {
        $normalizedSlug = trim($slug, '/');
        if ($normalizedSlug === '') {
            $normalizedSlug = 'index';
        }

        foreach ($this->pages as $page) {
            if ($page->slug === $normalizedSlug) {
                return $page;
            }
        }

        return null;
    }

    /**
     * Find page by URL path.
     *
     * @param string $urlPath URL path.
     */
    public function findByUrlPath(string $urlPath): ?ContentPage
    {
        $normalizedUrlPath = $this->normalizeUrlPath($urlPath);

        foreach ($this->pages as $page) {
            if ($this->normalizeUrlPath($page->urlPath) === $normalizedUrlPath) {
                return $page;
            }
        }

        return null;
    }

    /**
     * Return pages for a section.
     *
     * @param string $section Section slug.
     */
    public function section(string $section): PageCollection
    {
        $resolved = $this->sectionNode($section);
        if (!$resolved instanceof Section) {
            return new PageCollection([]);
        }

        return $resolved->pages();
    }

    /**
     * Return a section node by path.
     *
     * @param string $sectionPath Section path.
     */
    public function sectionNode(string $sectionPath): ?Section
    {
        $normalizedPath = strtolower(trim($sectionPath, '/'));

        return $this->sectionsLookup()[$normalizedPath] ?? null;
    }

    /**
     * Return top-level sections ordered by section weight.
     *
     * @return array<string, \Glaze\Template\Section>
     */
    public function sections(): array
    {
        return $this->tree()->children();
    }

    /**
     * Return root section node.
     */
    public function tree(): Section
    {
        if ($this->treeCache instanceof Section) {
            return $this->treeCache;
        }

        $this->treeCache = SectionTree::build($this->regularPages()->all());

        return $this->treeCache;
    }

    /**
     * Return root-level pages that do not belong to any nested section.
     */
    public function rootPages(): PageCollection
    {
        return $this->tree()->pages();
    }

    /**
     * Return the previous page in global display order, crossing section boundaries.
     *
     * Sections and root pages are interleaved by weight. Each section block
     * is positioned by its lowest page weight; root pages by their own weight.
     *
     * @param \Glaze\Content\ContentPage $page Current page.
     * @param callable(\Glaze\Content\ContentPage): bool|null $predicate Optional matcher for candidate pages.
     */
    public function previous(ContentPage $page, ?callable $predicate = null): ?ContentPage
    {
        return $this->adjacentInCollection($this->globalPageOrder(), $page, -1, $predicate);
    }

    /**
     * Return the next page in global display order, crossing section boundaries.
     *
     * Sections and root pages are interleaved by weight. Each section block
     * is positioned by its lowest page weight; root pages by their own weight.
     *
     * @param \Glaze\Content\ContentPage $page Current page.
     * @param callable(\Glaze\Content\ContentPage): bool|null $predicate Optional matcher for candidate pages.
     */
    public function next(ContentPage $page, ?callable $predicate = null): ?ContentPage
    {
        return $this->adjacentInCollection($this->globalPageOrder(), $page, 1, $predicate);
    }

    /**
     * Return taxonomy map for a taxonomy name.
     *
     * @param string $taxonomy Taxonomy key.
     */
    public function taxonomy(string $taxonomy): TaxonomyCollection
    {
        $taxonomyKey = strtolower(trim($taxonomy));

        if (isset($this->taxonomyCache[$taxonomyKey])) {
            return $this->taxonomyCache[$taxonomyKey];
        }

        $terms = [];

        foreach ($this->regularPages() as $page) {
            $pageTerms = $page->taxonomies[$taxonomyKey] ?? [];

            foreach ($pageTerms as $term) {
                $terms[$term] ??= [];
                $terms[$term][] = $page;
            }
        }

        $collections = [];
        foreach ($terms as $term => $pages) {
            $collections[$term] = new PageCollection($pages);
        }

        $this->taxonomyCache[$taxonomyKey] = new TaxonomyCollection($collections);

        return $this->taxonomyCache[$taxonomyKey];
    }

    /**
     * Return previous page in the current page section.
     *
     * @param \Glaze\Content\ContentPage $page Current page.
     * @param callable(\Glaze\Content\ContentPage): bool|null $predicate Optional matcher for candidate pages.
     */
    public function previousInSection(ContentPage $page, ?callable $predicate = null): ?ContentPage
    {
        $section = $this->sectionNode($this->resolveSectionPath($page));
        if (!$section instanceof Section) {
            return null;
        }

        return $this->adjacentInCollection($section->pages()->all(), $page, -1, $predicate);
    }

    /**
     * Return next page in the current page section.
     *
     * @param \Glaze\Content\ContentPage $page Current page.
     * @param callable(\Glaze\Content\ContentPage): bool|null $predicate Optional matcher for candidate pages.
     */
    public function nextInSection(ContentPage $page, ?callable $predicate = null): ?ContentPage
    {
        $section = $this->sectionNode($this->resolveSectionPath($page));
        if (!$section instanceof Section) {
            return null;
        }

        return $this->adjacentInCollection($section->pages()->all(), $page, 1, $predicate);
    }

    /**
     * Return flattened section lookup by path.
     *
     * @return array<string, \Glaze\Template\Section>
     */
    protected function sectionsLookup(): array
    {
        if ($this->sectionLookupCache !== null) {
            return $this->sectionLookupCache;
        }

        $this->sectionLookupCache = $this->tree()->flatten();

        return $this->sectionLookupCache;
    }

    /**
     * Resolve section path from page relative path.
     *
     * @param \Glaze\Content\ContentPage $page Content page.
     */
    protected function resolveSectionPath(ContentPage $page): string
    {
        $metaSection = Hash::get($page->meta, 'section');
        if (is_string($metaSection) && trim($metaSection) !== '') {
            return Normalization::pathKey($metaSection);
        }

        $normalizedPath = Normalization::pathKey($page->relativePath);
        $directory = dirname($normalizedPath);
        if ($directory === '.' || $directory === '/') {
            return '';
        }

        return trim($directory, '/');
    }

    /**
     * Resolve adjacent page by relative offset in a page sequence.
     *
     * @param array<\Glaze\Content\ContentPage> $pages Ordered page list.
     * @param \Glaze\Content\ContentPage $page Current page.
     * @param int $offset Relative offset.
     * @param callable(\Glaze\Content\ContentPage): bool|null $predicate Optional matcher for candidate pages.
     */
    protected function adjacentInCollection(
        array $pages,
        ContentPage $page,
        int $offset,
        ?callable $predicate = null,
    ): ?ContentPage {
        $pages = array_values($pages);
        $index = null;
        foreach ($pages as $position => $candidate) {
            if ($candidate->slug === $page->slug) {
                $index = $position;
                break;
            }
        }

        if ($index === null) {
            return null;
        }

        for ($target = $index + $offset; isset($pages[$target]); $target += $offset) {
            $candidate = $pages[$target];
            if ($predicate === null || $predicate($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Build global page order from the section tree.
     *
     * Pages and child sections are interleaved by weight at each tree depth.
     *
     * @return array<int, \Glaze\Content\ContentPage>
     */
    protected function globalPageOrder(): array
    {
        return $this->flattenSectionOrder($this->tree());
    }

    /**
     * Flatten section subtree into global navigation order.
     *
     * @param \Glaze\Template\Section $section Section root.
     * @return array<int, \Glaze\Content\ContentPage>
     */
    protected function flattenSectionOrder(Section $section): array
    {
        /** @var array<int, array{weight: int, type: string, pages?: array<\Glaze\Content\ContentPage>, section?: \Glaze\Template\Section}> $blocks */
        $blocks = [];

        foreach ($section->pages() as $page) {
            $blocks[] = [
                'weight' => $this->extractWeight($page),
                'type' => 'page',
                'pages' => [$page],
            ];
        }

        foreach ($section->children() as $childSection) {
            $blocks[] = [
                'weight' => $childSection->weight(),
                'type' => 'section',
                'section' => $childSection,
            ];
        }

        usort($blocks, static fn(array $a, array $b): int => $a['weight'] <=> $b['weight']);

        $orderedPages = [];
        foreach ($blocks as $block) {
            if ($block['type'] === 'page') {
                foreach ($block['pages'] ?? [] as $blockPage) {
                    $orderedPages[] = $blockPage;
                }

                continue;
            }

            $childSection = $block['section'] ?? null;
            if ($childSection instanceof Section) {
                foreach ($this->flattenSectionOrder($childSection) as $nestedPage) {
                    $orderedPages[] = $nestedPage;
                }
            }
        }

        return $orderedPages;
    }

    /**
     * Normalize URL path.
     *
     * @param string $urlPath URL path.
     */
    protected function normalizeUrlPath(string $urlPath): string
    {
        $trimmed = trim($urlPath);
        if ($trimmed === '') {
            return '/';
        }

        $normalized = '/' . trim($trimmed, '/');
        if ($normalized === '/index') {
            return '/';
        }

        return $normalized;
    }

    /**
     * Extract sorting weight from metadata.
     *
     * @param \Glaze\Content\ContentPage $page Content page.
     */
    protected function extractWeight(ContentPage $page): int
    {
        $weight = Hash::get($page->meta, 'weight');

        return is_int($weight) ? $weight : PHP_INT_MAX;
    }

    /**
     * Extract sortable timestamp from metadata.
     *
     * @param \Glaze\Content\ContentPage $page Content page.
     */
    protected function extractDateTimestamp(ContentPage $page): int
    {
        $value = Hash::get($page->meta, 'date');

        if (is_int($value)) {
            return $value;
        }

        if (!is_string($value) || trim($value) === '') {
            return 0;
        }

        try {
            return Chronos::parse($value)->getTimestamp();
        } catch (Throwable) {
            return 0;
        }
    }
}
