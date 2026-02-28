<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Utility;

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
     * Ensure path-key normalization produces lowercase forward-slash lookup keys.
     */
    public function testPathKeyNormalization(): void
    {
        $this->assertSame('docs/guides/intro', Normalization::pathKey('\\Docs\\Guides\\Intro\\'));
    }

    /**
     * Ensure dot-segment normalization resolves current and parent markers.
     */
    public function testNormalizePathSegments(): void
    {
        $this->assertSame(
            'docs/images/hero.png',
            Normalization::normalizePathSegments('docs/./guides/../images/hero.png'),
        );
    }

    /**
     * Ensure path-fragment normalization keeps case and trims slashes.
     */
    public function testPathFragmentNormalization(): void
    {
        $this->assertSame('Docs/Guides', Normalization::pathFragment('\\Docs\\Guides\\'));
    }

    /**
     * Ensure optional path-fragment normalization handles empty and invalid inputs.
     */
    public function testOptionalPathFragmentNormalization(): void
    {
        $this->assertSame('Docs/Guides', Normalization::optionalPathFragment(' /Docs/Guides/ '));
        $this->assertNull(Normalization::optionalPathFragment('///'));
        $this->assertNull(Normalization::optionalPathFragment(null));
    }
}
