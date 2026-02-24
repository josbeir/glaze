<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Config;

use Glaze\Config\BuildConfig;
use Glaze\Config\SiteConfig;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for build configuration path resolution.
 */
final class BuildConfigTest extends TestCase
{
    /**
     * Ensure default directories resolve from project root.
     */
    public function testDefaultPathsResolveFromRoot(): void
    {
        $config = BuildConfig::fromProjectRoot('/tmp/glaze-project');

        $this->assertSame('/tmp/glaze-project/content', $this->normalizePath($config->contentPath()));
        $this->assertSame('/tmp/glaze-project/templates', $this->normalizePath($config->templatePath()));
        $this->assertSame('/tmp/glaze-project/public', $this->normalizePath($config->outputPath()));
        $this->assertSame('/tmp/glaze-project/tmp/cache', $this->normalizePath($config->cachePath()));
        $this->assertSame(['tags'], $config->taxonomies);
        $this->assertFalse($config->includeDrafts);
    }

    /**
     * Ensure draft inclusion can be configured at config creation time.
     */
    public function testIncludeDraftsCanBeEnabled(): void
    {
        $config = BuildConfig::fromProjectRoot('/tmp/glaze-project', true);

        $this->assertTrue($config->includeDrafts);
    }

    /**
     * Ensure taxonomies are loaded from optional project configuration.
     */
    public function testTaxonomiesCanBeConfiguredFromProjectFile(): void
    {
        $projectRoot = sys_get_temp_dir() . '/glaze-config-' . uniqid('', true);
        mkdir($projectRoot, 0755, true);
        file_put_contents(
            $projectRoot . '/glaze.neon',
            "taxonomies:\n  - tags\n  - categories\n",
        );

        $config = BuildConfig::fromProjectRoot($projectRoot);

        $this->assertSame(['tags', 'categories'], $config->taxonomies);
    }

    /**
     * Ensure site block can be loaded from project configuration.
     */
    public function testSiteConfigCanBeLoadedFromProjectFile(): void
    {
        $projectRoot = sys_get_temp_dir() . '/glaze-config-' . uniqid('', true);
        mkdir($projectRoot, 0755, true);
        file_put_contents(
            $projectRoot . '/glaze.neon',
            "pageTemplate: landing\nsite:\n  title: Example Site\n  description: Default site description\n  baseUrl: https://example.com\n  basePath: /docs\n  metaDefaults:\n    robots: \"index,follow\"\n",
        );

        $config = BuildConfig::fromProjectRoot($projectRoot);

        $this->assertInstanceOf(SiteConfig::class, $config->site);
        $this->assertSame('landing', $config->pageTemplate);
        $this->assertSame('Example Site', $config->site->title);
        $this->assertSame('Default site description', $config->site->description);
        $this->assertSame('https://example.com', $config->site->baseUrl);
        $this->assertSame('/docs', $config->site->basePath);
        $this->assertSame(['robots' => 'index,follow'], $config->site->metaDefaults);
    }

    /**
     * Ensure invalid taxonomy configuration values are normalized safely.
     */
    public function testTaxonomyNormalizationFallsBackToTags(): void
    {
        $projectRoot = sys_get_temp_dir() . '/glaze-config-' . uniqid('', true);
        mkdir($projectRoot, 0755, true);

        file_put_contents($projectRoot . '/glaze.neon', "taxonomies: true\n");
        $fallback = BuildConfig::fromProjectRoot($projectRoot);
        $this->assertSame(['tags'], $fallback->taxonomies);

        file_put_contents(
            $projectRoot . '/glaze.neon',
            "taxonomies:\n  - Tags\n  - categories\n  - ''\n  - 42\n  - tags\n",
        );
        $normalized = BuildConfig::fromProjectRoot($projectRoot);
        $this->assertSame(['tags', 'categories'], $normalized->taxonomies);
    }

    /**
     * Ensure invalid project configuration files are reported.
     */
    public function testInvalidProjectConfigurationThrowsRuntimeException(): void
    {
        $projectRoot = sys_get_temp_dir() . '/glaze-config-' . uniqid('', true);
        mkdir($projectRoot, 0755, true);
        file_put_contents($projectRoot . '/glaze.neon', "taxonomies:\n  - tags: [\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid project configuration');

        BuildConfig::fromProjectRoot($projectRoot);
    }

    /**
     * Ensure scalar configuration root is ignored and defaults are used.
     */
    public function testScalarProjectConfigurationKeepsDefaults(): void
    {
        $projectRoot = sys_get_temp_dir() . '/glaze-config-' . uniqid('', true);
        mkdir($projectRoot, 0755, true);
        file_put_contents($projectRoot . '/glaze.neon', "true\n");

        $config = BuildConfig::fromProjectRoot($projectRoot);

        $this->assertSame(['tags'], $config->taxonomies);
    }

    /**
     * Normalize platform-specific separators for deterministic assertions.
     *
     * @param string $path Path value to normalize.
     */
    protected function normalizePath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }
}
