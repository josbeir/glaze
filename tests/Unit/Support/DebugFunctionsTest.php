<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Support;

use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for global debug helper functions.
 */
final class DebugFunctionsTest extends TestCase
{
    /**
     * Ensure dump helper outputs scalar and complex values.
     */
    public function testDumpOutputsValues(): void
    {
        ob_start();
        dump('alpha', ['k' => 'v']);
        $output = ob_get_clean();

        $this->assertIsString($output);
        $this->assertStringContainsString('alpha', $output);
        $this->assertStringContainsString('k', $output);
        $this->assertStringContainsString('v', $output);
    }

    /**
     * Ensure debug helper behaves as dump alias.
     */
    public function testDebugAliasesDump(): void
    {
        ob_start();
        debug('beta');
        $output = ob_get_clean();

        $this->assertIsString($output);
        $this->assertStringContainsString('beta', $output);
    }

    /**
     * Ensure dd helper dumps values and stops execution.
     */
    public function testDdDumpsAndThrows(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Execution stopped by dd\(\)\..*gamma/s');

        dd('gamma');
    }
}
