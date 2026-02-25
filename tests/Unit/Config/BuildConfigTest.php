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
        $this->assertSame('/tmp/glaze-project/tmp/cache/sugar', $this->normalizePath($config->templateCachePath()));
        $this->assertSame('/tmp/glaze-project/tmp/cache/glide', $this->normalizePath($config->glideCachePath()));
        $this->assertSame([], $config->imagePresets);
        $this->assertSame([], $config->imageOptions);
        $this->assertSame([
            'codeHighlighting' => [
                'enabled' => true,
                'theme' => 'nord',
                'withGutter' => false,
            ],
            'headerAnchors' => [
                'enabled' => false,
                'symbol' => '#',
                'position' => 'after',
                'cssClass' => 'header-anchor',
                'ariaLabel' => 'Anchor link',
                'levels' => [1, 2, 3, 4, 5, 6],
            ],
        ], $config->djot);
        $this->assertSame([], $config->contentTypes);
        $this->assertSame(['tags'], $config->taxonomies);
        $this->assertSame([
            'buildEnabled' => false,
            'devEnabled' => false,
            'assetBaseUrl' => '/assets/',
            'manifestPath' => '/tmp/glaze-project/public/assets/.vite/manifest.json',
            'devServerUrl' => 'http://127.0.0.1:5173',
            'injectClient' => true,
            'defaultEntry' => null,
        ], $this->normalizeTemplateVitePaths($config->templateVite));
        $this->assertFalse($config->includeDrafts);
    }

    /**
     * Ensure Sugar Vite extension settings can be normalized from build and devServer sections.
     */
    public function testTemplateViteSettingsCanBeConfiguredFromProjectFile(): void
    {
        $projectRoot = sys_get_temp_dir() . '/glaze-config-' . uniqid('', true);
        mkdir($projectRoot, 0755, true);
        file_put_contents(
            $projectRoot . '/glaze.neon',
            "build:\n  vite:\n    enabled: true\n    assetBaseUrl: /build\n    manifestPath: public/custom-manifest.json\n    defaultEntry: resources/js/app.ts\ndevServer:\n  vite:\n    enabled: true\n    host: 0.0.0.0\n    port: 5179\n    injectClient: false\n",
        );

        $config = BuildConfig::fromProjectRoot($projectRoot);

        $this->assertSame([
            'buildEnabled' => true,
            'devEnabled' => true,
            'assetBaseUrl' => '/build/',
            'manifestPath' => $this->normalizePath($projectRoot . '/public/custom-manifest.json'),
            'devServerUrl' => 'http://0.0.0.0:5179',
            'injectClient' => false,
            'defaultEntry' => 'resources/js/app.ts',
        ], $this->normalizeTemplateVitePaths($config->templateVite));
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
            "pageTemplate: landing\nimages:\n  driver: imagick\n  presets:\n    thumb:\n      w: 320\n      h: 180\n    invalid: true\nsite:\n  title: Example Site\n  description: Default site description\n  baseUrl: https://example.com\n  basePath: /docs\n  meta:\n    robots: \"index,follow\"\n",
        );

        $config = BuildConfig::fromProjectRoot($projectRoot);

        $this->assertInstanceOf(SiteConfig::class, $config->site);
        $this->assertSame('landing', $config->pageTemplate);
        $this->assertSame(['thumb' => ['w' => '320', 'h' => '180']], $config->imagePresets);
        $this->assertSame(['driver' => 'imagick'], $config->imageOptions);
        $this->assertSame('Example Site', $config->site->title);
        $this->assertSame('Default site description', $config->site->description);
        $this->assertSame('https://example.com', $config->site->baseUrl);
        $this->assertSame('/docs', $config->site->basePath);
        $this->assertSame(['robots' => 'index,follow'], $config->site->meta);
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

        file_put_contents(
            $projectRoot . '/glaze.neon',
            "taxonomies:\n  - ''\n  - 42\n",
        );
        $emptyNormalized = BuildConfig::fromProjectRoot($projectRoot);
        $this->assertSame(['tags'], $emptyNormalized->taxonomies);
    }

    /**
     * Ensure image presets remain optional and invalid values are ignored safely.
     */
    public function testImagePresetNormalizationIsOptionalAndSafe(): void
    {
        $projectRoot = sys_get_temp_dir() . '/glaze-config-' . uniqid('', true);
        mkdir($projectRoot, 0755, true);

        file_put_contents($projectRoot . '/glaze.neon', "images: true\n");
        $fallback = BuildConfig::fromProjectRoot($projectRoot);
        $this->assertSame([], $fallback->imagePresets);
        $this->assertSame([], $fallback->imageOptions);

        file_put_contents(
            $projectRoot . '/glaze.neon',
            "images:\n  driver: GD\n  presets:\n    '':\n      w: 100\n    hero:\n      w: 1200\n      h: 630\n      bad: [1,2,3]\n    invalid: true\n",
        );
        $normalized = BuildConfig::fromProjectRoot($projectRoot);
        $this->assertSame(['hero' => ['w' => '1200', 'h' => '630']], $normalized->imagePresets);
        $this->assertSame(['driver' => 'GD'], $normalized->imageOptions);

        file_put_contents(
            $projectRoot . '/glaze.neon',
            "images:\n  driver: ''\n",
        );
        $invalidDriver = BuildConfig::fromProjectRoot($projectRoot);
        $this->assertSame([], $invalidDriver->imageOptions);
    }

    /**
     * Ensure code highlighting settings are normalized safely from config.
     */
    public function testCodeHighlightingCanBeConfiguredFromProjectFile(): void
    {
        $projectRoot = sys_get_temp_dir() . '/glaze-config-' . uniqid('', true);
        mkdir($projectRoot, 0755, true);

        file_put_contents(
            $projectRoot . '/glaze.neon',
            "djot:\n  codeHighlighting:\n    enabled: false\n    theme: DARK-PLUS\n    withGutter: true\n",
        );

        $config = BuildConfig::fromProjectRoot($projectRoot);

        $this->assertSame([
            'enabled' => false,
            'theme' => 'dark-plus',
            'withGutter' => true,
        ], $config->djot['codeHighlighting']);
    }

    /**
     * Ensure invalid code highlighting values fall back to defaults.
     */
    public function testInvalidCodeHighlightingConfigurationFallsBackToDefaults(): void
    {
        $projectRoot = sys_get_temp_dir() . '/glaze-config-' . uniqid('', true);
        mkdir($projectRoot, 0755, true);

        file_put_contents(
            $projectRoot . '/glaze.neon',
            "djot:\n  codeHighlighting:\n    enabled: 'yes'\n    theme: ''\n    withGutter: 1\n",
        );

        $config = BuildConfig::fromProjectRoot($projectRoot);

        $this->assertSame([
            'enabled' => true,
            'theme' => 'nord',
            'withGutter' => false,
        ], $config->djot['codeHighlighting']);
    }

    /**
     * Ensure heading anchor settings are normalized from Djot configuration.
     */
    public function testHeaderAnchorsCanBeConfiguredFromProjectFile(): void
    {
        $projectRoot = sys_get_temp_dir() . '/glaze-config-' . uniqid('', true);
        mkdir($projectRoot, 0755, true);

        file_put_contents(
            $projectRoot . '/glaze.neon',
            "djot:\n  headerAnchors:\n    enabled: true\n    symbol: '¶'\n    position: before\n    cssClass: docs-anchor\n    ariaLabel: Copy section link\n    levels: [2, '3', 9]\n",
        );

        $config = BuildConfig::fromProjectRoot($projectRoot);

        $this->assertSame([
            'enabled' => true,
            'symbol' => '¶',
            'position' => 'before',
            'cssClass' => 'docs-anchor',
            'ariaLabel' => 'Copy section link',
            'levels' => [2, 3],
        ], $config->djot['headerAnchors']);
    }

    /**
     * Ensure legacy root codeHighlighting remains supported for compatibility.
     */
    public function testLegacyRootCodeHighlightingRemainsSupported(): void
    {
        $projectRoot = sys_get_temp_dir() . '/glaze-config-' . uniqid('', true);
        mkdir($projectRoot, 0755, true);

        file_put_contents(
            $projectRoot . '/glaze.neon',
            "codeHighlighting:\n  enabled: false\n  theme: github-dark\n  withGutter: true\n",
        );

        $config = BuildConfig::fromProjectRoot($projectRoot);

        $this->assertSame([
            'enabled' => false,
            'theme' => 'github-dark',
            'withGutter' => true,
        ], $config->djot['codeHighlighting']);
    }

    /**
     * Ensure content types are normalized from project configuration.
     */
    public function testContentTypesCanBeConfiguredFromProjectFile(): void
    {
        $projectRoot = sys_get_temp_dir() . '/glaze-config-' . uniqid('', true);
        mkdir($projectRoot, 0755, true);
        file_put_contents(
            $projectRoot . '/glaze.neon',
            "contentTypes:\n  Blog:\n    paths:\n      - blog\n    defaults:\n      template: article\n      draft: false\n",
        );

        $config = BuildConfig::fromProjectRoot($projectRoot);

        $this->assertSame([
            'blog' => [
                'paths' => [
                    [
                        'match' => 'blog',
                        'createPattern' => null,
                    ],
                ],
                'defaults' => [
                    'template' => 'article',
                    'draft' => false,
                ],
            ],
        ], $config->contentTypes);
    }

    /**
     * Ensure invalid content type configuration values throw validation errors.
     */
    public function testInvalidContentTypesConfigurationThrowsRuntimeException(): void
    {
        $projectRoot = sys_get_temp_dir() . '/glaze-config-' . uniqid('', true);
        mkdir($projectRoot, 0755, true);

        file_put_contents($projectRoot . '/glaze.neon', "contentTypes: true\n");
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('contentTypes');
        BuildConfig::fromProjectRoot($projectRoot);
    }

    /**
     * Ensure invalid content type defaults map throws a validation error.
     */
    public function testInvalidContentTypeDefaultsConfigurationThrowsRuntimeException(): void
    {
        $projectRoot = sys_get_temp_dir() . '/glaze-config-' . uniqid('', true);
        mkdir($projectRoot, 0755, true);

        file_put_contents(
            $projectRoot . '/glaze.neon',
            "contentTypes:\n  blog:\n    defaults: true\n",
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('defaults');
        BuildConfig::fromProjectRoot($projectRoot);
    }

    /**
     * Ensure duplicate content type names (case-insensitive) are rejected.
     */
    public function testDuplicateContentTypeNamesThrowRuntimeException(): void
    {
        $projectRoot = sys_get_temp_dir() . '/glaze-config-' . uniqid('', true);
        mkdir($projectRoot, 0755, true);

        file_put_contents(
            $projectRoot . '/glaze.neon',
            "contentTypes:\n  Blog:\n    paths:\n      - blog\n  blog:\n    paths:\n      - posts\n",
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('duplicate content type');
        BuildConfig::fromProjectRoot($projectRoot);
    }

    /**
     * Ensure non-map content type definitions throw validation errors.
     */
    public function testInvalidContentTypeDefinitionThrowsRuntimeException(): void
    {
        $projectRoot = sys_get_temp_dir() . '/glaze-config-' . uniqid('', true);
        mkdir($projectRoot, 0755, true);

        file_put_contents(
            $projectRoot . '/glaze.neon',
            "contentTypes:\n  blog: true\n",
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must be a key/value mapping');
        BuildConfig::fromProjectRoot($projectRoot);
    }

    /**
     * Ensure content type names must be non-empty strings.
     */
    public function testContentTypeNameValidationThrowsRuntimeException(): void
    {
        $projectRoot = sys_get_temp_dir() . '/glaze-config-' . uniqid('', true);
        mkdir($projectRoot, 0755, true);

        file_put_contents(
            $projectRoot . '/glaze.neon',
            "contentTypes:\n  0:\n    paths:\n      - blog\n",
        );

        try {
            BuildConfig::fromProjectRoot($projectRoot);
            $this->fail('Expected non-string content type name to throw.');
        } catch (RuntimeException $runtimeException) {
            $this->assertStringContainsString('names must be strings', $runtimeException->getMessage());
        }

        file_put_contents(
            $projectRoot . '/glaze.neon',
            "contentTypes:\n  '':\n    paths:\n      - blog\n",
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('name cannot be empty');
        BuildConfig::fromProjectRoot($projectRoot);
    }

    /**
     * Ensure content type defaults drop invalid keys during normalization.
     */
    public function testContentTypeDefaultsNormalizationDropsInvalidKeys(): void
    {
        $projectRoot = sys_get_temp_dir() . '/glaze-config-' . uniqid('', true);
        mkdir($projectRoot, 0755, true);

        file_put_contents(
            $projectRoot . '/glaze.neon',
            "contentTypes:\n  blog:\n    defaults:\n      0: ignored\n      '': ignored\n      Template: article\n",
        );

        $config = BuildConfig::fromProjectRoot($projectRoot);

        $this->assertSame([
            'blog' => [
                'paths' => [],
                'defaults' => [
                    'template' => 'article',
                ],
            ],
        ], $config->contentTypes);
    }

    /**
     * Ensure image preset normalization skips invalid names and empty normalized maps.
     */
    public function testImagePresetNormalizationSkipsInvalidPresetEntries(): void
    {
        $projectRoot = sys_get_temp_dir() . '/glaze-config-' . uniqid('', true);
        mkdir($projectRoot, 0755, true);

        file_put_contents(
            $projectRoot . '/glaze.neon',
            "images:\n  presets:\n    0:\n      w: 100\n    hero:\n      w: 1200\n    noScalars:\n      nested: [1,2,3]\n",
        );

        $config = BuildConfig::fromProjectRoot($projectRoot);

        $this->assertSame([
            'hero' => [
                'w' => '1200',
            ],
        ], $config->imagePresets);
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

    /**
     * Normalize template Vite manifest path for assertions.
     *
     * @param array<string, mixed> $configuration Template Vite configuration.
     * @return array<string, mixed>
     */
    protected function normalizeTemplateVitePaths(array $configuration): array
    {
        if (is_string($configuration['manifestPath'] ?? null)) {
            $configuration['manifestPath'] = $this->normalizePath($configuration['manifestPath']);
        }

        return $configuration;
    }
}
