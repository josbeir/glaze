<?php
declare(strict_types=1);

namespace Glaze\Tests\Integration\Command;

use Glaze\Tests\Helper\IntegrationCommandTestCase;

/**
 * Integration tests for the cc command.
 */
final class CacheCommandTest extends IntegrationCommandTestCase
{
    /**
     * Ensure an empty project (no cache directories) exits successfully and prints skip messages.
     */
    public function testClearOnProjectWithNoCacheDirectoriesSkipsGracefully(): void
    {
        $projectRoot = $this->createTempDirectory();
        $this->writeMinimalConfig($projectRoot);

        $this->exec(sprintf('cc --root "%s"', $projectRoot));

        $this->assertExitCode(0);
        $this->assertOutputContains('nothing to clear');
    }

    /**
     * Ensure both caches are cleared and success messages are printed when no flags are given.
     */
    public function testClearBothCachesByDefault(): void
    {
        $projectRoot = $this->createTempDirectory();
        $this->writeMinimalConfig($projectRoot);

        $templateCache = $projectRoot . '/tmp/cache/sugar';
        $imageCache = $projectRoot . '/tmp/cache/glide';
        mkdir($templateCache, 0755, true);
        mkdir($imageCache, 0755, true);

        file_put_contents($templateCache . '/template.php', '<?php echo 1;');
        file_put_contents($imageCache . '/img.jpg', 'binary');

        $this->exec(sprintf('cc --root "%s"', $projectRoot));

        $this->assertExitCode(0);
        $this->assertOutputContains('[Cache/Templates]');
        $this->assertOutputContains('[Cache/Images]');
        $this->assertDirectoryExists($templateCache);
        $this->assertDirectoryExists($imageCache);
        $this->assertFileDoesNotExist($templateCache . '/template.php');
        $this->assertFileDoesNotExist($imageCache . '/img.jpg');
    }

    /**
     * Ensure only the template cache is cleared when --templates is passed.
     */
    public function testClearOnlyTemplatesWhenFlagIsSet(): void
    {
        $projectRoot = $this->createTempDirectory();
        $this->writeMinimalConfig($projectRoot);

        $templateCache = $projectRoot . '/tmp/cache/sugar';
        $imageCache = $projectRoot . '/tmp/cache/glide';
        mkdir($templateCache, 0755, true);
        mkdir($imageCache, 0755, true);

        file_put_contents($templateCache . '/template.php', '<?php echo 1;');
        file_put_contents($imageCache . '/img.jpg', 'binary');

        $this->exec(sprintf('cc --templates --root "%s"', $projectRoot));

        $this->assertExitCode(0);
        $this->assertOutputContains('[Cache/Templates]');
        $this->assertOutputNotContains('[Cache/Images]');
        $this->assertFileDoesNotExist($templateCache . '/template.php');
        $this->assertFileExists($imageCache . '/img.jpg');
    }

    /**
     * Ensure only the image cache is cleared when --images is passed.
     */
    public function testClearOnlyImagesWhenFlagIsSet(): void
    {
        $projectRoot = $this->createTempDirectory();
        $this->writeMinimalConfig($projectRoot);

        $templateCache = $projectRoot . '/tmp/cache/sugar';
        $imageCache = $projectRoot . '/tmp/cache/glide';
        mkdir($templateCache, 0755, true);
        mkdir($imageCache, 0755, true);

        file_put_contents($templateCache . '/template.php', '<?php echo 1;');
        file_put_contents($imageCache . '/img.jpg', 'binary');

        $this->exec(sprintf('cc --images --root "%s"', $projectRoot));

        $this->assertExitCode(0);
        $this->assertOutputContains('[Cache/Images]');
        $this->assertOutputNotContains('[Cache/Templates]');
        $this->assertFileDoesNotExist($imageCache . '/img.jpg');
        $this->assertFileExists($templateCache . '/template.php');
    }

    /**
     * Ensure the cache directory itself is preserved (not removed) after clearing.
     */
    public function testCachDirectoryIsPreservedAfterClearing(): void
    {
        $projectRoot = $this->createTempDirectory();
        $this->writeMinimalConfig($projectRoot);

        $templateCache = $projectRoot . '/tmp/cache/sugar';
        mkdir($templateCache, 0755, true);
        file_put_contents($templateCache . '/template.php', '<?php echo 1;');

        $this->exec(sprintf('cc --templates --root "%s"', $projectRoot));

        $this->assertExitCode(0);
        $this->assertDirectoryExists($templateCache);
    }

    /**
     * Ensure nested subdirectories inside a cache directory are removed.
     */
    public function testNestedSubdirectoriesInsideCacheAreRemoved(): void
    {
        $projectRoot = $this->createTempDirectory();
        $this->writeMinimalConfig($projectRoot);

        $templateCache = $projectRoot . '/tmp/cache/sugar';
        $nested = $templateCache . '/partials/sub';
        mkdir($nested, 0755, true);
        file_put_contents($nested . '/partial.php', '<?php echo 1;');

        $this->exec(sprintf('cc --templates --root "%s"', $projectRoot));

        $this->assertExitCode(0);
        $this->assertDirectoryExists($templateCache);
        $this->assertDirectoryDoesNotExist($nested);
        $this->assertFileDoesNotExist($nested . '/partial.php');
    }

    /**
     * Ensure the command prints the version header.
     */
    public function testCommandPrintsVersionHeader(): void
    {
        $projectRoot = $this->createTempDirectory();
        $this->writeMinimalConfig($projectRoot);

        $this->exec(sprintf('cc --root "%s"', $projectRoot));

        $this->assertExitCode(0);
        $this->assertOutputContains('Glaze');
    }

    /**
     * Write a minimal glaze.neon file into the given project root.
     *
     * @param string $projectRoot Absolute project root path.
     */
    private function writeMinimalConfig(string $projectRoot): void
    {
        file_put_contents($projectRoot . '/glaze.neon', "site:\n  title: Test\n");
    }
}
