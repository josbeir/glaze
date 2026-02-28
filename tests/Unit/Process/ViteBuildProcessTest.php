<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Process;

use Glaze\Process\ViteBuildProcess;
use Glaze\Tests\Helper\FilesystemTestTrait;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for Vite build process.
 */
final class ViteBuildProcessTest extends TestCase
{
    use FilesystemTestTrait;

    /**
     * Ensure build command is executed successfully.
     */
    public function testStartExecutesViteBuildCommand(): void
    {
        $projectRoot = $this->createTempDirectory();
        $process = new ViteBuildProcess();
        $configuration = [
            'command' => "php -r \"file_put_contents('vite-ok.txt', 'ok');\"",
        ];

        $process->start($configuration, $projectRoot);

        $this->assertFileExists($projectRoot . '/vite-ok.txt');
        $this->assertSame('ok', file_get_contents($projectRoot . '/vite-ok.txt'));
    }

    /**
     * Ensure failed build command surfaces a runtime error.
     */
    public function testStartThrowsForFailingCommand(): void
    {
        $projectRoot = $this->createTempDirectory();
        $process = new ViteBuildProcess();
        $configuration = [
            'command' => "php -r \"fwrite(STDERR, 'boom'); exit(1);\"",
        ];

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to run Vite build command');

        $process->start($configuration, $projectRoot);
    }

    /**
     * Ensure invalid configuration is rejected when command is missing.
     */
    public function testStartThrowsForMissingCommandConfiguration(): void
    {
        $process = new ViteBuildProcess();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid configuration for');

        $process->start([], $this->createTempDirectory());
    }

    /**
     * Ensure stop is a no-op for compatibility with ProcessInterface.
     */
    public function testStopDoesNothing(): void
    {
        $process = new ViteBuildProcess();

        $this->expectNotToPerformAssertions();

        $process->stop('runtime');
    }
}
