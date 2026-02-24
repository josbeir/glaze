<?php
declare(strict_types=1);

namespace Glaze\Template;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * Immutable taxonomy term map for template usage.
 *
 * @implements \IteratorAggregate<string, \Glaze\Template\PageCollection>
 */
final class TaxonomyCollection implements IteratorAggregate, Countable
{
    /**
     * Constructor.
     *
     * @param array<string, \Glaze\Template\PageCollection> $terms Taxonomy term collections.
     */
    public function __construct(protected array $terms)
    {
        ksort($this->terms);
    }

    /**
     * Return all term collections.
     *
     * @return array<string, \Glaze\Template\PageCollection>
     */
    public function all(): array
    {
        return $this->terms;
    }

    /**
     * Return sorted term names.
     *
     * @return array<string>
     */
    public function terms(): array
    {
        return array_keys($this->terms);
    }

    /**
     * Get pages for a term.
     *
     * @param string $term Taxonomy term.
     */
    public function term(string $term): PageCollection
    {
        $normalized = $this->normalizeTerm($term);

        return $this->terms[$normalized] ?? new PageCollection([]);
    }

    /**
     * Determine whether a term exists.
     *
     * @param string $term Taxonomy term.
     */
    public function hasTerm(string $term): bool
    {
        $normalized = $this->normalizeTerm($term);

        return isset($this->terms[$normalized]);
    }

    /**
     * Return term count.
     */
    public function count(): int
    {
        return count($this->terms);
    }

    /**
     * Return iterator for term loops.
     *
     * @return \Traversable<string, \Glaze\Template\PageCollection>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->terms);
    }

    /**
     * Normalize user term input.
     *
     * @param string $term Raw term value.
     */
    protected function normalizeTerm(string $term): string
    {
        return strtolower(trim($term));
    }
}
