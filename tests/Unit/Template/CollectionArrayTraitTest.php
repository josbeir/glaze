<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Template;

use Glaze\Tests\Fixture\Template\CollectionArrayTraitFixture;
use PHPUnit\Framework\TestCase;

/**
 * Tests for shared CollectionArrayTrait behavior.
 */
final class CollectionArrayTraitTest extends TestCase
{
    /**
     * Validate count and emptiness helpers.
     */
    public function testTraitCountAndEmptyHelpers(): void
    {
        $empty = new CollectionArrayTraitFixture([]);
        $filled = new CollectionArrayTraitFixture(['one', 'two']);

        $this->assertSame(0, $empty->count());
        $this->assertTrue($empty->isEmpty());

        $this->assertSame(2, $filled->count());
        $this->assertFalse($filled->isEmpty());
    }

    /**
     * Validate first and last accessors.
     */
    public function testTraitFirstAndLastAccessors(): void
    {
        $empty = new CollectionArrayTraitFixture([]);
        $filled = new CollectionArrayTraitFixture(['one', 'two', 'three']);

        $this->assertNull($empty->first());
        $this->assertNull($empty->last());
        $this->assertSame('one', $filled->first());
        $this->assertSame('three', $filled->last());
    }

    /**
     * Validate take, slice, and reverse transformations.
     */
    public function testTraitTakeSliceAndReverseHelpers(): void
    {
        $fixture = new CollectionArrayTraitFixture(['one', 'two', 'three']);

        $this->assertSame(['one', 'two'], $fixture->take(2)->all());
        $this->assertSame([], $fixture->take(-2)->all());
        $this->assertSame(['two', 'three'], $fixture->slice(1)->all());
        $this->assertSame(['two'], $fixture->slice(1, 1)->all());
        $this->assertSame(['three', 'two', 'one'], $fixture->reverse()->all());
    }
}
