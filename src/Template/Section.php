<?php
declare(strict_types=1);

namespace Glaze\Template;

use Countable;
use Glaze\Content\ContentPage;
use Glaze\Template\Collection\ContentAssetCollection;
use Glaze\Template\Collection\PageCollection;
use IteratorAggregate;
use Traversable;

/**
 * Immutable section node used to model hierarchical content folders.
 *
 * A section represents one content directory and exposes metadata (label,
 * weight, optional index page), direct pages, and nested child sections.
 *
 * @implements \IteratorAggregate<int, \Glaze\Content\ContentPage>
 */
final class Section implements IteratorAggregate, Countable
{
    /**
     * Constructor.
     *
     * @param string $path Section path relative to content root.
     * @param string $label Human-readable section label.
     * @param int $weight Section ordering weight.
     * @param \Glaze\Content\ContentPage|null $indexPage Section index page (`index.dj`) when present.
     * @param \Glaze\Template\Collection\PageCollection $pages Direct pages that belong to this section.
     * @param array<string, \Glaze\Template\Section> $children Ordered child sections by child key.
     * @param \Glaze\Template\ContentAssetResolver|null $assetResolver Optional content asset resolver.
     */
    public function __construct(
        protected string $path,
        protected string $label,
        protected int $weight,
        protected ?ContentPage $indexPage,
        protected PageCollection $pages,
        protected array $children,
        protected ?ContentAssetResolver $assetResolver = null,
    ) {
    }

    /**
     * Return section path relative to content root.
     */
    public function path(): string
    {
        return $this->path;
    }

    /**
     * Return section key (last path segment).
     */
    public function key(): string
    {
        if ($this->path === '') {
            return '';
        }

        $segments = explode('/', $this->path);

        return end($segments);
    }

    /**
     * Return section depth, where root is depth 0.
     */
    public function depth(): int
    {
        if ($this->path === '') {
            return 0;
        }

        return count(explode('/', $this->path));
    }

    /**
     * Return whether this section represents the content root.
     */
    public function isRoot(): bool
    {
        return $this->path === '';
    }

    /**
     * Return section display label.
     */
    public function label(): string
    {
        return $this->label;
    }

    /**
     * Return section ordering weight.
     */
    public function weight(): int
    {
        return $this->weight;
    }

    /**
     * Return section index page when present.
     */
    public function index(): ?ContentPage
    {
        return $this->indexPage;
    }

    /**
     * Return direct section pages.
     */
    public function pages(): PageCollection
    {
        return $this->pages;
    }

    /**
     * Return direct assets for this section path.
     *
     * Scans only direct files in the section directory unless a subdirectory is
     * provided. Djot source files are excluded.
     *
     * @param string|null $subdirectory Optional child path relative to this section.
     */
    public function assets(?string $subdirectory = null): ContentAssetCollection
    {
        if (!$this->assetResolver instanceof ContentAssetResolver) {
            return new ContentAssetCollection([]);
        }

        $targetPath = $this->path;
        if (is_string($subdirectory) && trim($subdirectory) !== '') {
            $targetPath = $targetPath === ''
                ? $subdirectory
                : $targetPath . '/' . $subdirectory;
        }

        return $this->assetResolver->forDirectory($targetPath);
    }

    /**
     * Return all assets for this section subtree recursively.
     *
     * @param string|null $subdirectory Optional child path relative to this section.
     */
    public function allAssets(?string $subdirectory = null): ContentAssetCollection
    {
        if (!$this->assetResolver instanceof ContentAssetResolver) {
            return new ContentAssetCollection([]);
        }

        $targetPath = $this->path;
        if (is_string($subdirectory) && trim($subdirectory) !== '') {
            $targetPath = $targetPath === ''
                ? $subdirectory
                : $targetPath . '/' . $subdirectory;
        }

        return $this->assetResolver->forDirectoryRecursive($targetPath);
    }

    /**
     * Return all pages in this section subtree.
     */
    public function allPages(): PageCollection
    {
        $items = $this->pages->all();

        foreach ($this->children as $childSection) {
            foreach ($childSection->allPages() as $page) {
                $items[] = $page;
            }
        }

        return new PageCollection($items);
    }

    /**
     * Return ordered child sections.
     *
     * @return array<string, \Glaze\Template\Section>
     */
    public function children(): array
    {
        return $this->children;
    }

    /**
     * Return child section by key.
     *
     * @param string $key Child section key.
     */
    public function child(string $key): ?self
    {
        return $this->children[$key] ?? null;
    }

    /**
     * Return whether this section has child sections.
     */
    public function hasChildren(): bool
    {
        return $this->children !== [];
    }

    /**
     * Return whether this section has no pages and no children.
     */
    public function isEmpty(): bool
    {
        return $this->pages->isEmpty() && $this->children === [];
    }

    /**
     * Return flattened section map including this node and all descendants.
     *
     * @return array<string, \Glaze\Template\Section>
     */
    public function flatten(): array
    {
        $flat = [$this->path => $this];

        foreach ($this->children as $childSection) {
            foreach ($childSection->flatten() as $path => $section) {
                $flat[$path] = $section;
            }
        }

        return $flat;
    }

    /**
     * Return direct section pages as iterable for template loops.
     *
     * @return \Traversable<int, \Glaze\Content\ContentPage>
     */
    public function getIterator(): Traversable
    {
        return $this->pages()->getIterator();
    }

    /**
     * Return number of direct pages in this section.
     */
    public function count(): int
    {
        return $this->pages()->count();
    }
}
