<?php
declare(strict_types=1);

namespace Glaze\Tests\Integration\Command;

use Glaze\Tests\Helper\IntegrationCommandTestCase;
use RuntimeException;

/**
 * Integration tests for the build command.
 */
final class BuildCommandTest extends IntegrationCommandTestCase
{
    /**
     * Ensure build command generates output from fixture content.
     */
    public function testBuildCommandGeneratesStaticOutput(): void
    {
        $projectRoot = $this->copyFixtureToTemp('projects/basic');

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
        $projectRoot = $this->copyFixtureToTemp('projects/with-draft');

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
     * Ensure template context helpers render collection, taxonomy, and pager values.
     */
    public function testBuildCommandRendersTemplateContextFunctions(): void
    {
        $projectRoot = $this->copyFixtureToTemp('projects/template-context');

        $this->exec(sprintf('build --root "%s"', $projectRoot));

        $this->assertExitCode(0);
        $this->assertOutputContains('Build complete: 3 page(s).');

        $output = file_get_contents($projectRoot . '/public/blog/post-a/index.html');
        $this->assertIsString($output);
        $this->assertStringContainsString('<p class="regular">3</p>', $output);
        $this->assertStringContainsString('<p class="section">2</p>', $output);
        $this->assertStringContainsString('<p class="tag-php">2</p>', $output);
        $this->assertStringContainsString('<p class="pager-url">/blog/page/2/</p>', $output);
        $this->assertStringContainsString('<p class="prev">none</p>', $output);
        $this->assertStringContainsString('<p class="next">blog/post-b</p>', $output);
    }

    /**
     * Ensure custom-taxonomies fixture uses custom glaze configuration for root taxonomies.
     */
    public function testBuildCommandCustomTaxonomiesFixtureWithCustomTaxonomyConfig(): void
    {
        $projectRoot = $this->copyFixtureToTemp('projects/custom-taxonomies');

        $this->exec(sprintf('build --root "%s"', $projectRoot));

        $this->assertExitCode(0);
        $this->assertOutputContains('Build complete: 2 page(s).');
        $this->assertFileExists($projectRoot . '/public/blog/post-a/index.html');

        $output = file_get_contents($projectRoot . '/public/blog/post-a/index.html');
        $this->assertIsString($output);
        $this->assertStringContainsString('<p class="tags-php">2</p>', $output);
        $this->assertStringContainsString('<p class="categories-docs">2</p>', $output);
    }

    /**
     * Ensure invalid project configuration is reported as build command error.
     */
    public function testBuildCommandFailsForInvalidProjectConfig(): void
    {
        $projectRoot = $this->createTempDirectory();
        file_put_contents($projectRoot . '/glaze.neon', "taxonomies:\n  - tags: [\n");

        $this->exec(sprintf('build --root "%s"', $projectRoot));

        $this->assertExitCode(1);
        $this->assertErrorContains('Invalid project configuration');
    }

    /**
     * Ensure frontmatter slug override and content asset handling work end-to-end.
     */
    public function testBuildCommandHandlesFrontmatterSlugAndAssets(): void
    {
        $projectRoot = $this->copyFixtureToTemp('projects/frontmatter-slug-assets');

        $this->exec(sprintf('build --root "%s"', $projectRoot));

        $this->assertExitCode(0);
        $this->assertOutputContains('Build complete: 1 page(s).');
        $this->assertFileExists($projectRoot . '/public/blog/asset-test-page/index.html');
        $this->assertFileExists($projectRoot . '/public/blog/test2.jpg');

        $output = file_get_contents($projectRoot . '/public/blog/asset-test-page/index.html');
        $this->assertIsString($output);
        $this->assertStringContainsString('<img alt="test" src="/blog/test2.jpg">', $output);
    }

    /**
     * Ensure site config defaults and page-level meta overrides are rendered.
     */
    public function testBuildCommandRendersSiteConfigDefaultsAndPageOverrides(): void
    {
        $projectRoot = $this->copyFixtureToTemp('projects/site-config');

        $this->exec(sprintf('build --root "%s"', $projectRoot));

        $this->assertExitCode(0);
        $this->assertOutputContains('Build complete: 2 page(s).');

        $indexOutput = file_get_contents($projectRoot . '/public/index.html');
        $this->assertIsString($indexOutput);
        $this->assertStringContainsString('<p class="site-title">Example Site</p>', $indexOutput);
        $this->assertStringContainsString('<p class="meta-description">Default site description</p>', $indexOutput);
        $this->assertStringContainsString('<p class="meta-robots">index,follow</p>', $indexOutput);

        $aboutOutput = file_get_contents($projectRoot . '/public/about/index.html');
        $this->assertIsString($aboutOutput);
        $this->assertStringContainsString('<p class="site-title">Example Site</p>', $aboutOutput);
        $this->assertStringContainsString('<p class="meta-description">About page description</p>', $aboutOutput);
        $this->assertStringContainsString('<p class="meta-robots">noindex</p>', $aboutOutput);
    }

    /**
     * Ensure build rewrites Glide image query URLs to static transformed assets.
     */
    public function testBuildCommandBuildsStaticGlideImageAssets(): void
    {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
            $this->markTestSkipped('GD image functions are required for Glide build tests.');
        }

        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/content/images', 0755, true);
        mkdir($projectRoot . '/templates', 0755, true);

        $this->createJpegImage($projectRoot . '/content/images/hero.jpg');
        file_put_contents(
            $projectRoot . '/content/index.dj',
            "# Home\n\n![Hero](images/hero.jpg?h=50&w=100&fit=crop)\n",
        );
        file_put_contents(
            $projectRoot . '/templates/page.sugar.php',
            '<?= $content |> raw() ?>',
        );

        $this->exec(sprintf('build --root "%s"', $projectRoot));

        $this->assertExitCode(0);
        $output = file_get_contents($projectRoot . '/public/index.html');
        $this->assertIsString($output);
        $this->assertMatchesRegularExpression('/src="\/_glide\/[a-f0-9]+\.jpg"/', $output);

        preg_match('/src="(\/_glide\/[a-f0-9]+\.jpg)"/', $output, $matches);
        $this->assertArrayHasKey(1, $matches);
        $transformedRelativePath = $matches[1];
        $this->assertFileExists($projectRoot . '/public' . $transformedRelativePath);
    }

    /**
     * Ensure build command copies static directory assets into public output.
     */
    public function testBuildCommandCopiesStaticAssets(): void
    {
        $projectRoot = $this->copyFixtureToTemp('projects/basic');
        mkdir($projectRoot . '/static/fonts', 0755, true);

        file_put_contents($projectRoot . '/static/robots.txt', "User-agent: *\nAllow: /\n");
        file_put_contents($projectRoot . '/static/fonts/site.woff2', 'woff2-binary');

        $this->exec(sprintf('build --root "%s"', $projectRoot));

        $this->assertExitCode(0);
        $this->assertFileExists($projectRoot . '/public/robots.txt');
        $this->assertFileExists($projectRoot . '/public/fonts/site.woff2');
        $this->assertSame('woff2-binary', file_get_contents($projectRoot . '/public/fonts/site.woff2'));
    }

    /**
     * Build a small JPEG image file for Glide integration tests.
     *
     * @param string $path Destination file path.
     */
    protected function createJpegImage(string $path): void
    {
        $image = imagecreatetruecolor(4, 4);
        if ($image === false) {
            throw new RuntimeException('Unable to create JPEG test image.');
        }

        $color = imagecolorallocate($image, 10, 20, 30);
        if ($color === false) {
            throw new RuntimeException('Unable to allocate JPEG test image color.');
        }

        imagefilledrectangle($image, 0, 0, 3, 3, $color);
        imagejpeg($image, $path, 90);
    }

    /**
     * Ensure frontmatter template override selects the configured custom template.
     */
    public function testBuildCommandSupportsFrontmatterTemplateOverride(): void
    {
        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/content', 0755, true);
        mkdir($projectRoot . '/templates', 0755, true);

        file_put_contents(
            $projectRoot . '/content/index.dj',
            "---\ntitle: Home\ntemplate: landing\n---\n# Hello\n",
        );
        file_put_contents(
            $projectRoot . '/templates/page.sugar.php',
            '<p class="template">default</p><?= $content |> raw() ?>',
        );
        file_put_contents(
            $projectRoot . '/templates/landing.sugar.php',
            '<p class="template">landing</p><?= $content |> raw() ?>',
        );

        $this->exec(sprintf('build --root "%s"', $projectRoot));

        $this->assertExitCode(0);
        $output = file_get_contents($projectRoot . '/public/index.html');
        $this->assertIsString($output);
        $this->assertStringContainsString('<p class="template">landing</p>', $output);
        $this->assertStringNotContainsString('<p class="template">default</p>', $output);
    }
}
