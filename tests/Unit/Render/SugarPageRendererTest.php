<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Render;

use Glaze\Config\SiteConfig;
use Glaze\Config\TemplateViteOptions;
use Glaze\Render\SugarPageRenderer;
use Glaze\Support\ResourcePathRewriter;
use Glaze\Tests\Helper\FilesystemTestTrait;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Sugar\Core\Cache\FileCache;
use Sugar\Core\Loader\FileTemplateLoader;

/**
 * Tests for SugarPageRenderer helpers and runtime configuration.
 */
final class SugarPageRendererTest extends TestCase
{
    use FilesystemTestTrait;

    /**
     * Ensure loader and cache accessors return stable instances reused by the renderer.
     */
    public function testGetLoaderAndGetCacheReturnStableInstances(): void
    {
        $projectRoot = $this->createTempDirectory();
        $templatePath = $projectRoot . '/templates';
        $cachePath = $projectRoot . '/tmp/cache/sugar';

        mkdir($templatePath, 0755, true);

        $renderer = new SugarPageRenderer(
            templatePath: $templatePath,
            cachePath: $cachePath,
            template: 'page',
            siteConfig: new SiteConfig(),
            resourcePathRewriter: new ResourcePathRewriter(),
            templateVite: new TemplateViteOptions(),
            liveMode: false,
        );

        $loaderOne = $renderer->getLoader();
        $loaderTwo = $renderer->getLoader();
        $cacheOne = $renderer->getCache();
        $cacheTwo = $renderer->getCache();

        $this->assertInstanceOf(FileTemplateLoader::class, $loaderOne);
        $this->assertSame($loaderOne, $loaderTwo);
        $this->assertInstanceOf(FileCache::class, $cacheOne);
        $this->assertSame($cacheOne, $cacheTwo);
    }

    /**
     * Ensure resolveViteConfiguration returns null when Vite is disabled.
     */
    public function testResolveViteConfigurationReturnsNullWhenDisabled(): void
    {
        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/templates', 0755, true);

        $renderer = new SugarPageRenderer(
            templatePath: $projectRoot . '/templates',
            cachePath: $projectRoot . '/tmp/cache/sugar',
            template: 'page',
            siteConfig: new SiteConfig(),
            resourcePathRewriter: new ResourcePathRewriter(),
            templateVite: new TemplateViteOptions(devEnabled: false),
            liveMode: true,
        );

        $result = (new ReflectionMethod(SugarPageRenderer::class, 'resolveViteConfiguration'))
            ->invoke($renderer);

        $this->assertNull($result);
    }

    /**
     * Ensure resolveViteConfiguration returns the configured devServerUrl without modification.
     */
    public function testResolveViteConfigurationReturnsConfiguredDevServerUrl(): void
    {
        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/templates', 0755, true);

        $renderer = new SugarPageRenderer(
            templatePath: $projectRoot . '/templates',
            cachePath: $projectRoot . '/tmp/cache/sugar',
            template: 'page',
            siteConfig: new SiteConfig(),
            resourcePathRewriter: new ResourcePathRewriter(),
            templateVite: new TemplateViteOptions(devEnabled: true, devServerUrl: 'http://127.0.0.1:5184'),
            liveMode: true,
        );

        $result = (new ReflectionMethod(SugarPageRenderer::class, 'resolveViteConfiguration'))
            ->invoke($renderer);

        $this->assertIsArray($result);
        $this->assertSame('http://127.0.0.1:5184', $result['devServerUrl']);
        $this->assertSame('dev', $result['mode']);
    }

    /**
     * Ensure resolveViteConfiguration passes an explicit mode through unmodified.
     */
    public function testResolveViteConfigurationPassesThroughExplicitMode(): void
    {
        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/templates', 0755, true);

        foreach (['dev', 'prod'] as $mode) {
            $options = new TemplateViteOptions(buildEnabled: true, devEnabled: true, mode: $mode);

            $rendererDev = new SugarPageRenderer(
                templatePath: $projectRoot . '/templates',
                cachePath: $projectRoot . '/tmp/cache/sugar',
                template: 'page',
                siteConfig: new SiteConfig(),
                resourcePathRewriter: new ResourcePathRewriter(),
                templateVite: $options,
                liveMode: true,
            );

            $rendererProd = new SugarPageRenderer(
                templatePath: $projectRoot . '/templates',
                cachePath: $projectRoot . '/tmp/cache/sugar',
                template: 'page',
                siteConfig: new SiteConfig(),
                resourcePathRewriter: new ResourcePathRewriter(),
                templateVite: $options,
                liveMode: false,
            );

            $resultDev = (new ReflectionMethod(SugarPageRenderer::class, 'resolveViteConfiguration'))
                ->invoke($rendererDev);
            $resultProd = (new ReflectionMethod(SugarPageRenderer::class, 'resolveViteConfiguration'))
                ->invoke($rendererProd);

            $this->assertIsArray($resultDev);
            $this->assertSame($mode, $resultDev['mode'], 'debug=true, mode=' . $mode);
            $this->assertIsArray($resultProd);
            $this->assertSame($mode, $resultProd['mode'], 'debug=false, mode=' . $mode);
        }
    }

    /**
     * Ensure auto mode resolves to dev in live mode and prod in build mode.
     */
    public function testResolveViteConfigurationResolvesAutoModeFromLiveMode(): void
    {
        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/templates', 0755, true);

        $options = new TemplateViteOptions(buildEnabled: true, devEnabled: true, mode: 'auto');

        $rendererLiveDebug = new SugarPageRenderer(
            templatePath: $projectRoot . '/templates',
            cachePath: $projectRoot . '/tmp/cache/sugar',
            template: 'page',
            siteConfig: new SiteConfig(),
            resourcePathRewriter: new ResourcePathRewriter(),
            templateVite: $options,
            liveMode: true,
        );
        $rendererBuild = new SugarPageRenderer(
            templatePath: $projectRoot . '/templates',
            cachePath: $projectRoot . '/tmp/cache/sugar',
            template: 'page',
            siteConfig: new SiteConfig(),
            resourcePathRewriter: new ResourcePathRewriter(),
            templateVite: $options,
            liveMode: false,
        );

        $method = new ReflectionMethod(SugarPageRenderer::class, 'resolveViteConfiguration');
        $resultLiveDebug = $method->invoke($rendererLiveDebug);
        $resultBuild = $method->invoke($rendererBuild);

        $this->assertIsArray($resultLiveDebug);
        $this->assertSame('dev', $resultLiveDebug['mode']);
        $this->assertIsArray($resultBuild);
        $this->assertSame('prod', $resultBuild['mode']);
    }
}
