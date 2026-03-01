<?php
declare(strict_types=1);

namespace Glaze\Template\Collection;

/**
 * Shared array-based collection helpers for immutable template collections.
 */
trait CollectionArrayTrait
{
    /**
     * Return collection items.
     *
     * @template TValue
     * @return array<TValue>
     */
    abstract protected function collectionItems(): array;

    /**
     * Rebuild collection from normalized items.
     *
     * @template TValue
     * @param array<TValue> $items Collection items.
     */
    abstract protected function withCollectionItems(array $items): static;

    /**
     * Return collection size.
     *
     * @return int<0, max>
     */
    public function count(): int
    {
        return count($this->collectionItems());
    }

    /**
     * Return whether collection is empty.
     */
    public function isEmpty(): bool
    {
        return $this->collectionItems() === [];
    }

    /**
     * Return first collection item.
     */
    public function first(): mixed
    {
        $items = $this->collectionItems();

        return $items[0] ?? null;
    }

    /**
     * Return last collection item.
     */
    public function last(): mixed
    {
        $items = $this->collectionItems();
        if ($items === []) {
            return null;
        }

        return $items[array_key_last($items)];
    }

    /**
     * Return first N items.
     *
     * @param int $limit Maximum item count.
     */
    public function take(int $limit): static
    {
        return $this->withCollectionItems(array_slice($this->collectionItems(), 0, max(0, $limit)));
    }

    /**
     * Return a sub-slice of items.
     *
     * @param int $offset Start offset.
     * @param int|null $length Slice length.
     */
    public function slice(int $offset, ?int $length = null): static
    {
        if ($length === null) {
            return $this->withCollectionItems(array_slice($this->collectionItems(), $offset));
        }

        return $this->withCollectionItems(array_slice($this->collectionItems(), $offset, $length));
    }

    /**
     * Return items in reversed order.
     */
    public function reverse(): static
    {
        return $this->withCollectionItems(array_reverse($this->collectionItems()));
    }
}
