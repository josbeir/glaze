<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Serve;

use Glaze\Serve\ViteBuildConfig;
use Glaze\Serve\ViteBuildService;
use Glaze\Tests\Helper\FilesystemTestTrait;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Unit tests for Vite build service.
 */
final class ViteBuildServiceTest extends TestCase
{
    use FilesystemTestTrait;

    /**
     * Ensure build command is executed successfully.
     */
    public function testRunExecutesViteBuildCommand(): void
    {
        $projectRoot = $this->createTempDirectory();
        $service = new ViteBuildService();
        $configuration = new ViteBuildConfig(
            enabled: true,
            command: "php -r \"file_put_contents('vite-ok.txt', 'ok');\"",
        );

        $service->run($configuration, $projectRoot);

        $this->assertFileExists($projectRoot . '/vite-ok.txt');
        $this->assertSame('ok', file_get_contents($projectRoot . '/vite-ok.txt'));
    }

    /**
     * Ensure failed build command surfaces a runtime error.
     */
    public function testRunThrowsForFailingCommand(): void
    {
        $projectRoot = $this->createTempDirectory();
        $service = new ViteBuildService();
        $configuration = new ViteBuildConfig(
            enabled: true,
            command: "php -r \"fwrite(STDERR, 'boom'); exit(1);\"",
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to run Vite build command');

        $service->run($configuration, $projectRoot);
    }
}
