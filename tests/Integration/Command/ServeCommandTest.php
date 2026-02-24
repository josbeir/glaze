<?php
declare(strict_types=1);

namespace Glaze\Tests\Integration\Command;

use Cake\Console\TestSuite\ConsoleIntegrationTestTrait;
use Cake\Core\ConsoleApplicationInterface;
use Glaze\Application;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for the serve command.
 */
final class ServeCommandTest extends TestCase
{
    use ConsoleIntegrationTestTrait;

    /**
     * Return the console application for command runner tests.
     */
    protected function createApp(): ConsoleApplicationInterface
    {
        return new Application();
    }

    /**
     * Ensure help output includes available serve options.
     */
    public function testServeCommandHelpContainsOptions(): void
    {
        $this->exec('serve --help');

        $this->assertExitCode(0);
        $this->assertOutputContains('--host');
        $this->assertOutputContains('--port');
        $this->assertOutputContains('--root');
        $this->assertOutputContains('--build');
        $this->assertOutputContains('--static');
        $this->assertOutputContains('--drafts');
    }

    /**
     * Ensure command fails fast when project root does not exist.
     */
    public function testServeCommandFailsWithoutProjectRoot(): void
    {
        $projectRoot = $this->createTempDirectory() . '/missing';

        $this->exec(sprintf('serve --root "%s"', $projectRoot));

        $this->assertExitCode(1);
        $this->assertErrorContains('Project root not found');
    }

    /**
     * Ensure build option is only accepted in static mode.
     */
    public function testServeCommandBuildRequiresStaticMode(): void
    {
        $projectRoot = $this->createTempDirectory();

        $this->exec(sprintf('serve --root "%s" --build', $projectRoot));

        $this->assertExitCode(1);
        $this->assertErrorContains('--build can only be used together with --static');
    }

    /**
     * Ensure command rejects invalid port values.
     */
    public function testServeCommandRejectsInvalidPort(): void
    {
        $projectRoot = $this->createTempDirectory();

        $this->exec(sprintf('serve --root "%s" --port 99999', $projectRoot));

        $this->assertExitCode(1);
        $this->assertErrorContains('Invalid port');
    }

    /**
     * Ensure static mode requires a generated public directory.
     */
    public function testServeCommandStaticModeFailsWithoutPublicDirectory(): void
    {
        $projectRoot = $this->createTempDirectory();

        $this->exec(sprintf('serve --root "%s" --static', $projectRoot));

        $this->assertExitCode(1);
        $this->assertErrorContains('Public directory not found');
    }

    /**
     * Create a temporary directory for isolated test execution.
     */
    protected function createTempDirectory(): string
    {
        $path = sys_get_temp_dir() . '/glaze_test_' . uniqid('', true);
        mkdir($path, 0755, true);

        return $path;
    }
}
