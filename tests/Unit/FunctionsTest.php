<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use function dd;
use function debug;
use function glaze_dump_to_string;

/**
 * Tests for global debugging helper functions.
 */
final class FunctionsTest extends TestCase
{
    /**
     * Ensure dump helper serializes scalar and structured values.
     */
    public function testGlazeDumpToStringFormatsScalarAndArrayValues(): void
    {
        $dumped = glaze_dump_to_string(['hello', ['x' => 1], null]);

        $this->assertStringContainsString('hello', $dumped);
        $this->assertStringContainsString('[x] => 1', $dumped);
    }

    /**
     * Ensure debug helper proxies to dump output in CLI mode.
     */
    public function testDebugOutputsDumpedValues(): void
    {
        ob_start();
        debug('value-1', 12);
        $output = ob_get_clean();

        $this->assertIsString($output);
        $this->assertStringContainsString('value-1', $output);
        $this->assertStringContainsString('12', $output);
    }

    /**
     * Ensure dd throws RuntimeException containing dumped payload.
     */
    public function testDdThrowsRuntimeExceptionWithDumpedOutput(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Execution stopped by dd().');
        $this->expectExceptionMessage('payload');

        dd('payload');
    }

    /**
     * Ensure dd still throws with default message when no values are provided.
     */
    public function testDdThrowsRuntimeExceptionWithoutValues(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Execution stopped by dd().');

        dd();
    }
}
