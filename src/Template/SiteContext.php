<?php
declare(strict_types=1);

namespace Glaze\Template;

use Glaze\Content\ContentPage;

/**
 * Template context facade exposed to Sugar templates as `$this`.
 */
final class SiteContext
{
    /**
     * Constructor.
     *
     * @param \Glaze\Template\SiteIndex $siteIndex Site-wide page index.
     * @param \Glaze\Content\ContentPage $currentPage Current page being rendered.
     */
    public function __construct(
        protected SiteIndex $siteIndex,
        protected ContentPage $currentPage,
    ) {
    }

    /**
     * Return current page.
     */
    public function page(): ContentPage
    {
        return $this->currentPage;
    }

    /**
     * Return this site context for chainable template ergonomics.
     */
    public function site(): self
    {
        return $this;
    }

    /**
     * Return default-sorted regular pages.
     */
    public function regularPages(): PageCollection
    {
        return $this->siteIndex->regularPages();
    }

    /**
     * Alias for regular pages.
     */
    public function pages(): PageCollection
    {
        return $this->regularPages();
    }

    /**
     * Return pages matching a resolved content type.
     *
     * @param string $type Content type name.
     */
    public function type(string $type): PageCollection
    {
        return $this->regularPages()->whereType($type);
    }

    /**
     * Return pages from a section.
     *
     * @param string $name Section slug.
     */
    public function section(string $name): PageCollection
    {
        return $this->siteIndex->section($name);
    }

    /**
     * Find a page by slug.
     *
     * @param string $slug Page slug.
     */
    public function bySlug(string $slug): ?ContentPage
    {
        return $this->siteIndex->findBySlug($slug);
    }

    /**
     * Find a page by URL path.
     *
     * @param string $urlPath URL path.
     */
    public function byUrl(string $urlPath): ?ContentPage
    {
        return $this->siteIndex->findByUrlPath($urlPath);
    }

    /**
     * Filter a collection with where semantics.
     *
     * @param \Glaze\Template\PageCollection|array<\Glaze\Content\ContentPage> $collection Collection to filter.
     * @param string $key Field key.
     * @param mixed $operatorOrValue Operator or expected value.
     * @param mixed $value Optional expected value when using operator.
     */
    public function where(
        PageCollection|array $collection,
        string $key,
        mixed $operatorOrValue,
        mixed $value = null,
    ): PageCollection {
        $pages = $this->toCollection($collection);

        if (func_num_args() >= 4) {
            return $pages->where($key, $operatorOrValue, $value);
        }

        return $pages->where($key, $operatorOrValue);
    }

    /**
     * Return taxonomy data by name.
     *
     * @param string $name Taxonomy field name.
     */
    public function taxonomy(string $name): TaxonomyCollection
    {
        return $this->siteIndex->taxonomy($name);
    }

    /**
     * Return pages for a specific taxonomy term.
     *
     * @param string $name Taxonomy field name.
     * @param string $term Term name.
     */
    public function taxonomyTerm(string $name, string $term): PageCollection
    {
        return $this->taxonomy($name)->term($term);
    }

    /**
     * Paginate a page collection.
     *
     * @param \Glaze\Template\PageCollection|array<\Glaze\Content\ContentPage> $collection Source collection.
     * @param int $pageSize Page size.
     * @param int $currentPage Current page number.
     * @param string|null $basePath Base pager path.
     * @param string $pathSegment Pagination path segment.
     */
    public function paginate(
        PageCollection|array $collection,
        int $pageSize = 10,
        int $currentPage = 1,
        ?string $basePath = null,
        string $pathSegment = 'page',
    ): Pager {
        $pages = $this->toCollection($collection);
        $basePath ??= $this->currentPage->urlPath;

        return new Pager(
            source: $pages,
            pageSize: $pageSize,
            pageNumber: $currentPage,
            basePath: $basePath,
            pathSegment: $pathSegment,
        );
    }

    /**
     * Return previous page in the current page section.
     */
    public function previousInSection(): ?ContentPage
    {
        return $this->siteIndex->previousInSection($this->currentPage);
    }

    /**
     * Return next page in the current page section.
     */
    public function nextInSection(): ?ContentPage
    {
        return $this->siteIndex->nextInSection($this->currentPage);
    }

    /**
     * Check if the current page URL matches a path.
     *
     * @param string $urlPath Path to compare.
     */
    public function isCurrent(string $urlPath): bool
    {
        return $this->normalizeUrlPath($this->currentPage->urlPath) === $this->normalizeUrlPath($urlPath);
    }

    /**
     * Normalize collections to `PageCollection`.
     *
     * @param \Glaze\Template\PageCollection|array<\Glaze\Content\ContentPage> $collection Input collection.
     */
    protected function toCollection(PageCollection|array $collection): PageCollection
    {
        if ($collection instanceof PageCollection) {
            return $collection;
        }

        return new PageCollection($collection);
    }

    /**
     * Normalize URL path for equality checks.
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

        return $normalized === '/index' ? '/' : $normalized;
    }
}
