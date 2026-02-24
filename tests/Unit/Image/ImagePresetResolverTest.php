<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Image;

use Glaze\Image\ImagePresetResolver;
use PHPUnit\Framework\TestCase;

/**
 * Tests for image preset and query parameter resolution.
 */
final class ImagePresetResolverTest extends TestCase
{
    /**
     * Ensure resolver returns raw query params when no preset is provided.
     */
    public function testResolveUsesRawQueryParamsWithoutPreset(): void
    {
        $resolver = new ImagePresetResolver();

        $resolved = $resolver->resolve(
            ['w' => '100', 'h' => 200],
            [],
        );

        $this->assertSame(['w' => '100', 'h' => '200'], $resolved);
    }

    /**
     * Ensure resolver merges preset values and allows query param overrides.
     */
    public function testResolveMergesPresetAndQueryParams(): void
    {
        $resolver = new ImagePresetResolver();

        $resolved = $resolver->resolve(
            ['preset' => 'thumb', 'h' => '400', 'fit' => 'crop'],
            ['thumb' => ['w' => '320', 'h' => '180']],
        );

        $this->assertSame(['w' => '320', 'h' => '400', 'fit' => 'crop'], $resolved);
    }

    /**
     * Ensure shorthand preset key is supported and unknown presets fall back safely.
     */
    public function testResolveSupportsShorthandAndUnknownPresetFallback(): void
    {
        $resolver = new ImagePresetResolver();

        $fromShorthand = $resolver->resolve(
            ['p' => 'hero', 'w' => '1200'],
            ['hero' => ['w' => '800', 'h' => '450']],
        );
        $unknownPreset = $resolver->resolve(
            ['preset' => 'missing', 'h' => '500'],
            ['hero' => ['w' => '800', 'h' => '450']],
        );

        $this->assertSame(['w' => '1200', 'h' => '450'], $fromShorthand);
        $this->assertSame(['h' => '500'], $unknownPreset);
    }

    /**
     * Ensure unsupported query values are ignored.
     */
    public function testResolveIgnoresInvalidQueryValues(): void
    {
        $resolver = new ImagePresetResolver();

        $resolved = $resolver->resolve(
            ['w' => ['invalid'], '' => 'ignored', 'fit' => 'contain'],
            [],
        );

        $this->assertSame(['fit' => 'contain'], $resolved);
    }
}
