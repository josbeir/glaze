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
        $phikiCache = $projectRoot . '/tmp/cache/phiki-html';
        mkdir($templateCache, 0755, true);
        mkdir($imageCache, 0755, true);
        mkdir($phikiCache, 0755, true);

        file_put_contents($templateCache . '/template.php', '<?php echo 1;');
        file_put_contents($imageCache . '/img.jpg', 'binary');
        file_put_contents($phikiCache . '/block.cache.phpser', 'serialized');

        $this->exec(sprintf('cc --root "%s"', $projectRoot));

        $this->assertExitCode(0);
        $this->assertOutputContains('[Cache/Templates]');
        $this->assertOutputContains('[Cache/Images]');
        $this->assertOutputContains('[Cache/Phiki]');
        $this->assertDirectoryExists($templateCache);
        $this->assertDirectoryExists($imageCache);
        $this->assertDirectoryExists($phikiCache);
        $this->assertFileDoesNotExist($templateCache . '/template.php');
        $this->assertFileDoesNotExist($imageCache . '/img.jpg');
        $this->assertFileDoesNotExist($phikiCache . '/block.cache.phpser');
    }

    /**
     * Ensure the build manifest file is deleted when present during a default clear.
     */
    public function testClearBothDeletesExistingBuildManifestFile(): void
    {
        $projectRoot = $this->createTempDirectory();
        $this->writeMinimalConfig($projectRoot);

        $manifestPath = $projectRoot . '/tmp/cache/build-manifest.json';
        mkdir(dirname($manifestPath), 0755, true);
        file_put_contents($manifestPath, '{"hash":"abc"}');

        $this->exec(sprintf('cc --root "%s"', $projectRoot));

        $this->assertExitCode(0);
        $this->assertOutputContains('[Cache/Build]');
        $this->assertFileDoesNotExist($manifestPath);
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
        $phikiCache = $projectRoot . '/tmp/cache/phiki-html';
        mkdir($templateCache, 0755, true);
        mkdir($imageCache, 0755, true);
        mkdir($phikiCache, 0755, true);

        file_put_contents($templateCache . '/template.php', '<?php echo 1;');
        file_put_contents($imageCache . '/img.jpg', 'binary');
        file_put_contents($phikiCache . '/block.cache.phpser', 'serialized');

        $this->exec(sprintf('cc --templates --root "%s"', $projectRoot));

        $this->assertExitCode(0);
        $this->assertOutputContains('[Cache/Templates]');
        $this->assertOutputContains('[Cache/Phiki]');
        $this->assertOutputNotContains('[Cache/Images]');
        $this->assertFileDoesNotExist($templateCache . '/template.php');
        $this->assertFileDoesNotExist($phikiCache . '/block.cache.phpser');
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
        $phikiCache = $projectRoot . '/tmp/cache/phiki-html';
        mkdir($templateCache, 0755, true);
        mkdir($imageCache, 0755, true);
        mkdir($phikiCache, 0755, true);

        file_put_contents($templateCache . '/template.php', '<?php echo 1;');
        file_put_contents($imageCache . '/img.jpg', 'binary');
        file_put_contents($phikiCache . '/block.cache.phpser', 'serialized');

        $this->exec(sprintf('cc --images --root "%s"', $projectRoot));

        $this->assertExitCode(0);
        $this->assertOutputContains('[Cache/Images]');
        $this->assertOutputNotContains('[Cache/Templates]');
        $this->assertOutputNotContains('[Cache/Phiki]');
        $this->assertFileDoesNotExist($imageCache . '/img.jpg');
        $this->assertFileExists($templateCache . '/template.php');
        $this->assertFileExists($phikiCache . '/block.cache.phpser');
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
     * Ensure malformed project configuration is reported as a command error.
     */
    public function testMalformedProjectConfigReturnsErrorCode(): void
    {
        $projectRoot = $this->createTempDirectory();
        file_put_contents($projectRoot . '/glaze.neon', "site: [\n");

        $this->exec(sprintf('cc --root "%s"', $projectRoot));

        $this->assertExitCode(1);
        $this->assertErrorContains('Cache clear failed:');
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
