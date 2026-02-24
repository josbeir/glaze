<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Template;

use Glaze\Template\PageCollection;
use Glaze\Template\TaxonomyCollection;
use PHPUnit\Framework\TestCase;

/**
 * Tests for taxonomy term collection wrapper.
 */
final class TaxonomyCollectionTest extends TestCase
{
    /**
     * Ensure term access, normalization, and iteration work as expected.
     */
    public function testTaxonomyCollectionOperations(): void
    {
        $taxonomy = new TaxonomyCollection([
            'php' => new PageCollection([]),
            'cake' => new PageCollection([]),
        ]);

        $this->assertCount(2, $taxonomy);
        $this->assertSame(['cake', 'php'], $taxonomy->terms());
        $this->assertTrue($taxonomy->hasTerm('PHP'));
        $this->assertFalse($taxonomy->hasTerm('missing'));
        $this->assertInstanceOf(PageCollection::class, $taxonomy->term('php'));
        $this->assertInstanceOf(PageCollection::class, $taxonomy->term('missing'));
        $this->assertSame(['cake', 'php'], array_keys($taxonomy->all()));

        $iterated = [];
        foreach ($taxonomy as $term => $pages) {
            $iterated[$term] = $pages->count();
        }

        $this->assertSame(['cake' => 0, 'php' => 0], $iterated);
    }
}
