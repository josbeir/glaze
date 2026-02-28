<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Process;

use Glaze\Process\NpmInstallProcess;
use Glaze\Tests\Helper\FilesystemTestTrait;
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
}
