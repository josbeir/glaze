<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Serve;

use Glaze\Serve\ViteProcessService;
use Glaze\Serve\ViteServeConfig;
use Glaze\Tests\Helper\FilesystemTestTrait;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\Process\Process;

/**
 * Unit tests for Vite process service.
 */
final class ViteProcessServiceTest extends TestCase
{
    use FilesystemTestTrait;

    /**
     * Ensure start rejects invalid configuration objects.
     */
    public function testStartThrowsForInvalidConfigurationObject(): void
    {
        $service = new ViteProcessService();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid configuration type');

        $service->start(new stdClass(), $this->createTempDirectory());
    }

    /**
     * Ensure start returns a running process for valid configuration.
     */
    public function testStartReturnsRunningProcess(): void
    {
        $service = new ViteProcessService();
        $configuration = new ViteServeConfig(
            enabled: true,
            host: '127.0.0.1',
            port: 5173,
            command: 'php -r "sleep(2);"',
        );

        $process = $service->start($configuration, $this->createTempDirectory());

        $this->assertInstanceOf(Process::class, $process);
        $this->assertTrue($process->isRunning());

        $service->stop($process);
        $this->assertFalse($process->isRunning());
    }

    /**
     * Ensure start throws runtime exception when process fails immediately.
     */
    public function testStartThrowsWhenProcessFailsImmediately(): void
    {
        $service = new ViteProcessService();
        $configuration = new ViteServeConfig(
            enabled: true,
            host: '127.0.0.1',
            port: 5173,
            command: 'php -r "fwrite(STDERR, \"boom\"); exit(1);"',
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to start Vite process using command');

        $service->start($configuration, $this->createTempDirectory());
    }

    /**
     * Ensure stop handles non-process runtime values without error.
     */
    public function testStopDoesNothingForNonProcessRuntime(): void
    {
        $this->expectNotToPerformAssertions();

        $service = new ViteProcessService();
        $service->stop('not-a-process');
    }
}
