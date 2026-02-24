<?php
declare(strict_types=1);

namespace Glaze\Tests\Integration\Command;

use Glaze\Tests\Helper\IntegrationCommandTestCase;

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
}
