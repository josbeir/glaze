<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Utility;

use Glaze\Utility\Normalization;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Tests for shared normalization utility helpers.
 */
final class NormalizationTest extends TestCase
{
    /**
     * Ensure optional string normalization trims valid values and rejects invalid input.
     */
    public function testOptionalStringNormalization(): void
    {
        $this->assertSame('value', Normalization::optionalString('  value  '));
        $this->assertNull(Normalization::optionalString('   '));
        $this->assertNull(Normalization::optionalString(123));
    }

    /**
     * Ensure optional scalar string normalization supports scalar values only.
     */
    public function testOptionalScalarStringNormalization(): void
    {
        $this->assertSame('42', Normalization::optionalScalarString(42));
        $this->assertSame('yes', Normalization::optionalScalarString(' yes '));
        $this->assertNull(Normalization::optionalScalarString(['invalid']));
        $this->assertNull(Normalization::optionalScalarString('   '));
    }

    /**
     * Ensure string map normalization ignores invalid keys and values.
     */
    public function testStringMapNormalization(): void
    {
        $normalized = Normalization::stringMap([
            'robots' => ' noindex ',
            '' => 'ignored-empty-key',
            5 => 'ignored-numeric-key',
            'complex' => ['invalid'],
            'count' => 10,
        ]);

        $this->assertSame([
            'robots' => 'noindex',
            'count' => '10',
        ], $normalized);
    }

    /**
     * Ensure string list normalization keeps only trimmed non-empty strings.
     */
    public function testStringListNormalization(): void
    {
        $normalized = Normalization::stringList([' tags ', '', 'news', 5, true, 'news']);

        $this->assertSame(['tags', 'news', 'news'], $normalized);
    }

    /**
     * Ensure path normalization standardizes separators and trailing separator handling.
     */
    public function testPathNormalization(): void
    {
        $normalized = Normalization::path('/tmp/glaze/site/');

        $this->assertSame('/tmp/glaze/site', str_replace('\\', '/', $normalized));
    }

    /**
     * Ensure optional path normalization returns null for invalid values.
     */
    public function testOptionalPathNormalization(): void
    {
        $normalized = Normalization::optionalPath(' /tmp/glaze/site/ ');

        $this->assertSame('/tmp/glaze/site', str_replace('\\', '/', (string)$normalized));
        $this->assertNull(Normalization::optionalPath('   '));
        $this->assertNull(Normalization::optionalPath(null));
    }

    /**
     * Ensure nested array normalization preserves map/list structures.
     */
    public function testNormalizeNestedArrayPreservesStructure(): void
    {
        $normalized = Normalization::normalizeNestedArray(
            value: [
                'hero' => [
                    'title' => 'Title',
                    'actions' => [
                        ['label' => 'Read docs'],
                    ],
                ],
                'flags' => [true, false, 1],
            ],
            normalizeListItem: static fn(mixed $item): mixed => $item,
            normalizeMapItem: static fn(string $key, mixed $item): mixed => $item,
        );

        $this->assertSame([
            'hero' => [
                'title' => 'Title',
                'actions' => [
                    ['label' => 'Read docs'],
                ],
            ],
            'flags' => [true, false, 1],
        ], $normalized);
    }

    /**
     * Ensure nested array normalization filters unsupported normalized values.
     */
    public function testNormalizeNestedArrayFiltersUnsupportedValues(): void
    {
        $normalized = Normalization::normalizeNestedArray(
            value: [
                'keep' => 'ok',
                'skip' => new stdClass(),
            ],
            normalizeListItem: static fn(mixed $item): mixed => $item,
            normalizeMapItem: static fn(string $key, mixed $item): mixed => $item,
            isAcceptedValue: static fn(mixed $item): bool => is_scalar($item) || $item === null || is_array($item),
        );

        $this->assertSame(['keep' => 'ok'], $normalized);
    }
}
