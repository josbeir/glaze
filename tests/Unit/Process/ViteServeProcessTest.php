<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Process;

use Glaze\Process\ViteServeProcess;
use Glaze\Tests\Helper\FilesystemTestTrait;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Unit tests for Vite serve process.
 */
final class ViteServeProcessTest extends TestCase
{
    use FilesystemTestTrait;

    /**
     * Ensure start rejects invalid configuration arrays.
     */
    public function testStartThrowsForInvalidConfigurationArray(): void
    {
        $process = new ViteServeProcess();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid configuration for');

        $process->start([], $this->createTempDirectory());
    }

    /**
     * Ensure start returns a running process for valid configuration.
     */
    public function testStartReturnsRunningProcess(): void
    {
        $process = new ViteServeProcess();
        $configuration = [
            'enabled' => true,
            'host' => '127.0.0.1',
            'port' => 5173,
            'command' => 'php -r "sleep(2);"',
        ];

        $runtime = $process->start($configuration, $this->createTempDirectory());

        $this->assertInstanceOf(Process::class, $runtime);
        $this->assertTrue($runtime->isRunning());

        $process->stop($runtime);
        $this->assertFalse($runtime->isRunning());
    }

    /**
     * Ensure start throws runtime exception when process fails immediately.
     */
    public function testStartThrowsWhenProcessFailsImmediately(): void
    {
        $process = new ViteServeProcess();
        $configuration = [
            'enabled' => true,
            'host' => '127.0.0.1',
            'port' => 5173,
            'command' => 'php -r "fwrite(STDERR, \"boom\"); exit(1);"',
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to start Vite process using command');

        $process->start($configuration, $this->createTempDirectory());
    }

    /**
     * Ensure stop handles non-process runtime values without error.
     */
    public function testStopDoesNothingForNonProcessRuntime(): void
    {
        $this->expectNotToPerformAssertions();

        $process = new ViteServeProcess();
        $process->stop('not-a-process');
    }
}
