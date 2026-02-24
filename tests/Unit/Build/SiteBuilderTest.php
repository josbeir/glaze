<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Build;

use Glaze\Build\SiteBuilder;
use Glaze\Config\BuildConfig;
use PHPUnit\Framework\TestCase;

/**
 * Tests for site build orchestration.
 */
final class SiteBuilderTest extends TestCase
{
    /**
     * Ensure Djot content is rendered and written to expected output files.
     */
    public function testBuildWritesRenderedOutputFiles(): void
    {
        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/content', 0755, true);
        mkdir($projectRoot . '/content/docs', 0755, true);
        mkdir($projectRoot . '/content/docs/images', 0755, true);
        mkdir($projectRoot . '/templates', 0755, true);

        file_put_contents($projectRoot . '/content/index.dj', "# Welcome\n");
        file_put_contents($projectRoot . '/content/docs/getting-started.dj', "# Start\n");
        file_put_contents($projectRoot . '/content/docs/images/cover.jpg', 'binary-image');
        file_put_contents($projectRoot . '/templates/page.sugar.php', '<h1><?= htmlspecialchars((string)$title, ENT_QUOTES, "UTF-8") ?></h1><?= $content |> raw() ?>');

        $builder = new SiteBuilder();
        $config = BuildConfig::fromProjectRoot($projectRoot);
        $writtenFiles = $builder->build($config);

        $this->assertCount(2, $writtenFiles);
        $this->assertFileExists($projectRoot . '/public/index.html');
        $this->assertFileExists($projectRoot . '/public/docs/getting-started/index.html');
        $this->assertFileExists($projectRoot . '/public/docs/images/cover.jpg');
        $this->assertFileDoesNotExist($projectRoot . '/public/docs/getting-started.dj');

        $homeOutput = file_get_contents($projectRoot . '/public/index.html');
        $docsOutput = file_get_contents($projectRoot . '/public/docs/getting-started/index.html');
        $this->assertIsString($homeOutput);
        $this->assertIsString($docsOutput);
        $this->assertStringContainsString('<h1>Home</h1>', $homeOutput);
        $this->assertStringContainsString('<h1>Getting started</h1>', $docsOutput);
        $this->assertStringContainsString('<h1>Welcome</h1>', $homeOutput);
        $this->assertStringContainsString('<h1>Start</h1>', $docsOutput);
    }

    /**
     * Ensure build copies nested content assets preserving relative paths.
     */
    public function testBuildCopiesContentAssetsPreservingStructure(): void
    {
        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/content/blog/my-post', 0755, true);
        mkdir($projectRoot . '/templates', 0755, true);

        file_put_contents($projectRoot . '/content/blog/my-post/index.dj', "# Post\n");
        file_put_contents($projectRoot . '/content/blog/my-post/photo.png', 'png-bytes');
        file_put_contents($projectRoot . '/templates/page.sugar.php', '<h1><?= htmlspecialchars((string)$title, ENT_QUOTES, "UTF-8") ?></h1><?= $content |> raw() ?>');

        $builder = new SiteBuilder();
        $config = BuildConfig::fromProjectRoot($projectRoot);
        $builder->build($config);

        $this->assertFileExists($projectRoot . '/public/blog/my-post/photo.png');
        $this->assertSame('png-bytes', file_get_contents($projectRoot . '/public/blog/my-post/photo.png'));
    }

    /**
     * Ensure request rendering reuses the build rendering pipeline.
     */
    public function testRenderRequestReturnsHtmlForKnownPath(): void
    {
        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/content', 0755, true);
        mkdir($projectRoot . '/templates', 0755, true);

        file_put_contents($projectRoot . '/content/index.dj', "# Welcome\n");
        file_put_contents($projectRoot . '/templates/page.sugar.php', '<h1><?= htmlspecialchars((string)$title, ENT_QUOTES, "UTF-8") ?></h1><?= $content |> raw() ?>');

        $builder = new SiteBuilder();
        $config = BuildConfig::fromProjectRoot($projectRoot);
        $html = $builder->renderRequest($config, '/');

        $this->assertIsString($html);
        $this->assertStringContainsString('<h1>Home</h1>', $html);
        $this->assertStringContainsString('<h1>Welcome</h1>', $html);
    }

    /**
     * Ensure unknown request paths return null in live mode rendering.
     */
    public function testRenderRequestReturnsNullForUnknownPath(): void
    {
        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/content', 0755, true);
        mkdir($projectRoot . '/templates', 0755, true);

        file_put_contents($projectRoot . '/content/index.dj', "# Welcome\n");
        file_put_contents($projectRoot . '/templates/page.sugar.php', '<h1><?= htmlspecialchars((string)$title, ENT_QUOTES, "UTF-8") ?></h1><?= $content |> raw() ?>');

        $builder = new SiteBuilder();
        $config = BuildConfig::fromProjectRoot($projectRoot);
        $html = $builder->renderRequest($config, '/missing-page/');

        $this->assertNull($html);
    }

    /**
     * Ensure clean-output mode removes stale files before regenerating pages.
     */
    public function testBuildWithCleanOutputRemovesStaleFiles(): void
    {
        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/content', 0755, true);
        mkdir($projectRoot . '/templates', 0755, true);
        mkdir($projectRoot . '/public/old', 0755, true);

        file_put_contents($projectRoot . '/content/index.dj', "# Welcome\n");
        file_put_contents($projectRoot . '/templates/page.sugar.php', '<h1><?= htmlspecialchars((string)$title, ENT_QUOTES, "UTF-8") ?></h1><?= $content |> raw() ?>');
        file_put_contents($projectRoot . '/public/old/stale.html', 'stale');

        $builder = new SiteBuilder();
        $config = BuildConfig::fromProjectRoot($projectRoot);
        $builder->build($config, true);

        $this->assertFileDoesNotExist($projectRoot . '/public/old/stale.html');
        $this->assertFileExists($projectRoot . '/public/index.html');
    }

    /**
     * Ensure draft pages are skipped by default during static builds.
     */
    public function testBuildSkipsDraftPagesByDefault(): void
    {
        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/content', 0755, true);
        mkdir($projectRoot . '/templates', 0755, true);

        file_put_contents($projectRoot . '/content/index.dj', "# Welcome\n");
        file_put_contents($projectRoot . '/content/draft.dj', "+++\ndraft: true\n+++\n# Draft\n");
        file_put_contents($projectRoot . '/templates/page.sugar.php', '<h1><?= htmlspecialchars((string)$title, ENT_QUOTES, "UTF-8") ?></h1><?= $content |> raw() ?>');

        $builder = new SiteBuilder();
        $config = BuildConfig::fromProjectRoot($projectRoot);
        $writtenFiles = $builder->build($config);

        $this->assertCount(1, $writtenFiles);
        $this->assertFileExists($projectRoot . '/public/index.html');
        $this->assertFileDoesNotExist($projectRoot . '/public/draft/index.html');
    }

    /**
     * Ensure draft pages can be included explicitly.
     */
    public function testBuildIncludesDraftPagesWhenConfigured(): void
    {
        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/content', 0755, true);
        mkdir($projectRoot . '/templates', 0755, true);

        file_put_contents($projectRoot . '/content/index.dj', "# Welcome\n");
        file_put_contents($projectRoot . '/content/draft.dj', "+++\ndraft: true\n+++\n# Draft\n");
        file_put_contents($projectRoot . '/templates/page.sugar.php', '<h1><?= htmlspecialchars((string)$title, ENT_QUOTES, "UTF-8") ?></h1><?= $content |> raw() ?>');

        $builder = new SiteBuilder();
        $config = BuildConfig::fromProjectRoot($projectRoot, true);
        $writtenFiles = $builder->build($config);

        $this->assertCount(2, $writtenFiles);
        $this->assertFileExists($projectRoot . '/public/draft/index.html');
    }

    /**
     * Ensure metadata is exposed to templates through the shared render context.
     */
    public function testRenderRequestExposesMetaDataToTemplate(): void
    {
        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/content', 0755, true);
        mkdir($projectRoot . '/templates', 0755, true);

        file_put_contents($projectRoot . '/content/index.dj', "+++\ndescription: Hello world\n+++\n# Welcome\n");
        file_put_contents($projectRoot . '/templates/page.sugar.php', '<p><?= $meta["description"] ?? "none" ?></p><?= $content |> raw() ?>');

        $builder = new SiteBuilder();
        $config = BuildConfig::fromProjectRoot($projectRoot);
        $html = $builder->renderRequest($config, '/');

        $this->assertIsString($html);
        $this->assertStringContainsString('<p>Hello world</p>', $html);
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
