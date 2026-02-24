<?php
declare(strict_types=1);

namespace Glaze\Template;

use Cake\Chronos\Chronos;
use Cake\Utility\Hash;
use Glaze\Content\ContentPage;
use Throwable;

/**
 * Immutable site-wide index for page, section, and taxonomy lookups.
 */
final class SiteIndex
{
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

        return new PageCollection($pages);
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

        return $this->regularPages()->filter(function (ContentPage $page) use ($normalizedSection): bool {
            return $this->resolveSection($page) === $normalizedSection;
        });
    }

    /**
     * Return taxonomy map for a taxonomy name.
     *
     * @param string $taxonomy Taxonomy key.
     */
    public function taxonomy(string $taxonomy): TaxonomyCollection
    {
        $taxonomyKey = strtolower(trim($taxonomy));
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

        return new TaxonomyCollection($collections);
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
