<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Support;

use Glaze\Utility\Normalization;
use PHPUnit\Framework\TestCase;

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
}
