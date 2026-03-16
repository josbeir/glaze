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
        $this->assertSame('auto', $options->mode);
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
        $this->assertSame('auto', $options->mode);
    }

    /**
     * Ensure a valid `mode` in the build config block is read and preserved.
     */
    public function testFromProjectConfigReadsExplicitMode(): void
    {
        $optionsDev = TemplateViteOptions::fromProjectConfig(['mode' => 'dev'], [], '/project');
        $optionsProd = TemplateViteOptions::fromProjectConfig(['mode' => 'prod'], [], '/project');
        $optionsAuto = TemplateViteOptions::fromProjectConfig(['mode' => 'auto'], [], '/project');

        $this->assertSame('dev', $optionsDev->mode);
        $this->assertSame('prod', $optionsProd->mode);
        $this->assertSame('auto', $optionsAuto->mode);
    }

    /**
     * Ensure an invalid or unrecognised `mode` value falls back to `auto`.
     */
    public function testFromProjectConfigInvalidModeFallsBackToAuto(): void
    {
        $options = TemplateViteOptions::fromProjectConfig(['mode' => 'invalid'], [], '/project');

        $this->assertSame('auto', $options->mode);
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

        $this->assertSame('/my/project/public/.vite/manifest.json', $options->manifestPath);
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

        $this->assertSame('/absolute/path/manifest.json', $options->manifestPath);
    }

    /**
     * Ensure the default manifest path is resolved against the project root.
     */
    public function testDefaultManifestPathIsResolvedAgainstProjectRoot(): void
    {
        $options = TemplateViteOptions::fromProjectConfig([], [], '/my/project');

        $this->assertSame('/my/project/public/.vite/manifest.json', $options->manifestPath);
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

    /**
     * Ensure applyEnvironmentOverrides returns the same options when no env vars are set.
     */
    public function testApplyEnvironmentOverridesReturnsUnchangedOptionsWhenNoEnvVarsAreSet(): void
    {
        $original = new TemplateViteOptions(devEnabled: true, devServerUrl: 'http://127.0.0.1:5173');
        $result = $original->applyEnvironmentOverrides();

        $this->assertTrue($result->devEnabled);
        $this->assertSame('http://127.0.0.1:5173', $result->devServerUrl);
    }

    /**
     * Ensure GLAZE_VITE_ENABLED=1 forces devEnabled on regardless of config.
     */
    public function testApplyEnvironmentOverridesEnablesDevWhenEnvVarIsOne(): void
    {
        putenv('GLAZE_VITE_ENABLED=1');

        try {
            $result = (new TemplateViteOptions(devEnabled: false))->applyEnvironmentOverrides();

            $this->assertTrue($result->devEnabled);
        } finally {
            putenv('GLAZE_VITE_ENABLED');
        }
    }

    /**
     * Ensure GLAZE_VITE_ENABLED=0 forces devEnabled off regardless of config.
     */
    public function testApplyEnvironmentOverridesDisablesDevWhenEnvVarIsZero(): void
    {
        putenv('GLAZE_VITE_ENABLED=0');

        try {
            $result = (new TemplateViteOptions(devEnabled: true))->applyEnvironmentOverrides();

            $this->assertFalse($result->devEnabled);
        } finally {
            putenv('GLAZE_VITE_ENABLED');
        }
    }

    /**
     * Ensure GLAZE_VITE_URL replaces devServerUrl when set.
     */
    public function testApplyEnvironmentOverridesReplacesDevServerUrlFromEnv(): void
    {
        putenv('GLAZE_VITE_URL=http://127.0.0.1:9999');

        try {
            $result = (new TemplateViteOptions(devServerUrl: 'http://127.0.0.1:5173'))
                ->applyEnvironmentOverrides();

            $this->assertSame('http://127.0.0.1:9999', $result->devServerUrl);
        } finally {
            putenv('GLAZE_VITE_URL');
        }
    }

    /**
     * Ensure all other fields are preserved unchanged by applyEnvironmentOverrides.
     */
    public function testApplyEnvironmentOverridesPreservesAllOtherFields(): void
    {
        $original = new TemplateViteOptions(
            buildEnabled: true,
            assetBaseUrl: '/_glaze/assets/',
            manifestPath: '/some/path/manifest.json',
            injectClient: false,
            defaultEntry: 'assets/app.js',
        );

        $result = $original->applyEnvironmentOverrides();

        $this->assertSame($original->buildEnabled, $result->buildEnabled);
        $this->assertSame($original->assetBaseUrl, $result->assetBaseUrl);
        $this->assertSame($original->manifestPath, $result->manifestPath);
        $this->assertSame($original->injectClient, $result->injectClient);
        $this->assertSame($original->defaultEntry, $result->defaultEntry);
        $this->assertSame($original->mode, $result->mode);
    }

    /**
     * Ensure withMode returns a new immutable instance and only changes mode.
     */
    public function testWithModeReturnsNewInstanceWithOnlyModeChanged(): void
    {
        $original = new TemplateViteOptions(
            buildEnabled: true,
            devEnabled: true,
            assetBaseUrl: '/assets/',
            manifestPath: '/project/public/.vite/manifest.json',
            devServerUrl: 'http://localhost:5173',
            injectClient: false,
            defaultEntry: 'assets/app.js',
            mode: 'auto',
        );

        $result = $original->withMode('prod');

        $this->assertNotSame($original, $result);
        $this->assertSame('auto', $original->mode);
        $this->assertSame('prod', $result->mode);
        $this->assertSame($original->buildEnabled, $result->buildEnabled);
        $this->assertSame($original->devEnabled, $result->devEnabled);
        $this->assertSame($original->assetBaseUrl, $result->assetBaseUrl);
        $this->assertSame($original->manifestPath, $result->manifestPath);
        $this->assertSame($original->devServerUrl, $result->devServerUrl);
        $this->assertSame($original->injectClient, $result->injectClient);
        $this->assertSame($original->defaultEntry, $result->defaultEntry);
    }
}
