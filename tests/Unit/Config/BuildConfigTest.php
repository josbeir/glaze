<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Config;

use Glaze\Config\BuildConfig;
use Glaze\Config\DjotOptions;
use Glaze\Config\SiteConfig;
use Glaze\Config\TemplateViteOptions;
use Glaze\Utility\Normalization;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for build configuration path resolution and option extraction.
 */
final class BuildConfigTest extends TestCase
{
    /**
     * Ensure default directories resolve from project root and typed option objects carry defaults.
     */
    public function testDefaultPathsResolveFromRoot(): void
    {
        $config = BuildConfig::fromProjectRoot('/tmp/glaze-project');

        $this->assertSame('/tmp/glaze-project/content', $config->contentPath());
        $this->assertSame('/tmp/glaze-project/templates', $config->templatePath());
        $this->assertSame('/tmp/glaze-project/public', $config->outputPath());
        $this->assertSame('/tmp/glaze-project/tmp/cache', $config->cachePath());
        $this->assertSame('/tmp/glaze-project/tmp/cache/sugar', $config->templateCachePath());
        $this->assertSame('/tmp/glaze-project/tmp/cache/glide', $config->glideCachePath());
        $this->assertSame([], $config->imagePresets);
        $this->assertSame([], $config->imageOptions);
        $this->assertSame([], $config->contentTypes);
        $this->assertSame(['tags'], $config->taxonomies);
        $this->assertSame('extensions', $config->extensionsDir);
        $this->assertFalse($config->includeDrafts);

        // DjotOptions defaults
        $this->assertInstanceOf(DjotOptions::class, $config->djotOptions);
        $this->assertTrue($config->djotOptions->codeHighlightingEnabled);
        $this->assertSame('nord', $config->djotOptions->codeHighlightingTheme);
        $this->assertSame([], $config->djotOptions->codeHighlightingThemes);
        $this->assertFalse($config->djotOptions->headerAnchorsEnabled);

        // TemplateViteOptions defaults
        $this->assertInstanceOf(TemplateViteOptions::class, $config->templateViteOptions);
        $this->assertFalse($config->templateViteOptions->buildEnabled);
        $this->assertFalse($config->templateViteOptions->devEnabled);
        $this->assertSame('/', $config->templateViteOptions->assetBaseUrl);
        $this->assertSame(
            Normalization::path('/tmp/glaze-project/public/.vite/manifest.json'),
            Normalization::path($config->templateViteOptions->manifestPath),
        );
        $this->assertSame('http://127.0.0.1:5173', $config->templateViteOptions->devServerUrl);
        $this->assertTrue($config->templateViteOptions->injectClient);
        $this->assertNull($config->templateViteOptions->defaultEntry);
    }

    /**
     * Ensure djotOptions is a typed DjotOptions value object with correct defaults.
     */
    public function testDjotOptionsIsTypedValueObject(): void
    {
        $config = BuildConfig::fromProjectRoot('/tmp/glaze-project');

        $this->assertInstanceOf(DjotOptions::class, $config->djotOptions);
        $this->assertSame('permalink-wrapper', $config->djotOptions->headerAnchorsCssClass);
        $this->assertTrue($config->djotOptions->codeHighlightingEnabled);
    }

    /**
     * Ensure extensions directory can be configured from project file.
     */
    public function testExtensionsDirCanBeConfiguredFromProjectFile(): void
    {
        $projectRoot = sys_get_temp_dir() . '/glaze-config-' . uniqid('', true);
        mkdir($projectRoot, 0755, true);
        file_put_contents($projectRoot . '/glaze.neon', "extensionsDir: src/Extensions\n");

        $config = BuildConfig::fromProjectRoot($projectRoot);

        $this->assertSame('src/Extensions', $config->extensionsDir);
    }

    /**
     * Ensure extensions directory defaults to 'extensions' when config value is empty or absent.
     */
    public function testExtensionsDirFallsBackToDefault(): void
    {
        $projectRoot = sys_get_temp_dir() . '/glaze-config-' . uniqid('', true);
        mkdir($projectRoot, 0755, true);
        file_put_contents($projectRoot . '/glaze.neon', "extensionsDir: \n");

        $config = BuildConfig::fromProjectRoot($projectRoot);

        $this->assertSame('extensions', $config->extensionsDir);
    }

    /**
     * Ensure Sugar Vite extension settings resolve correctly from build and devServer sections.
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
        $vite = $config->templateViteOptions;

        $this->assertTrue($vite->buildEnabled);
        $this->assertTrue($vite->devEnabled);
        $this->assertSame('/build/', $vite->assetBaseUrl);
        $this->assertSame(
            Normalization::path($projectRoot . '/public/custom-manifest.json'),
            Normalization::path($vite->manifestPath),
        );
        $this->assertSame('http://0.0.0.0:5179', $vite->devServerUrl);
        $this->assertFalse($vite->injectClient);
        $this->assertSame('resources/js/app.ts', $vite->defaultEntry);
    }

    /**
     * Ensure templateViteOptions is a typed TemplateViteOptions value object with correct values.
     */
    public function testTemplateViteOptionsIsTypedValueObject(): void
    {
        $projectRoot = sys_get_temp_dir() . '/glaze-config-' . uniqid('', true);
        mkdir($projectRoot, 0755, true);
        file_put_contents(
            $projectRoot . '/glaze.neon',
            "build:\n  vite:\n    enabled: true\n    assetBaseUrl: /build\n    manifestPath: public/custom-manifest.json\ndevServer:\n  vite:\n    enabled: true\n    host: 0.0.0.0\n    port: 5179\n    injectClient: false\n    defaultEntry: resources/js/app.ts\n",
        );

        $config = BuildConfig::fromProjectRoot($projectRoot);
        $vite = $config->templateViteOptions;

        $this->assertInstanceOf(TemplateViteOptions::class, $vite);
        $this->assertTrue($vite->buildEnabled);
        $this->assertTrue($vite->devEnabled);
        $this->assertSame('/build/', $vite->assetBaseUrl);
        $this->assertSame(
            Normalization::path($projectRoot . '/public/custom-manifest.json'),
            Normalization::path($vite->manifestPath),
        );
        $this->assertSame('http://0.0.0.0:5179', $vite->devServerUrl);
        $this->assertFalse($vite->injectClient);
        $this->assertSame('resources/js/app.ts', $vite->defaultEntry);
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
     * Ensure code highlighting settings are resolved in djotOptions.
     */
    public function testCodeHighlightingCanBeConfiguredFromProjectFile(): void
    {
        $projectRoot = sys_get_temp_dir() . '/glaze-config-' . uniqid('', true);
        mkdir($projectRoot, 0755, true);

        file_put_contents(
            $projectRoot . '/glaze.neon',
            "djot:\n  codeHighlighting:\n    enabled: false\n    theme: DARK-PLUS\n    themes:\n      dark: DARK-PLUS\n      light: GITHUB-LIGHT\n    withGutter: true\n",
        );

        $config = BuildConfig::fromProjectRoot($projectRoot);

        $this->assertFalse($config->djotOptions->codeHighlightingEnabled);
        $this->assertSame('dark-plus', $config->djotOptions->codeHighlightingTheme);
        $this->assertSame(['dark' => 'dark-plus', 'light' => 'github-light'], $config->djotOptions->codeHighlightingThemes);
        $this->assertTrue($config->djotOptions->codeHighlightingWithGutter);
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

        $this->assertTrue($config->djotOptions->codeHighlightingEnabled);
        $this->assertSame('nord', $config->djotOptions->codeHighlightingTheme);
        $this->assertSame([], $config->djotOptions->codeHighlightingThemes);
        $this->assertFalse($config->djotOptions->codeHighlightingWithGutter);
    }

    /**
     * Ensure heading anchor settings are resolved in djotOptions.
     */
    public function testHeaderAnchorsCanBeConfiguredFromProjectFile(): void
    {
        $projectRoot = sys_get_temp_dir() . '/glaze-config-' . uniqid('', true);
        mkdir($projectRoot, 0755, true);

        file_put_contents(
            $projectRoot . '/glaze.neon',
            "djot:\n  headerAnchors:\n    enabled: true\n    symbol: '¶'\n    position: before\n    cssClass: docs-anchor\n    ariaLabel: Copy section link\n    levels: [2, 3]\n",
        );

        $config = BuildConfig::fromProjectRoot($projectRoot);

        $this->assertTrue($config->djotOptions->headerAnchorsEnabled);
        $this->assertSame('¶', $config->djotOptions->headerAnchorsSymbol);
        $this->assertSame('before', $config->djotOptions->headerAnchorsPosition);
        $this->assertSame('docs-anchor', $config->djotOptions->headerAnchorsCssClass);
        $this->assertSame('Copy section link', $config->djotOptions->headerAnchorsAriaLabel);
        $this->assertSame([2, 3], $config->djotOptions->headerAnchorsLevels);
    }

    /**
     * Ensure root-level codeHighlighting is ignored in favour of djot-scoped settings.
     */
    public function testRootCodeHighlightingIsIgnoredInFavorOfDjotScopedSettings(): void
    {
        $projectRoot = sys_get_temp_dir() . '/glaze-config-' . uniqid('', true);
        mkdir($projectRoot, 0755, true);

        file_put_contents(
            $projectRoot . '/glaze.neon',
            "codeHighlighting:\n  enabled: false\n  theme: github-dark\n  withGutter: true\n",
        );

        $config = BuildConfig::fromProjectRoot($projectRoot);

        $this->assertTrue($config->djotOptions->codeHighlightingEnabled);
        $this->assertSame('nord', $config->djotOptions->codeHighlightingTheme);
        $this->assertFalse($config->djotOptions->codeHighlightingWithGutter);
    }

    /**
     * Ensure autolink settings are resolved in djotOptions.
     */
    public function testAutolinkCanBeConfiguredFromProjectFile(): void
    {
        $projectRoot = sys_get_temp_dir() . '/glaze-config-' . uniqid('', true);
        mkdir($projectRoot, 0755, true);

        file_put_contents(
            $projectRoot . '/glaze.neon',
            "djot:\n  autolink:\n    enabled: true\n    allowedSchemes: [https, ftp]\n",
        );

        $config = BuildConfig::fromProjectRoot($projectRoot);

        $this->assertTrue($config->djotOptions->autolinkEnabled);
        $this->assertSame(['https', 'ftp'], $config->djotOptions->autolinkAllowedSchemes);
    }

    /**
     * Ensure autolink settings fall back to safe defaults when not configured.
     */
    public function testAutolinkFallsBackToDefaultsForInvalidConfig(): void
    {
        $projectRoot = sys_get_temp_dir() . '/glaze-config-' . uniqid('', true);
        mkdir($projectRoot, 0755, true);

        file_put_contents($projectRoot . '/glaze.neon', "djot:\n  autolink: true\n");

        $config = BuildConfig::fromProjectRoot($projectRoot);

        $this->assertFalse($config->djotOptions->autolinkEnabled);
        $this->assertSame(['https', 'http', 'mailto'], $config->djotOptions->autolinkAllowedSchemes);
    }

    /**
     * Ensure external links settings are resolved in djotOptions.
     */
    public function testExternalLinksCanBeConfiguredFromProjectFile(): void
    {
        $projectRoot = sys_get_temp_dir() . '/glaze-config-' . uniqid('', true);
        mkdir($projectRoot, 0755, true);

        file_put_contents(
            $projectRoot . '/glaze.neon',
            "djot:\n  externalLinks:\n    enabled: true\n    internalHosts: [example.com, cdn.example.com]\n    target: _blank\n    rel: noopener\n    nofollow: true\n",
        );

        $config = BuildConfig::fromProjectRoot($projectRoot);

        $this->assertTrue($config->djotOptions->externalLinksEnabled);
        $this->assertSame(['example.com', 'cdn.example.com'], $config->djotOptions->externalLinksInternalHosts);
        $this->assertSame('_blank', $config->djotOptions->externalLinksTarget);
        $this->assertSame('noopener', $config->djotOptions->externalLinksRel);
        $this->assertTrue($config->djotOptions->externalLinksNofollow);
    }

    /**
     * Ensure external links settings fall back to defaults when not configured.
     */
    public function testExternalLinksFallsBackToDefaultsForInvalidConfig(): void
    {
        $projectRoot = sys_get_temp_dir() . '/glaze-config-' . uniqid('', true);
        mkdir($projectRoot, 0755, true);

        file_put_contents($projectRoot . '/glaze.neon', "djot:\n  externalLinks: invalid\n");

        $config = BuildConfig::fromProjectRoot($projectRoot);

        $this->assertFalse($config->djotOptions->externalLinksEnabled);
        $this->assertSame([], $config->djotOptions->externalLinksInternalHosts);
        $this->assertSame('_blank', $config->djotOptions->externalLinksTarget);
        $this->assertSame('noopener noreferrer', $config->djotOptions->externalLinksRel);
        $this->assertFalse($config->djotOptions->externalLinksNofollow);
    }

    /**
     * Ensure smart quotes settings are resolved in djotOptions.
     */
    public function testSmartQuotesCanBeConfiguredFromProjectFile(): void
    {
        $projectRoot = sys_get_temp_dir() . '/glaze-config-' . uniqid('', true);
        mkdir($projectRoot, 0755, true);

        file_put_contents(
            $projectRoot . '/glaze.neon',
            "djot:\n  smartQuotes:\n    enabled: true\n    locale: de\n    openDouble: \"\u{201E}\"\n    closeDouble: \"\u{201C}\"\n",
        );

        $config = BuildConfig::fromProjectRoot($projectRoot);

        $this->assertTrue($config->djotOptions->smartQuotesEnabled);
        $this->assertSame('de', $config->djotOptions->smartQuotesLocale);
        $this->assertSame("\u{201E}", $config->djotOptions->smartQuotesOpenDouble);
        $this->assertSame("\u{201C}", $config->djotOptions->smartQuotesCloseDouble);
        $this->assertNull($config->djotOptions->smartQuotesOpenSingle);
        $this->assertNull($config->djotOptions->smartQuotesCloseSingle);
    }

    /**
     * Ensure smart quotes settings fall back to defaults when not configured.
     */
    public function testSmartQuotesFallsBackToDefaultsForInvalidConfig(): void
    {
        $projectRoot = sys_get_temp_dir() . '/glaze-config-' . uniqid('', true);
        mkdir($projectRoot, 0755, true);

        file_put_contents($projectRoot . '/glaze.neon', "djot:\n  smartQuotes: 1\n");

        $config = BuildConfig::fromProjectRoot($projectRoot);

        $this->assertFalse($config->djotOptions->smartQuotesEnabled);
        $this->assertNull($config->djotOptions->smartQuotesLocale);
        $this->assertNull($config->djotOptions->smartQuotesOpenDouble);
        $this->assertNull($config->djotOptions->smartQuotesCloseDouble);
        $this->assertNull($config->djotOptions->smartQuotesOpenSingle);
        $this->assertNull($config->djotOptions->smartQuotesCloseSingle);
    }

    /**
     * Ensure mentions settings are resolved in djotOptions.
     */
    public function testMentionsCanBeConfiguredFromProjectFile(): void
    {
        $projectRoot = sys_get_temp_dir() . '/glaze-config-' . uniqid('', true);
        mkdir($projectRoot, 0755, true);

        file_put_contents(
            $projectRoot . '/glaze.neon',
            "djot:\n  mentions:\n    enabled: true\n    urlTemplate: '/profiles/{username}'\n    cssClass: user-mention\n",
        );

        $config = BuildConfig::fromProjectRoot($projectRoot);

        $this->assertTrue($config->djotOptions->mentionsEnabled);
        $this->assertSame('/profiles/{username}', $config->djotOptions->mentionsUrlTemplate);
        $this->assertSame('user-mention', $config->djotOptions->mentionsCssClass);
    }

    /**
     * Ensure mentions settings fall back to defaults when not configured.
     */
    public function testMentionsFallsBackToDefaultsForInvalidConfig(): void
    {
        $projectRoot = sys_get_temp_dir() . '/glaze-config-' . uniqid('', true);
        mkdir($projectRoot, 0755, true);

        file_put_contents($projectRoot . '/glaze.neon', "djot:\n  mentions: true\n");

        $config = BuildConfig::fromProjectRoot($projectRoot);

        $this->assertFalse($config->djotOptions->mentionsEnabled);
        $this->assertSame('/users/view/{username}', $config->djotOptions->mentionsUrlTemplate);
        $this->assertSame('mention', $config->djotOptions->mentionsCssClass);
    }

    /**
     * Ensure semantic span settings are resolved in djotOptions.
     */
    public function testSemanticSpanCanBeConfiguredFromProjectFile(): void
    {
        $projectRoot = sys_get_temp_dir() . '/glaze-config-' . uniqid('', true);
        mkdir($projectRoot, 0755, true);

        file_put_contents(
            $projectRoot . '/glaze.neon',
            "djot:\n  semanticSpan:\n    enabled: true\n",
        );

        $config = BuildConfig::fromProjectRoot($projectRoot);

        $this->assertTrue($config->djotOptions->semanticSpanEnabled);
    }

    /**
     * Ensure semantic span settings fall back to defaults when not configured.
     */
    public function testSemanticSpanFallsBackToDefaultsForInvalidConfig(): void
    {
        $projectRoot = sys_get_temp_dir() . '/glaze-config-' . uniqid('', true);
        mkdir($projectRoot, 0755, true);

        file_put_contents($projectRoot . '/glaze.neon', "djot:\n  semanticSpan: invalid\n");

        $config = BuildConfig::fromProjectRoot($projectRoot);

        $this->assertFalse($config->djotOptions->semanticSpanEnabled);
    }

    /**
     * Ensure default attributes settings are resolved in djotOptions.
     */
    public function testDefaultAttributesCanBeConfiguredFromProjectFile(): void
    {
        $projectRoot = sys_get_temp_dir() . '/glaze-config-' . uniqid('', true);
        mkdir($projectRoot, 0755, true);

        file_put_contents(
            $projectRoot . '/glaze.neon',
            "djot:\n  defaultAttributes:\n    enabled: true\n    defaults:\n      heading:\n        class: heading\n      paragraph:\n        class: prose\n",
        );

        $config = BuildConfig::fromProjectRoot($projectRoot);

        $this->assertTrue($config->djotOptions->defaultAttributesEnabled);
        $this->assertSame([
            'heading' => ['class' => 'heading'],
            'paragraph' => ['class' => 'prose'],
        ], $config->djotOptions->defaultAttributesDefaults);
    }

    /**
     * Ensure default attributes settings fall back to defaults when not configured.
     */
    public function testDefaultAttributesFallsBackToDefaultsForInvalidConfig(): void
    {
        $projectRoot = sys_get_temp_dir() . '/glaze-config-' . uniqid('', true);
        mkdir($projectRoot, 0755, true);

        file_put_contents($projectRoot . '/glaze.neon', "djot:\n  defaultAttributes: invalid\n");

        $config = BuildConfig::fromProjectRoot($projectRoot);

        $this->assertFalse($config->djotOptions->defaultAttributesEnabled);
        $this->assertSame([], $config->djotOptions->defaultAttributesDefaults);
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
}
