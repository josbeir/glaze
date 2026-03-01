<?php
declare(strict_types=1);

namespace Glaze\Template;

use Glaze\Template\Collection\PageCollection;

/**
 * Immutable pager object for paginated template rendering.
 */
final class Pager
{
    /**
     * Constructor.
     *
     * @param \Glaze\Template\Collection\PageCollection $source Full source collection.
     * @param int $pageSize Number of elements per page.
     * @param int $pageNumber Current page number.
     * @param string $basePath Base path for pager URLs.
     * @param string $pathSegment URL segment for paginated pages.
     */
    public function __construct(
        protected PageCollection $source,
        protected int $pageSize,
        protected int $pageNumber,
        protected string $basePath,
        protected string $pathSegment = 'page',
    ) {
        $this->pageSize = max(1, $this->pageSize);
        $this->pageNumber = max(1, $this->pageNumber);
        $this->basePath = $this->normalizeBasePath($this->basePath);
        $this->pathSegment = trim($this->pathSegment, '/');
    }

    /**
     * Return source collection for the current pager.
     */
    public function source(): PageCollection
    {
        return $this->source;
    }

    /**
     * Return pages in the current pager.
     */
    public function pages(): PageCollection
    {
        $offset = ($this->pageNumber() - 1) * $this->pageSize;

        return $this->source->slice($offset, $this->pageSize);
    }

    /**
     * Return all pager objects.
     *
     * @return array<\Glaze\Template\Pager>
     */
    public function pagers(): array
    {
        $total = $this->totalPages();
        $pagers = [];

        for ($number = 1; $number <= $total; $number++) {
            $pagers[] = new self(
                source: $this->source,
                pageSize: $this->pageSize,
                pageNumber: $number,
                basePath: $this->basePath,
                pathSegment: $this->pathSegment,
            );
        }

        return $pagers;
    }

    /**
     * Return current page number.
     */
    public function pageNumber(): int
    {
        return min($this->pageNumber, $this->totalPages());
    }

    /**
     * Return number of elements in current page.
     */
    public function numberOfElements(): int
    {
        return $this->pages()->count();
    }

    /**
     * Return total number of source elements.
     */
    public function totalNumberOfElements(): int
    {
        return $this->source->count();
    }

    /**
     * Return configured page size.
     */
    public function pageSize(): int
    {
        return $this->pageSize;
    }

    /**
     * Return configured pager size alias.
     */
    public function pagerSize(): int
    {
        return $this->pageSize();
    }

    /**
     * Return total pager count.
     */
    public function totalPages(): int
    {
        $totalElements = $this->source->count();
        if ($totalElements === 0) {
            return 1;
        }

        return (int)ceil($totalElements / $this->pageSize);
    }

    /**
     * Return current pager URL.
     */
    public function url(): string
    {
        return $this->urlForPage($this->pageNumber());
    }

    /**
     * Return first pager.
     */
    public function first(): self
    {
        return new self(
            source: $this->source,
            pageSize: $this->pageSize,
            pageNumber: 1,
            basePath: $this->basePath,
            pathSegment: $this->pathSegment,
        );
    }

    /**
     * Return last pager.
     */
    public function last(): self
    {
        return new self(
            source: $this->source,
            pageSize: $this->pageSize,
            pageNumber: $this->totalPages(),
            basePath: $this->basePath,
            pathSegment: $this->pathSegment,
        );
    }

    /**
     * Return previous pager when available.
     */
    public function prev(): ?self
    {
        if (!$this->hasPrev()) {
            return null;
        }

        return new self(
            source: $this->source,
            pageSize: $this->pageSize,
            pageNumber: $this->pageNumber() - 1,
            basePath: $this->basePath,
            pathSegment: $this->pathSegment,
        );
    }

    /**
     * Return next pager when available.
     */
    public function next(): ?self
    {
        if (!$this->hasNext()) {
            return null;
        }

        return new self(
            source: $this->source,
            pageSize: $this->pageSize,
            pageNumber: $this->pageNumber() + 1,
            basePath: $this->basePath,
            pathSegment: $this->pathSegment,
        );
    }

    /**
     * Return whether there is a previous pager.
     */
    public function hasPrev(): bool
    {
        return $this->pageNumber() > 1;
    }

    /**
     * Return whether there is a next pager.
     */
    public function hasNext(): bool
    {
        return $this->pageNumber() < $this->totalPages();
    }

    /**
     * Return previous pager URL.
     */
    public function prevUrl(): ?string
    {
        if (!$this->hasPrev()) {
            return null;
        }

        return $this->urlForPage($this->pageNumber() - 1);
    }

    /**
     * Return next pager URL.
     */
    public function nextUrl(): ?string
    {
        if (!$this->hasNext()) {
            return null;
        }

        return $this->urlForPage($this->pageNumber() + 1);
    }

    /**
     * Build URL for a specific page number.
     *
     * @param int $pageNumber Page number.
     */
    protected function urlForPage(int $pageNumber): string
    {
        $pageNumber = max(1, $pageNumber);
        if ($pageNumber === 1) {
            return $this->basePath;
        }

        if ($this->pathSegment === '') {
            return rtrim($this->basePath, '/') . '/' . $pageNumber . '/';
        }

        return rtrim($this->basePath, '/') . '/' . $this->pathSegment . '/' . $pageNumber . '/';
    }

    /**
     * Normalize pager base path.
     *
     * @param string $basePath Base path.
     */
    protected function normalizeBasePath(string $basePath): string
    {
        $trimmed = trim($basePath);
        if ($trimmed === '') {
            return '/';
        }

        $normalized = '/' . trim($trimmed, '/') . '/';

        return preg_replace('#/+#', '/', $normalized) ?? '/';
    }
}
