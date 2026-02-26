<?php
declare(strict_types=1);

namespace Glaze\Tests\Integration\Command;

use Glaze\Tests\Helper\IntegrationCommandTestCase;

/**
 * Integration tests for the serve command.
 */
final class ServeCommandTest extends IntegrationCommandTestCase
{
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
        $this->assertOutputContains('--vite');
        $this->assertOutputContains('--vite-host');
        $this->assertOutputContains('--vite-port');
        $this->assertOutputContains('--vite-command');
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
     * Ensure version header is shown when verbose mode is enabled.
     */
    public function testServeCommandShowsHeaderInVerboseMode(): void
    {
        $projectRoot = $this->createTempDirectory() . '/missing';

        $this->exec(sprintf('serve --root "%s" --verbose', $projectRoot));

        $this->assertExitCode(1);
        $this->assertOutputContains('Glaze');
        $this->assertErrorContains('Project root not found');
    }

    /**
     * Ensure build option is only accepted in static mode.
     */
    public function testServeCommandBuildRequiresStaticMode(): void
    {
        $projectRoot = $this->copyFixtureToTemp('projects/basic');

        $this->exec(sprintf('serve --root "%s" --build', $projectRoot));

        $this->assertExitCode(1);
        $this->assertErrorContains('--build can only be used together with --static');
    }

    /**
     * Ensure Vite integration is rejected when static mode is enabled.
     */
    public function testServeCommandViteRequiresLiveMode(): void
    {
        $projectRoot = $this->copyFixtureToTemp('projects/basic');

        $this->exec(sprintf('serve --root "%s" --static --vite', $projectRoot));

        $this->assertExitCode(1);
        $this->assertErrorContains('--vite can only be used in live mode');
    }

    /**
     * Ensure command rejects invalid port values.
     */
    public function testServeCommandRejectsInvalidPort(): void
    {
        $projectRoot = $this->copyFixtureToTemp('projects/basic');

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
     * Ensure static build errors are surfaced and command exits with error.
     */
    public function testServeCommandStaticBuildReportsBuildFailure(): void
    {
        $projectRoot = $this->copyFixtureToTemp('projects/basic');
        file_put_contents($projectRoot . '/public', 'not-a-directory');

        set_error_handler(static fn(): bool => true);
        try {
            $this->exec(sprintf('serve --root "%s" --static --build', $projectRoot));
        } finally {
            restore_error_handler();
        }

        $this->assertExitCode(1);
        $this->assertErrorContains('Unable to create output directory');
    }

    /**
     * Ensure missing live router configuration is reported as command error.
     */
    public function testServeCommandLiveModeFailsWhenRouterIsMissing(): void
    {
        $projectRoot = $this->createTempDirectory();

        $this->exec(sprintf('serve --root "%s"', $projectRoot));

        $this->assertExitCode(1);
        $this->assertErrorContains('Live router script not found');
    }

    /**
     * Ensure non-verbose mode prints compact server URL line.
     */
    public function testServeCommandPrintsCompactServerLineInNonVerboseMode(): void
    {
        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/content', 0755, true);
        mkdir($projectRoot . '/templates', 0755, true);
        file_put_contents($projectRoot . '/glaze.neon', "site:\n  title: Test\n");

        $this->exec(sprintf(
            'serve --root "%s" --host invalid_host_name --port 8099',
            $projectRoot,
        ));

        $this->assertExitCode(1);
        $this->assertOutputContains('Glaze development server:');
        $this->assertOutputContains('http://invalid_host_name:8099');
    }

    /**
     * Ensure verbose mode prints detailed serve context and process hints.
     */
    public function testServeCommandPrintsDetailedOutputInVerboseMode(): void
    {
        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/content', 0755, true);
        mkdir($projectRoot . '/templates', 0755, true);
        file_put_contents($projectRoot . '/glaze.neon', "site:\n  title: Test\n");

        $this->exec(sprintf(
            'serve --root "%s" --host invalid_host_name --port 8099 --verbose',
            $projectRoot,
        ));

        $this->assertExitCode(1);
        $this->assertOutputContains('version <success>');
        $this->assertOutputContains('Serving live templates/content from');
        $this->assertOutputContains('Press Ctrl+C to stop.');
    }
}
