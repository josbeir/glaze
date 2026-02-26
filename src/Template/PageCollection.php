<?php
declare(strict_types=1);

namespace Glaze\Template;

use ArrayIterator;
use Cake\Chronos\Chronos;
use Cake\Utility\Hash;
use Countable;
use DateTimeInterface;
use Glaze\Content\ContentPage;
use Glaze\Utility\Normalization;
use IteratorAggregate;
use Throwable;
use Traversable;

/**
 * Immutable collection wrapper for content pages with query-style helpers.
 *
 * @implements \IteratorAggregate<int, \Glaze\Content\ContentPage>
 */
final class PageCollection implements IteratorAggregate, Countable
{
    /**
     * Supported where operators.
     *
     * @var array<string, string>
     */
    protected const OPERATORS = [
        '=' => 'eq',
        '==' => 'eq',
        'eq' => 'eq',
        '!=' => 'ne',
        '<>' => 'ne',
        'ne' => 'ne',
        '>=' => 'ge',
        'ge' => 'ge',
        '>' => 'gt',
        'gt' => 'gt',
        '<=' => 'le',
        'le' => 'le',
        '<' => 'lt',
        'lt' => 'lt',
        'in' => 'in',
        'not in' => 'not in',
        'intersect' => 'intersect',
        'like' => 'like',
    ];

    /**
     * Constructor.
     *
     * @param array<\Glaze\Content\ContentPage> $pages Pages in this collection.
     */
    public function __construct(protected array $pages)
    {
        $this->pages = array_values($this->pages);
    }

    /**
     * Create collection from pages.
     *
     * @param array<\Glaze\Content\ContentPage> $pages Pages in this collection.
     */
    public static function from(array $pages): self
    {
        return new self($pages);
    }

    /**
     * Return raw page array.
     *
     * @return array<\Glaze\Content\ContentPage>
     */
    public function all(): array
    {
        return $this->pages;
    }

    /**
     * Return collection size.
     */
    public function count(): int
    {
        return count($this->pages);
    }

    /**
     * Return whether collection is empty.
     */
    public function isEmpty(): bool
    {
        return $this->count() === 0;
    }

    /**
     * Return first page in collection.
     */
    public function first(): ?ContentPage
    {
        return $this->pages[0] ?? null;
    }

    /**
     * Return last page in collection.
     */
    public function last(): ?ContentPage
    {
        if ($this->pages === []) {
            return null;
        }

        return $this->pages[array_key_last($this->pages)];
    }

    /**
     * Return first N pages.
     *
     * @param int $limit Maximum number of pages.
     */
    public function take(int $limit): self
    {
        return new self(array_slice($this->pages, 0, max(0, $limit)));
    }

    /**
     * Return a sub-slice of pages.
     *
     * @param int $offset Start offset.
     * @param int|null $length Slice length.
     */
    public function slice(int $offset, ?int $length = null): self
    {
        if ($length === null) {
            return new self(array_slice($this->pages, $offset));
        }

        return new self(array_slice($this->pages, $offset, $length));
    }

    /**
     * Return pages in reversed order.
     */
    public function reverse(): self
    {
        return new self(array_reverse($this->pages));
    }

    /**
     * Filter pages with a callback.
     *
     * @param callable(\Glaze\Content\ContentPage): bool $callback Filter callback.
     */
    public function filter(callable $callback): self
    {
        $raw = array_filter(
            $this->pages,
            static fn(ContentPage $page): bool => (bool)$callback($page),
        );
        $pages = $this->normalizeContentPages($raw);

        return new self($pages);
    }

    /**
     * Sort pages by a field key.
     *
     * Key supports nested dot notation (for example `meta.date`).
     *
     * @param string $key Field key.
     * @param string $direction Sort direction (`asc` or `desc`).
     */
    public function by(string $key, string $direction = 'asc'): self
    {
        $pages = $this->pages;
        $descending = strtolower($direction) === 'desc';
        usort($pages, function (ContentPage $left, ContentPage $right) use ($key, $descending): int {
            $leftValue = $this->sortableValue($this->resolveValue($left, $key));
            $rightValue = $this->sortableValue($this->resolveValue($right, $key));

            $comparison = $this->compareValues($leftValue, $rightValue);

            return $descending ? -$comparison : $comparison;
        });

        return new self($pages);
    }

    /**
     * Sort pages by date metadata.
     *
     * @param string $direction Sort direction (`asc` or `desc`).
     * @param string $dateKey Metadata key containing date value.
     */
    public function byDate(string $direction = 'asc', string $dateKey = 'meta.date'): self
    {
        $pages = $this->pages;
        $descending = strtolower($direction) === 'desc';

        usort($pages, function (ContentPage $left, ContentPage $right) use ($dateKey, $descending): int {
            $leftValue = $this->toTimestamp($this->resolveValue($left, $dateKey));
            $rightValue = $this->toTimestamp($this->resolveValue($right, $dateKey));
            $comparison = $this->compareValues($leftValue, $rightValue);

            return $descending ? -$comparison : $comparison;
        });

        return new self($pages);
    }

    /**
     * Sort pages by title.
     *
     * @param string $direction Sort direction (`asc` or `desc`).
     */
    public function byTitle(string $direction = 'asc'): self
    {
        return $this->by('title', $direction);
    }

    /**
     * Filter pages using where-style operator syntax.
     *
     * @param string $key Field key to compare against.
     * @param mixed $operatorOrValue Operator or expected value.
     * @param mixed $value Optional expected value when operator is provided.
     */
    public function where(string $key, mixed $operatorOrValue, mixed $value = null): self
    {
        [$operator, $expected] = $this->resolveWhereArguments($operatorOrValue, $value, func_num_args());

        return $this->filter(function (ContentPage $page) use ($key, $operator, $expected): bool {
            $actual = $this->resolveValue($page, $key);

            return $this->matchesWhere($actual, $operator, $expected);
        });
    }

    /**
     * Filter pages by resolved content type.
     *
     * @param string $type Content type name.
     */
    public function whereType(string $type): self
    {
        $normalizedType = Normalization::optionalString($type);
        if ($normalizedType === null) {
            return new self([]);
        }

        $expectedType = strtolower($normalizedType);

        return $this->filter(function (ContentPage $page) use ($expectedType): bool {
            $resolvedType = Normalization::optionalString($page->type);
            if ($resolvedType !== null) {
                return strtolower($resolvedType) === $expectedType;
            }

            $metaType = Normalization::optionalString($page->meta['type'] ?? null);
            if ($metaType !== null) {
                return strtolower($metaType) === $expectedType;
            }

            return false;
        });
    }

    /**
     * Group pages by key value.
     *
     * @param string $key Field key to group by.
     * @return array<string, \Glaze\Template\PageCollection>
     */
    public function groupBy(string $key): array
    {
        $groups = [];

        foreach ($this->pages as $page) {
            $groupKey = $this->normalizeGroupKey($this->resolveValue($page, $key));
            $groups[$groupKey] ??= [];
            $groups[$groupKey][] = $page;
        }

        ksort($groups);

        $result = [];
        foreach ($groups as $groupKey => $items) {
            $result[$groupKey] = new PageCollection($this->normalizeContentPages($items));
        }

        return $result;
    }

    /**
     * Group pages by formatted date key.
     *
     * @param string $format Date format.
     * @param string $direction Group order (`asc` or `desc`).
     * @param string $dateKey Date field key.
     * @return array<string, \Glaze\Template\PageCollection>
     */
    public function groupByDate(
        string $format = 'Y-m',
        string $direction = 'desc',
        string $dateKey = 'meta.date',
    ): array {
        $groups = [];

        foreach ($this->pages as $page) {
            $timestamp = $this->toTimestamp($this->resolveValue($page, $dateKey));
            $groupKey = $timestamp !== null ? date($format, $timestamp) : 'unknown';
            $groups[$groupKey] ??= [];
            $groups[$groupKey][] = $page;
        }

        if (strtolower($direction) === 'asc') {
            ksort($groups);
        } else {
            krsort($groups);
        }

        return array_map(static fn(array $items): PageCollection => new PageCollection($items), $groups);
    }

    /**
     * Return traversable iterator for template loops.
     *
     * @return \Traversable<int, \Glaze\Content\ContentPage>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->pages);
    }

    /**
     * Resolve where arguments into canonical operator and expected value.
     *
     * @param mixed $operatorOrValue Operator or expected value.
     * @param mixed $value Expected value.
     * @param int $argumentCount Number of received arguments.
     * @return array{string, mixed}
     */
    protected function resolveWhereArguments(mixed $operatorOrValue, mixed $value, int $argumentCount): array
    {
        if ($argumentCount === 2) {
            return ['eq', $operatorOrValue];
        }

        if (is_string($operatorOrValue) && $this->isOperator($operatorOrValue)) {
            return [self::OPERATORS[strtolower($operatorOrValue)], $value];
        }

        return ['eq', $operatorOrValue];
    }

    /**
     * Check whether a string is a supported where operator.
     *
     * @param string $operator Operator candidate.
     */
    protected function isOperator(string $operator): bool
    {
        return isset(self::OPERATORS[strtolower($operator)]);
    }

    /**
     * Compare actual and expected values using a canonical operator.
     *
     * @param mixed $actual Actual value.
     * @param string $operator Canonical operator.
     * @param mixed $expected Expected value.
     */
    protected function matchesWhere(mixed $actual, string $operator, mixed $expected): bool
    {
        return match ($operator) {
            'eq' => $actual === $expected,
            'ne' => $actual !== $expected,
            'gt' => $this->compareValues($actual, $expected) > 0,
            'ge' => $this->compareValues($actual, $expected) >= 0,
            'lt' => $this->compareValues($actual, $expected) < 0,
            'le' => $this->compareValues($actual, $expected) <= 0,
            'in' => $this->matchesIn($actual, $expected),
            'not in' => !$this->matchesIn($actual, $expected),
            'intersect' => $this->matchesIntersect($actual, $expected),
            'like' => $this->matchesLike($actual, $expected),
            default => false,
        };
    }

    /**
     * Compare two values in a deterministic way.
     *
     * @param mixed $left Left value.
     * @param mixed $right Right value.
     */
    protected function compareValues(mixed $left, mixed $right): int
    {
        if ($left === $right) {
            return 0;
        }

        if ($left === null) {
            return 1;
        }

        if ($right === null) {
            return -1;
        }

        if (is_numeric($left) && is_numeric($right)) {
            return (float)$left <=> (float)$right;
        }

        $leftTimestamp = $this->toTimestamp($left);
        $rightTimestamp = $this->toTimestamp($right);

        if ($leftTimestamp !== null && $rightTimestamp !== null) {
            return $leftTimestamp <=> $rightTimestamp;
        }

        return strcmp($this->stringifyValue($left), $this->stringifyValue($right));
    }

    /**
     * Check whether value membership condition matches.
     *
     * @param mixed $actual Actual value.
     * @param mixed $expected Expected set/container.
     */
    protected function matchesIn(mixed $actual, mixed $expected): bool
    {
        if (is_array($expected)) {
            return in_array($actual, $expected, true);
        }

        if (is_string($actual) && is_string($expected)) {
            return str_contains($expected, $actual);
        }

        return false;
    }

    /**
     * Check whether two arrays intersect.
     *
     * @param mixed $actual Actual value.
     * @param mixed $expected Expected array value.
     */
    protected function matchesIntersect(mixed $actual, mixed $expected): bool
    {
        if (!is_array($actual) || !is_array($expected)) {
            return false;
        }

        return array_intersect($actual, $expected) !== [];
    }

    /**
     * Check whether a string value matches a regex pattern.
     *
     * @param mixed $actual Actual value.
     * @param mixed $expected Regex pattern.
     */
    protected function matchesLike(mixed $actual, mixed $expected): bool
    {
        if (!is_string($actual) || !is_string($expected)) {
            return false;
        }

        $pattern = $this->normalizeRegexPattern($expected);
        $result = $this->safePregMatch($pattern, $actual);

        return $result === 1;
    }

    /**
     * Normalize a pattern to a valid `preg_match` expression.
     *
     * @param string $pattern Regex pattern.
     */
    protected function normalizeRegexPattern(string $pattern): string
    {
        $trimmed = trim($pattern);
        if ($trimmed === '') {
            return '/^$/';
        }

        $compiled = $this->safePregMatch($trimmed, '');
        if ($compiled !== null) {
            return $trimmed;
        }

        $escaped = str_replace('#', '\\#', $trimmed);

        return '#' . $escaped . '#u';
    }

    /**
     * Resolve field values from page and metadata using dot notation.
     *
     * @param \Glaze\Content\ContentPage $page Page to inspect.
     * @param string $key Field key.
     */
    protected function resolveValue(ContentPage $page, string $key): mixed
    {
        if ($key === '') {
            return null;
        }

        if (!str_contains($key, '.')) {
            return property_exists($page, $key) ? $page->{$key} : null;
        }

        if (str_starts_with($key, 'meta.')) {
            $metaPath = substr($key, 5);

            return $metaPath === '' ? $page->meta : Hash::get($page->meta, $metaPath);
        }

        if (str_starts_with($key, 'taxonomies.')) {
            $taxonomyPath = substr($key, 11);

            return $taxonomyPath === '' ? $page->taxonomies : Hash::get($page->taxonomies, $taxonomyPath);
        }

        return Hash::get($this->pageToArray($page), $key);
    }

    /**
     * Convert a value to unix timestamp when possible.
     *
     * @param mixed $value Value to convert.
     */
    protected function toTimestamp(mixed $value): ?int
    {
        if ($value instanceof DateTimeInterface) {
            return $value->getTimestamp();
        }

        if (is_int($value)) {
            return $value;
        }

        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Chronos::parse($value)->getTimestamp();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Execute `preg_match` without leaking warnings to output.
     *
     * @param string $pattern Regular expression pattern.
     * @param string $subject Subject string.
     */
    protected function safePregMatch(string $pattern, string $subject): ?int
    {
        set_error_handler(static fn(): bool => true);
        try {
            $result = preg_match($pattern, $subject);
        } finally {
            restore_error_handler();
        }

        return $result === false ? null : $result;
    }

    /**
     * Normalize any value to a stable grouping key.
     *
     * @param mixed $value Group value.
     */
    protected function normalizeGroupKey(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value)) {
            return (string)$value;
        }

        if (is_array($value)) {
            return implode(', ', array_map(fn(mixed $item): string => $this->stringifyValue($item), $value));
        }

        return 'unknown';
    }

    /**
     * Normalize values for stable `cakephp/collection` sorting.
     *
     * @param mixed $value Raw value.
     */
    protected function sortableValue(mixed $value): string|int|float
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        $timestamp = $this->toTimestamp($value);
        if ($timestamp !== null) {
            return $timestamp;
        }

        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 1 : 0;
        }

        if (is_scalar($value)) {
            return $value;
        }

        if (is_array($value)) {
            return implode(', ', array_map(fn(mixed $item): string => $this->stringifyValue($item), $value));
        }

        return '';
    }

    /**
     * Convert any value to a safe comparable string.
     *
     * @param mixed $value Value to stringify.
     */
    protected function stringifyValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_scalar($value)) {
            return (string)$value;
        }

        if (is_array($value)) {
            return implode(', ', array_map(fn(mixed $item): string => $this->stringifyValue($item), $value));
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return (string)$value;
        }

        return '';
    }

    /**
     * Normalize page object to array shape for Hash path access.
     *
     * @param \Glaze\Content\ContentPage $page Page instance.
     * @return array<string, mixed>
     */
    protected function pageToArray(ContentPage $page): array
    {
        /** @var array<string, mixed> $data */
        $data = get_object_vars($page);

        return $data;
    }

    /**
     * Normalize a mixed list into a strict `ContentPage` list.
     *
     * @param iterable<mixed> $items Candidate items.
     * @return array<\Glaze\Content\ContentPage>
     */
    protected function normalizeContentPages(iterable $items): array
    {
        $pages = [];

        foreach ($items as $item) {
            if (!$item instanceof ContentPage) {
                continue;
            }

            $pages[] = $item;
        }

        return $pages;
    }
}
