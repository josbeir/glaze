<?php
declare(strict_types=1);

namespace Glaze\Template;

use Glaze\Content\ContentPage;
use Glaze\Template\Extension\ExtensionRegistry;

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
     * @param \Glaze\Template\Extension\ExtensionRegistry $extensions Registered project extensions.
     */
    public function __construct(
        protected SiteIndex $siteIndex,
        protected ContentPage $currentPage,
        protected ExtensionRegistry $extensions = new ExtensionRegistry(),
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
     * Return all non-root sections as an ordered map of section key to page collection.
     *
     * Sections are ordered by the lowest weight of any page within each section.
     * Root-level pages are excluded; access them via `rootPages()`.
     *
     * @return array<string, \Glaze\Template\PageCollection>
     */
    public function sections(): array
    {
        return $this->siteIndex->sections();
    }

    /**
     * Return root-level pages that do not belong to any section.
     */
    public function rootPages(): PageCollection
    {
        return $this->siteIndex->rootPages();
    }

    /**
     * Derive a human-readable label from a section key.
     *
     * Converts slug-style section keys to title case.
     * For example, `getting-started` becomes `Getting Started`.
     *
     * @param string $sectionKey Section slug.
     */
    public function sectionLabel(string $sectionKey): string
    {
        return $this->siteIndex->sectionLabel($sectionKey);
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
     * Return the previous page in global display order, crossing section boundaries.
     */
    public function previous(): ?ContentPage
    {
        return $this->siteIndex->previous($this->currentPage);
    }

    /**
     * Return the next page in global display order, crossing section boundaries.
     */
    public function next(): ?ContentPage
    {
        return $this->siteIndex->next($this->currentPage);
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
     * Invoke a named project extension and return its result.
     *
     * Extension results are memoized for the lifetime of the current build or request,
     * so expensive operations (HTTP fetches, file reads, etc.) run at most once
     * regardless of how many templates call the same extension.
     *
     * @param string $name Extension name as registered in `glaze.php`.
     * @param mixed ...$args Arguments forwarded to the extension on first invocation.
     * @throws \RuntimeException When the named extension is not registered.
     */
    public function extension(string $name, mixed ...$args): mixed
    {
        return $this->extensions->call($name, ...$args);
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
