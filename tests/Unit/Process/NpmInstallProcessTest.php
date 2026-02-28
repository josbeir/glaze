<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Process;

use Glaze\Process\NpmInstallProcess;
use Glaze\Tests\Helper\FilesystemTestTrait;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for the npm install process.
 */
final class NpmInstallProcessTest extends TestCase
{
    use FilesystemTestTrait;

    /**
     * Ensure the process does not throw when the command succeeds.
     */
    public function testStartDoesNotThrowWhenCommandSucceeds(): void
    {
        $process = new NpmInstallProcess('php -r "exit(0);"');

        $process->start([], $this->createTempDirectory());

        $this->addToAssertionCount(1);
    }

    /**
     * Ensure the process throws when the command fails.
     */
    public function testStartThrowsWhenCommandFails(): void
    {
        $process = new NpmInstallProcess('php -r "fwrite(STDERR, \"boom\"); exit(1);"');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to run npm install command');
        $this->expectExceptionMessage('boom');

        $process->start([], $this->createTempDirectory());
    }

    /**
     * Ensure the process accepts command override from configuration.
     */
    public function testStartUsesCommandOverrideFromConfiguration(): void
    {
        $projectRoot = $this->createTempDirectory();
        $process = new NpmInstallProcess('php -r "exit(1);"');

        $process->start([
            'command' => "php -r \"file_put_contents('npm-ok.txt', 'ok');\"",
        ], $projectRoot);

        $this->assertFileExists($projectRoot . '/npm-ok.txt');
    }

    /**
     * Ensure invalid command override values are rejected.
     */
    public function testStartThrowsForInvalidCommandOverride(): void
    {
        $process = new NpmInstallProcess();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid configuration for');

        $process->start(['command' => '  '], $this->createTempDirectory());
    }

    /**
     * Ensure stop is a no-op for any runtime value.
     */
    public function testStopDoesNothing(): void
    {
        $process = new NpmInstallProcess();

        $this->expectNotToPerformAssertions();

        $process->stop('runtime');
    }
}
