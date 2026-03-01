<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Template;

use Glaze\Content\ContentAsset;
use Glaze\Template\Collection\ContentAssetCollection;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

/**
 * Tests for content asset collection helpers.
 */
final class ContentAssetCollectionTest extends TestCase
{
    /**
     * Ensure filter helpers return expected subsets.
     */
    public function testCollectionFiltersAssetsByTypeAndPattern(): void
    {
        $collection = new ContentAssetCollection([
            $this->makeAsset('post/hero.jpg', 'hero.jpg', 'jpg', 100),
            $this->makeAsset('post/gallery/cover.png', 'cover.png', 'png', 80),
            $this->makeAsset('post/readme.pdf', 'readme.pdf', 'pdf', 120),
        ]);

        $this->assertCount(2, $collection->images());
        $this->assertCount(1, $collection->ofType('pdf'));
        $this->assertCount(1, $collection->matching('post/gallery/*'));
        $this->assertCount(0, $collection->matching('   '));
    }

    /**
     * Ensure sort helpers provide deterministic ordering.
     */
    public function testCollectionSortsByNameAndSize(): void
    {
        $collection = new ContentAssetCollection([
            $this->makeAsset('a/zeta.png', 'zeta.png', 'png', 200),
            $this->makeAsset('a/alpha.png', 'alpha.png', 'png', 50),
            $this->makeAsset('a/mid.png', 'mid.png', 'png', 100),
        ]);

        $byName = $collection->sortByName()->all();
        $this->assertSame('alpha.png', $byName[0]->filename);
        $this->assertSame('zeta.png', $byName[2]->filename);

        $bySizeDesc = $collection->sortBySize('desc')->all();
        $this->assertSame(200, $bySizeDesc[0]->size);
        $this->assertSame(50, $bySizeDesc[2]->size);
    }

    /**
     * Ensure invalid sort direction throws an exception.
     */
    public function testCollectionThrowsForInvalidSortDirection(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $collection = new ContentAssetCollection([$this->makeAsset('a/a.png', 'a.png', 'png', 1)]);
        $collection->sortByName('sideways');
    }

    /**
     * Ensure base accessors expose first and last values.
     */
    public function testCollectionFirstLastAndEmptyState(): void
    {
        $empty = new ContentAssetCollection([]);
        $this->assertTrue($empty->isEmpty());
        $this->assertNotInstanceOf(ContentAsset::class, $empty->first());
        $this->assertNotInstanceOf(ContentAsset::class, $empty->last());

        $collection = new ContentAssetCollection([
            $this->makeAsset('a/one.png', 'one.png', 'png', 1),
            $this->makeAsset('a/two.png', 'two.png', 'png', 2),
        ]);

        $this->assertFalse($collection->isEmpty());
        $this->assertSame('one.png', $collection->first()?->filename);
        $this->assertSame('two.png', $collection->last()?->filename);
    }

    /**
     * Ensure API parity helpers behave like PageCollection counterparts.
     */
    public function testCollectionSupportsFilterTakeSliceAndReverse(): void
    {
        $collection = new ContentAssetCollection([
            $this->makeAsset('a/one.png', 'one.png', 'png', 1),
            $this->makeAsset('a/two.jpg', 'two.jpg', 'jpg', 2),
            $this->makeAsset('a/three.pdf', 'three.pdf', 'pdf', 3),
        ]);

        $this->assertCount(2, $collection->filter(static fn(ContentAsset $asset): bool => $asset->isImage()));

        $taken = $collection->take(2)->all();
        $this->assertCount(2, $taken);
        $this->assertSame('one.png', $taken[0]->filename);
        $this->assertSame('two.jpg', $taken[1]->filename);

        $sliced = $collection->slice(1, 1)->all();
        $this->assertCount(1, $sliced);
        $this->assertSame('two.jpg', $sliced[0]->filename);

        $reversed = $collection->reverse()->all();
        $this->assertSame('three.pdf', $reversed[0]->filename);
        $this->assertSame('one.png', $reversed[2]->filename);
    }

    /**
     * Build content asset fixture object.
     *
     * @param string $relativePath Content-relative path.
     * @param string $filename File name.
     * @param string $extension File extension.
     * @param int $size File size.
     */
    protected function makeAsset(string $relativePath, string $filename, string $extension, int $size): ContentAsset
    {
        return new ContentAsset(
            relativePath: $relativePath,
            urlPath: '/' . $relativePath,
            absolutePath: '/tmp/' . $relativePath,
            filename: $filename,
            extension: $extension,
            size: $size,
        );
    }
}
