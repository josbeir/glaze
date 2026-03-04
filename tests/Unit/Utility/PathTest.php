<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Utility;

use Glaze\Utility\Path;
use PHPUnit\Framework\TestCase;

/**
 * Tests for path utility helpers.
 */
final class PathTest extends TestCase
{
    /**
     * Ensure path normalization standardizes separators and trailing separator handling.
     */
    public function testNormalize(): void
    {
        $this->assertSame('/tmp/glaze/site', Path::normalize('/tmp/glaze/site/'));
        $this->assertSame('/tmp/glaze/site', Path::normalize('\\tmp\\glaze\\site\\'));
    }

    /**
     * Ensure absolute path detection supports Unix, UNC, and Windows drive formats.
     */
    public function testIsAbsolute(): void
    {
        $this->assertTrue(Path::isAbsolute('/tmp/glaze/site'));
        $this->assertTrue(Path::isAbsolute('//server/share/site'));
        $this->assertTrue(Path::isAbsolute('C:\\tmp\\glaze\\site'));
        $this->assertFalse(Path::isAbsolute('tmp/glaze/site'));
        $this->assertFalse(Path::isAbsolute(''));
    }

    /**
     * Ensure path resolution preserves absolute paths and joins relative values to base paths.
     */
    public function testResolve(): void
    {
        $this->assertSame('/tmp/glaze/site', Path::resolve('/var/www', '/tmp/glaze/site/'));
        $this->assertSame('/var/www/content/posts', Path::resolve('/var/www', 'content/posts'));
    }

    /**
     * Ensure optional path normalization returns null for invalid values.
     */
    public function testOptional(): void
    {
        $this->assertSame('/tmp/glaze/site', Path::optional(' /tmp/glaze/site/ '));
        $this->assertNull(Path::optional('   '));
        $this->assertNull(Path::optional(null));
    }

    /**
     * Ensure key normalization produces lowercase forward-slash lookup keys.
     */
    public function testKey(): void
    {
        $this->assertSame('docs/guides/intro', Path::key('\\Docs\\Guides\\Intro\\'));
    }

    /**
     * Ensure segment normalization resolves current and parent markers.
     */
    public function testNormalizeSegments(): void
    {
        $this->assertSame('docs/images/hero.png', Path::normalizeSegments('docs/./guides/../images/hero.png'));
    }

    /**
     * Ensure segment normalization skips empty segments from double slashes.
     */
    public function testNormalizeSegmentsSkipsEmptySegments(): void
    {
        $this->assertSame('docs/images/hero.png', Path::normalizeSegments('docs//images/hero.png'));
    }

    /**
     * Ensure fragment normalization keeps case and trims slashes.
     */
    public function testFragment(): void
    {
        $this->assertSame('Docs/Guides', Path::fragment('\\Docs\\Guides\\'));
    }

    /**
     * Ensure optional fragment normalization handles empty and invalid inputs.
     */
    public function testOptionalFragment(): void
    {
        $this->assertSame('Docs/Guides', Path::optionalFragment(' /Docs/Guides/ '));
        $this->assertNull(Path::optionalFragment('///'));
        $this->assertNull(Path::optionalFragment(null));
    }
}
