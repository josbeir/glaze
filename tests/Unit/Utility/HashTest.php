<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Utility;

use Glaze\Utility\Hash;
use PHPUnit\Framework\TestCase;

/**
 * Tests for shared hash utility helpers.
 */
final class HashTest extends TestCase
{
    /**
     * Ensure xxh3 helper returns deterministic hashes for the same input.
     */
    public function testMakeIsDeterministic(): void
    {
        $hashA = Hash::make('same-value');
        $hashB = Hash::make('same-value');

        $this->assertSame($hashA, $hashB);
        $this->assertNotSame(Hash::make('same-value'), Hash::make('different-value'));
        $this->assertSame(hash(Hash::ALGORITHM, 'same-value'), $hashA);
    }

    /**
     * Ensure xxh3FromParts preserves ordering and delimiter behavior.
     */
    public function testMakeFromPartsUsesOrderedParts(): void
    {
        $left = Hash::makeFromParts(['a', 'b', 'c']);
        $right = Hash::makeFromParts(['a', 'c', 'b']);

        $this->assertNotSame($left, $right);
        $this->assertSame(Hash::make('a|b|c'), $left);
    }
}
