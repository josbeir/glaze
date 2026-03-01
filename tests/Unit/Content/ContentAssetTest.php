<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Content;

use Glaze\Content\ContentAsset;
use PHPUnit\Framework\TestCase;

/**
 * Tests for content asset value object helpers.
 */
final class ContentAssetTest extends TestCase
{
    /**
     * Ensure extension matching works with normalized and dotted inputs.
     */
    public function testIsMatchesNormalizedExtensions(): void
    {
        $asset = new ContentAsset(
            relativePath: 'post/image.jpg',
            urlPath: '/post/image.jpg',
            absolutePath: '/tmp/post/image.jpg',
            filename: 'image.jpg',
            extension: 'jpg',
            size: 123,
        );

        $this->assertTrue($asset->is('jpg'));
        $this->assertTrue($asset->is('.jpg'));
        $this->assertTrue($asset->is('png', 'JPG'));
        $this->assertFalse($asset->is('   ', ' .  '));
        $this->assertFalse($asset->is('png'));
        $this->assertFalse($asset->is());
    }

    /**
     * Ensure isImage detects supported image extensions.
     */
    public function testIsImageChecksSupportedExtensions(): void
    {
        $image = new ContentAsset(
            relativePath: 'gallery/photo.webp',
            urlPath: '/gallery/photo.webp',
            absolutePath: '/tmp/gallery/photo.webp',
            filename: 'photo.webp',
            extension: 'webp',
            size: 10,
        );
        $document = new ContentAsset(
            relativePath: 'docs/readme.pdf',
            urlPath: '/docs/readme.pdf',
            absolutePath: '/tmp/docs/readme.pdf',
            filename: 'readme.pdf',
            extension: 'pdf',
            size: 10,
        );

        $this->assertTrue($image->isImage());
        $this->assertFalse($document->isImage());
    }
}
