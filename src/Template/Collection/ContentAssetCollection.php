<?php
declare(strict_types=1);

namespace Glaze\Template\Collection;

use Countable;
use Glaze\Content\ContentAsset;
use InvalidArgumentException;
use IteratorAggregate;
use Traversable;

/**
 * Immutable collection wrapper for content assets with query-style helpers.
 *
 * @implements \IteratorAggregate<int, \Glaze\Content\ContentAsset>
 */
final class ContentAssetCollection implements IteratorAggregate, Countable
{
    use CollectionArrayTrait;

    /**
     * Ascending sort direction.
     */
    protected const SORT_ASC = 'asc';

    /**
     * Descending sort direction.
     */
    protected const SORT_DESC = 'desc';

    /**
     * Constructor.
     *
     * @param array<\Glaze\Content\ContentAsset> $assets Asset list.
     */
    public function __construct(protected array $assets)
    {
        $this->assets = array_values($this->assets);
    }

    /**
     * Return raw asset array.
     *
     * @return array<\Glaze\Content\ContentAsset>
     */
    public function all(): array
    {
        return $this->assets;
    }

    /**
     * Keep only image assets.
     */
    public function images(): self
    {
        return $this->filter(static fn(ContentAsset $asset): bool => $asset->isImage());
    }

    /**
     * Keep only assets matching one or more extensions.
     *
     * @param string ...$extensions Extensions with or without leading dots.
     */
    public function ofType(string ...$extensions): self
    {
        return $this->filter(static fn(ContentAsset $asset): bool => $asset->is(...$extensions));
    }

    /**
     * Keep only assets whose relative path matches a glob pattern.
     *
     * @param string $pattern Glob pattern interpreted by `fnmatch()`.
     */
    public function matching(string $pattern): self
    {
        $trimmed = trim($pattern);
        if ($trimmed === '') {
            return new self([]);
        }

        return $this->filter(static fn(ContentAsset $asset): bool => fnmatch($trimmed, $asset->relativePath));
    }

    /**
     * Return first asset in collection.
     */
    public function first(): ?ContentAsset
    {
        return $this->assets[0] ?? null;
    }

    /**
     * Return last asset in collection.
     */
    public function last(): ?ContentAsset
    {
        if ($this->assets === []) {
            return null;
        }

        return $this->assets[array_key_last($this->assets)];
    }

    /**
     * Sort assets by filename.
     *
     * @param string $direction Sort direction (`asc` or `desc`).
     */
    public function sortByName(string $direction = self::SORT_ASC): self
    {
        $resolvedDirection = $this->normalizeSortDirection($direction);

        return $this->sort(static function (ContentAsset $left, ContentAsset $right) use ($resolvedDirection): int {
            $comparison = strcmp(mb_strtolower($left->filename), mb_strtolower($right->filename));
            if ($comparison === 0) {
                $comparison = strcmp($left->relativePath, $right->relativePath);
            }

            return $resolvedDirection === self::SORT_DESC ? -$comparison : $comparison;
        });
    }

    /**
     * Sort assets by file size.
     *
     * @param string $direction Sort direction (`asc` or `desc`).
     */
    public function sortBySize(string $direction = self::SORT_ASC): self
    {
        $resolvedDirection = $this->normalizeSortDirection($direction);

        return $this->sort(static function (ContentAsset $left, ContentAsset $right) use ($resolvedDirection): int {
            $comparison = $left->size <=> $right->size;
            if ($comparison === 0) {
                $comparison = strcmp($left->relativePath, $right->relativePath);
            }

            return $resolvedDirection === self::SORT_DESC ? -$comparison : $comparison;
        });
    }

    /**
     * Return iterator for template loops.
     *
     * @return \Traversable<int, \Glaze\Content\ContentAsset>
     */
    public function getIterator(): Traversable
    {
        yield from $this->assets;
    }

    /**
     * Return collection items.
     *
     * @return array<\Glaze\Content\ContentAsset>
     */
    protected function collectionItems(): array
    {
        return $this->assets;
    }

    /**
     * Rebuild collection from normalized items.
     *
     * @param array<\Glaze\Content\ContentAsset> $items Collection items.
     */
    protected function withCollectionItems(array $items): static
    {
        return new self($items);
    }

    /**
     * Filter collection with predicate.
     *
     * @param callable(\Glaze\Content\ContentAsset): bool $predicate Predicate callback.
     */
    public function filter(callable $predicate): self
    {
        $filtered = array_values(array_filter($this->assets, $predicate));

        return new self($filtered);
    }

    /**
     * Sort collection using a comparator.
     *
     * @param callable(\Glaze\Content\ContentAsset, \Glaze\Content\ContentAsset): int $comparator Comparator callback.
     */
    protected function sort(callable $comparator): self
    {
        $sorted = $this->assets;
        usort($sorted, $comparator);

        return new self($sorted);
    }

    /**
     * Normalize sort direction value.
     *
     * @param string $direction Raw direction input.
     */
    protected function normalizeSortDirection(string $direction): string
    {
        $resolved = strtolower(trim($direction));
        if ($resolved === self::SORT_ASC || $resolved === self::SORT_DESC) {
            return $resolved;
        }

        throw new InvalidArgumentException(sprintf(
            'Unsupported sort direction "%s". Use "asc" or "desc".',
            $direction,
        ));
    }
}
