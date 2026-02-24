<?php
declare(strict_types=1);

namespace Glaze\Tests\Integration\Command;

use Cake\Console\TestSuite\ConsoleIntegrationTestTrait;
use Cake\Core\ConsoleApplicationInterface;
use Glaze\Application;
use PHPUnit\Framework\TestCase;

/**
 * Integration tests for the build command.
 */
final class BuildCommandTest extends TestCase
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
     * Ensure build command generates output from fixture content.
     */
    public function testBuildCommandGeneratesStaticOutput(): void
    {
        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/content', 0755, true);
        mkdir($projectRoot . '/templates', 0755, true);

        file_put_contents($projectRoot . '/content/index.dj', "# Home\n");
        file_put_contents($projectRoot . '/templates/page.sugar.php', '<html><body><?= $content |> raw() ?></body></html>');

        $this->exec(sprintf('build --root "%s"', $projectRoot));

        $this->assertExitCode(0);
        $this->assertOutputContains('Build complete: 1 page(s).');
        $this->assertFileExists($projectRoot . '/public/index.html');
    }

    /**
     * Ensure drafts are excluded by default and included with --drafts.
     */
    public function testBuildCommandDraftFiltering(): void
    {
        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/content', 0755, true);
        mkdir($projectRoot . '/templates', 0755, true);

        file_put_contents($projectRoot . '/content/index.dj', "# Home\n");
        file_put_contents($projectRoot . '/content/draft.dj', "+++\ndraft: true\n+++\n# Draft\n");
        file_put_contents($projectRoot . '/templates/page.sugar.php', '<html><body><?= $content |> raw() ?></body></html>');

        $this->exec(sprintf('build --root "%s"', $projectRoot));

        $this->assertExitCode(0);
        $this->assertOutputContains('Build complete: 1 page(s).');
        $this->assertFileDoesNotExist($projectRoot . '/public/draft/index.html');

        $this->exec(sprintf('build --root "%s" --drafts', $projectRoot));

        $this->assertExitCode(0);
        $this->assertOutputContains('Build complete: 2 page(s).');
        $this->assertFileExists($projectRoot . '/public/draft/index.html');
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
