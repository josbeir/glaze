<?php
declare(strict_types=1);

namespace Glaze\Template;

use Cake\Chronos\Chronos;
use Cake\Utility\Hash;
use Cake\Utility\Inflector;
use Glaze\Content\ContentPage;
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
     * Memoized ordered sections array.
     *
     * @var array<string, \Glaze\Template\PageCollection>|null
     */
    protected ?array $sectionsCache = null;

    /**
     * Memoized section collections by normalized section key.
     *
     * @var array<string, \Glaze\Template\PageCollection>
     */
    protected array $sectionCache = [];

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
        $normalizedSection = strtolower(trim($section, '/'));

        if (isset($this->sectionCache[$normalizedSection])) {
            return $this->sectionCache[$normalizedSection];
        }

        $this->sectionCache[$normalizedSection] = $this->regularPages()->filter(
            function (ContentPage $page) use ($normalizedSection): bool {
                return $this->resolveSection($page) === $normalizedSection;
            },
        );

        return $this->sectionCache[$normalizedSection];
    }

    /**
     * Return all non-root sections as an ordered map of section key to page collection.
     *
     * Sections are ordered by the index page weight when present, otherwise by
     * the lowest weight of any page within each section. Root-level pages (those
     * without a section) are excluded; access them via `rootPages()`.
     *
     * @return array<string, \Glaze\Template\PageCollection>
     */
    public function sections(): array
    {
        if ($this->sectionsCache !== null) {
            return $this->sectionsCache;
        }

        $sectionKeys = [];
        $sectionMinWeight = [];

        foreach ($this->regularPages() as $page) {
            $sectionKey = $this->resolveSection($page);
            if ($sectionKey === '') {
                continue;
            }

            if (!isset($sectionKeys[$sectionKey])) {
                $sectionKeys[$sectionKey] = true;
                $sectionMinWeight[$sectionKey] = $this->extractWeight($page);
            } else {
                $sectionMinWeight[$sectionKey] = min(
                    $sectionMinWeight[$sectionKey],
                    $this->extractWeight($page),
                );
            }
        }

        $resolvedWeights = [];
        foreach (array_keys($sectionKeys) as $key) {
            $resolvedWeights[$key] = $this->resolveSectionWeight($key, $sectionMinWeight[$key]);
        }

        uksort($sectionKeys, static function (string $a, string $b) use ($resolvedWeights): int {
            return $resolvedWeights[$a] <=> $resolvedWeights[$b];
        });

        $result = [];
        foreach (array_keys($sectionKeys) as $sectionKey) {
            $result[$sectionKey] = $this->section($sectionKey);
        }

        $this->sectionsCache = $result;

        return $this->sectionsCache;
    }

    /**
     * Return root-level pages that do not belong to any section.
     */
    public function rootPages(): PageCollection
    {
        return $this->section('');
    }

    /**
     * Derive a human-readable label from a section key.
     *
     * When the section contains an `index.dj` page, its title is used as the
     * label. Otherwise, the section key is auto-humanized from the folder name
     * (e.g. `getting-started` becomes `Getting Started`).
     *
     * @param string $sectionKey Section slug.
     */
    public function sectionLabel(string $sectionKey): string
    {
        $indexPage = $this->findSectionIndex($sectionKey);
        if ($indexPage instanceof ContentPage) {
            return $indexPage->title;
        }

        return Inflector::humanize(str_replace('-', '_', $sectionKey));
    }

    /**
     * Find the index page for a section.
     *
     * An index page is a content file whose relative path is `{section}/index.dj`.
     * Section index pages can provide a custom title and weight for the section.
     *
     * @param string $sectionKey Section slug.
     */
    public function findSectionIndex(string $sectionKey): ?ContentPage
    {
        $normalizedSection = strtolower(trim($sectionKey, '/'));
        if ($normalizedSection === '') {
            return null;
        }

        foreach ($this->section($normalizedSection) as $page) {
            if ($this->isSectionIndex($page, $normalizedSection)) {
                return $page;
            }
        }

        return null;
    }

    /**
     * Return the previous page in global display order, crossing section boundaries.
     *
     * Sections and root pages are interleaved by weight. Each section block
     * is positioned by its lowest page weight; root pages by their own weight.
     *
     * @param \Glaze\Content\ContentPage $page Current page.
     */
    public function previous(ContentPage $page): ?ContentPage
    {
        return $this->adjacentGlobal($page, -1);
    }

    /**
     * Return the next page in global display order, crossing section boundaries.
     *
     * Sections and root pages are interleaved by weight. Each section block
     * is positioned by its lowest page weight; root pages by their own weight.
     *
     * @param \Glaze\Content\ContentPage $page Current page.
     */
    public function next(ContentPage $page): ?ContentPage
    {
        return $this->adjacentGlobal($page, 1);
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
     */
    public function previousInSection(ContentPage $page): ?ContentPage
    {
        return $this->adjacentInSection($page, -1);
    }

    /**
     * Return next page in the current page section.
     *
     * @param \Glaze\Content\ContentPage $page Current page.
     */
    public function nextInSection(ContentPage $page): ?ContentPage
    {
        return $this->adjacentInSection($page, 1);
    }

    /**
     * Resolve section slug from page data.
     *
     * @param \Glaze\Content\ContentPage $page Content page.
     */
    protected function resolveSection(ContentPage $page): string
    {
        $metaSection = Hash::get($page->meta, 'section');
        if (is_string($metaSection) && trim($metaSection) !== '') {
            return strtolower(trim($metaSection, '/'));
        }

        $segments = explode('/', trim(str_replace('\\', '/', $page->relativePath), '/'));
        if (count($segments) <= 1) {
            return '';
        }

        return strtolower($segments[0]);
    }

    /**
     * Determine whether a page is the index page of a given section.
     *
     * @param \Glaze\Content\ContentPage $page Content page.
     * @param string $sectionKey Normalized section key.
     */
    protected function isSectionIndex(ContentPage $page, string $sectionKey): bool
    {
        $normalizedPath = strtolower(trim(str_replace('\\', '/', $page->relativePath), '/'));

        return $normalizedPath === $sectionKey . '/index.dj';
    }

    /**
     * Resolve weight used for ordering a section relative to other sections and root pages.
     *
     * When an `index.dj` page exists in the section, its weight is used.
     * Otherwise, falls back to the minimum weight of all pages in the section.
     *
     * @param string $sectionKey Section key.
     * @param int $fallbackWeight Minimum weight across all section pages.
     */
    protected function resolveSectionWeight(string $sectionKey, int $fallbackWeight): int
    {
        $indexPage = $this->findSectionIndex($sectionKey);
        if ($indexPage instanceof ContentPage) {
            return $this->extractWeight($indexPage);
        }

        return $fallbackWeight;
    }

    /**
     * Return adjacent page in section order.
     *
     * @param \Glaze\Content\ContentPage $page Current page.
     * @param int $offset Relative offset.
     */
    protected function adjacentInSection(ContentPage $page, int $offset): ?ContentPage
    {
        /** @var array<int, \Glaze\Content\ContentPage> $sectionPages */
        $sectionPages = $this->section($this->resolveSection($page))->all();
        $index = null;

        foreach ($sectionPages as $position => $candidate) {
            if (!is_int($position)) {
                continue;
            }

            if ($candidate->slug === $page->slug) {
                $index = $position;
                break;
            }
        }

        if ($index === null) {
            return null;
        }

        $target = $index + $offset;
        if ($target < 0) {
            return null;
        }

        return $sectionPages[$target] ?? null;
    }

    /**
     * Build a flat ordered page list matching section display order and find an adjacent page.
     *
     * The order is: sectioned pages first (grouped by section, ordered by minimum
     * section weight), then root-level pages. Within each section, pages follow
     * the `regularPages()` sort order.
     *
     * @param \Glaze\Content\ContentPage $page Current page.
     * @param int $offset Relative offset (-1 for previous, +1 for next).
     */
    protected function adjacentGlobal(ContentPage $page, int $offset): ?ContentPage
    {
        $orderedPages = $this->buildGlobalPageOrder();
        $index = null;

        foreach ($orderedPages as $position => $candidate) {
            if ($candidate->slug === $page->slug) {
                $index = $position;
                break;
            }
        }

        if ($index === null) {
            return null;
        }

        $target = $index + $offset;
        if ($target < 0) {
            return null;
        }

        return $orderedPages[$target] ?? null;
    }

    /**
     * Build a flat ordered list of all navigable pages following section display order.
     *
     * Sections and root pages are interleaved by weight. Each section is treated
     * as a block whose weight equals the index page weight (when present) or the
     * minimum weight of its pages. Root pages use their individual weight. Blocks
     * are sorted by weight, then flattened.
     *
     * @return array<int, \Glaze\Content\ContentPage>
     */
    protected function buildGlobalPageOrder(): array
    {
        /** @var array<int, array{weight: int, pages: array<\Glaze\Content\ContentPage>}> $blocks */
        $blocks = [];

        foreach ($this->sections() as $sectionKey => $sectionPages) {
            $minWeight = PHP_INT_MAX;
            $pages = [];
            foreach ($sectionPages as $sectionPage) {
                $pages[] = $sectionPage;
                $minWeight = min($minWeight, $this->extractWeight($sectionPage));
            }
            $blocks[] = ['weight' => $this->resolveSectionWeight($sectionKey, $minWeight), 'pages' => $pages];
        }

        foreach ($this->rootPages() as $rootPage) {
            $blocks[] = ['weight' => $this->extractWeight($rootPage), 'pages' => [$rootPage]];
        }

        usort($blocks, static fn(array $a, array $b): int => $a['weight'] <=> $b['weight']);

        $orderedPages = [];
        foreach ($blocks as $block) {
            foreach ($block['pages'] as $blockPage) {
                $orderedPages[] = $blockPage;
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
