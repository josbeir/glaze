<?php
declare(strict_types=1);

namespace Glaze\Tests\Fixture\Template;

use Glaze\Template\Collection\CollectionArrayTrait;

/**
 * Lightweight fixture class for exercising CollectionArrayTrait directly.
 */
final class CollectionArrayTraitFixture
{
    use CollectionArrayTrait;

    /**
     * Constructor.
     *
     * @param array<string> $items Fixture items.
     */
    public function __construct(protected array $items)
    {
    }

    /**
     * Return all items.
     *
     * @return array<string>
     */
    public function all(): array
    {
        return $this->items;
    }

    /**
     * Return collection items.
     *
     * @return array<string>
     */
    protected function collectionItems(): array
    {
        return $this->items;
    }

    /**
     * Rebuild fixture from normalized items.
     *
     * @param array<string> $items Collection items.
     */
    protected function withCollectionItems(array $items): static
    {
        return new self($items);
    }
}
