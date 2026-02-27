<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Scaffold;

use Glaze\Scaffold\NpmInstallService;
use Glaze\Tests\Helper\FilesystemTestTrait;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for npm install service.
 */
final class NpmInstallServiceTest extends TestCase
{
    use FilesystemTestTrait;

    /**
     * Ensure installer does not throw when command succeeds.
     */
    public function testInstallDoesNotThrowWhenCommandSucceeds(): void
    {
        $service = new NpmInstallService('php -r "exit(0);"');

        $service->install($this->createTempDirectory());

        $this->addToAssertionCount(1);
    }

    /**
     * Ensure installer throws a runtime exception when command fails.
     */
    public function testInstallThrowsWhenCommandFails(): void
    {
        $service = new NpmInstallService('php -r "fwrite(STDERR, \"boom\"); exit(1);"');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to run npm install command');
        $this->expectExceptionMessage('boom');

        $service->install($this->createTempDirectory());
    }
}
