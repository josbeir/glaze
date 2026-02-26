<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Config;

use Glaze\Config\TemplateViteOptions;
use PHPUnit\Framework\TestCase;

/**
 * Tests for TemplateViteOptions construction and defaults.
 */
final class TemplateViteOptionsTest extends TestCase
{
    /**
     * Ensure a default-constructed TemplateViteOptions carries all expected defaults.
     */
    public function testDefaultConstructorCarriesExpectedDefaults(): void
    {
        $options = new TemplateViteOptions();

        $this->assertFalse($options->buildEnabled);
        $this->assertFalse($options->devEnabled);
        $this->assertSame('/', $options->assetBaseUrl);
        $this->assertSame('', $options->manifestPath);
        $this->assertSame('http://127.0.0.1:5173', $options->devServerUrl);
        $this->assertTrue($options->injectClient);
        $this->assertNull($options->defaultEntry);
    }

    /**
     * Ensure empty config arrays resolve to sensible defaults.
     */
    public function testFromProjectConfigWithEmptyArraysUsesDefaults(): void
    {
        $options = TemplateViteOptions::fromProjectConfig([], [], '/project');

        $this->assertFalse($options->buildEnabled);
        $this->assertFalse($options->devEnabled);
        $this->assertSame('/', $options->assetBaseUrl);
        $this->assertSame('http://127.0.0.1:5173', $options->devServerUrl);
        $this->assertTrue($options->injectClient);
        $this->assertNull($options->defaultEntry);
    }

    /**
     * Ensure `enabled` flags are read from their respective config blocks.
     */
    public function testEnabledFlagsAreReadFromConfig(): void
    {
        $options = TemplateViteOptions::fromProjectConfig(
            ['enabled' => true],
            ['enabled' => true],
            '/project',
        );

        $this->assertTrue($options->buildEnabled);
        $this->assertTrue($options->devEnabled);
    }

    /**
     * Ensure non-bool enabled values fall back to false.
     */
    public function testNonBoolEnabledFallsBackToFalse(): void
    {
        $options = TemplateViteOptions::fromProjectConfig(
            ['enabled' => 'yes'],
            ['enabled' => 1],
            '/project',
        );

        $this->assertFalse($options->buildEnabled);
        $this->assertFalse($options->devEnabled);
    }

    /**
     * Ensure assetBaseUrl is parsed and always has a trailing slash appended.
     */
    public function testAssetBaseUrlAlwaysHasTrailingSlash(): void
    {
        $options = TemplateViteOptions::fromProjectConfig(
            ['assetBaseUrl' => '/build/assets'],
            [],
            '/project',
        );

        $this->assertSame('/build/assets/', $options->assetBaseUrl);
    }

    /**
     * Ensure an assetBaseUrl that already has a trailing slash is not doubled.
     */
    public function testAssetBaseUrlWithExistingTrailingSlashIsNotDoubled(): void
    {
        $options = TemplateViteOptions::fromProjectConfig(
            ['assetBaseUrl' => '/build/assets/'],
            [],
            '/project',
        );

        $this->assertSame('/build/assets/', $options->assetBaseUrl);
    }

    /**
     * Ensure an empty assetBaseUrl falls back to the default.
     */
    public function testEmptyAssetBaseUrlFallsBackToDefault(): void
    {
        $options = TemplateViteOptions::fromProjectConfig(
            ['assetBaseUrl' => ''],
            [],
            '/project',
        );

        $this->assertSame('/', $options->assetBaseUrl);
    }

    /**
     * Ensure a relative manifest path is resolved against the project root.
     */
    public function testRelativeManifestPathIsResolvedAgainstProjectRoot(): void
    {
        $options = TemplateViteOptions::fromProjectConfig(
            ['manifestPath' => 'public/.vite/manifest.json'],
            [],
            '/my/project',
        );

        $this->assertSame('/my/project/public/.vite/manifest.json', $this->normalizePath($options->manifestPath));
    }

    /**
     * Ensure an absolute manifest path is not modified.
     */
    public function testAbsoluteManifestPathIsNotModified(): void
    {
        $options = TemplateViteOptions::fromProjectConfig(
            ['manifestPath' => '/absolute/path/manifest.json'],
            [],
            '/my/project',
        );

        $this->assertSame('/absolute/path/manifest.json', $this->normalizePath($options->manifestPath));
    }

    /**
     * Ensure the default manifest path is resolved against the project root.
     */
    public function testDefaultManifestPathIsResolvedAgainstProjectRoot(): void
    {
        $options = TemplateViteOptions::fromProjectConfig([], [], '/my/project');

        $this->assertSame('/my/project/public/.vite/manifest.json', $this->normalizePath($options->manifestPath));
    }

    /**
     * Normalize directory separators to forward slashes for cross-platform path comparison.
     *
     * @param string $path Path value to normalize.
     */
    protected function normalizePath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    /**
     * Ensure an explicit `url` in dev config takes precedence over host/port.
     */
    public function testExplicitDevServerUrlTakesPrecedenceOverHostPort(): void
    {
        $options = TemplateViteOptions::fromProjectConfig([], [
            'url' => 'http://localhost:3000',
            'host' => '10.0.0.1',
            'port' => 8080,
        ], '/project');

        $this->assertSame('http://localhost:3000', $options->devServerUrl);
    }

    /**
     * Ensure dev server URL is assembled from host and port when no explicit url is given.
     */
    public function testDevServerUrlIsAssembledFromHostAndPort(): void
    {
        $options = TemplateViteOptions::fromProjectConfig([], [
            'host' => 'localhost',
            'port' => 3000,
        ], '/project');

        $this->assertSame('http://localhost:3000', $options->devServerUrl);
    }

    /**
     * Ensure an out-of-range port falls back to the default port.
     */
    public function testOutOfRangePortFallsBackToDefault(): void
    {
        $options = TemplateViteOptions::fromProjectConfig([], [
            'host' => 'localhost',
            'port' => 99999,
        ], '/project');

        $this->assertSame('http://localhost:5173', $options->devServerUrl);
    }

    /**
     * Ensure a non-int port falls back to the default port.
     */
    public function testNonIntPortFallsBackToDefault(): void
    {
        $options = TemplateViteOptions::fromProjectConfig([], [
            'host' => 'localhost',
            'port' => '3000',
        ], '/project');

        $this->assertSame('http://localhost:5173', $options->devServerUrl);
    }

    /**
     * Ensure missing host falls back to `127.0.0.1`.
     */
    public function testMissingHostFallsBackToLoopback(): void
    {
        $options = TemplateViteOptions::fromProjectConfig([], ['port' => 3000], '/project');

        $this->assertSame('http://127.0.0.1:3000', $options->devServerUrl);
    }

    /**
     * Ensure injectClient is read as a bool from dev config.
     */
    public function testInjectClientIsReadFromDevConfig(): void
    {
        $options = TemplateViteOptions::fromProjectConfig([], ['injectClient' => false], '/project');

        $this->assertFalse($options->injectClient);
    }

    /**
     * Ensure non-bool injectClient falls back to true.
     */
    public function testNonBoolInjectClientFallsBackToTrue(): void
    {
        $options = TemplateViteOptions::fromProjectConfig([], ['injectClient' => 0], '/project');

        $this->assertTrue($options->injectClient);
    }

    /**
     * Ensure defaultEntry is read from dev config first.
     */
    public function testDefaultEntryReadFromDevConfig(): void
    {
        $options = TemplateViteOptions::fromProjectConfig(
            ['defaultEntry' => 'src/build.js'],
            ['defaultEntry' => 'src/dev.js'],
            '/project',
        );

        $this->assertSame('src/dev.js', $options->defaultEntry);
    }

    /**
     * Ensure defaultEntry falls back to build config when absent in dev config.
     */
    public function testDefaultEntryFallsBackToBuildConfig(): void
    {
        $options = TemplateViteOptions::fromProjectConfig(
            ['defaultEntry' => 'src/build.js'],
            [],
            '/project',
        );

        $this->assertSame('src/build.js', $options->defaultEntry);
    }

    /**
     * Ensure defaultEntry is null when absent from both config blocks.
     */
    public function testDefaultEntryIsNullWhenAbsent(): void
    {
        $options = TemplateViteOptions::fromProjectConfig([], [], '/project');

        $this->assertNull($options->defaultEntry);
    }

    /**
     * Ensure an empty defaultEntry string falls back to null.
     */
    public function testEmptyDefaultEntryFallsBackToNull(): void
    {
        $options = TemplateViteOptions::fromProjectConfig(
            ['defaultEntry' => ''],
            ['defaultEntry' => '  '],
            '/project',
        );

        $this->assertNull($options->defaultEntry);
    }
}
