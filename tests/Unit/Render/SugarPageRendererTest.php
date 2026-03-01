<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Render;

use Glaze\Config\SiteConfig;
use Glaze\Config\TemplateViteOptions;
use Glaze\Render\SugarPageRenderer;
use Glaze\Support\ResourcePathRewriter;
use Glaze\Tests\Helper\FilesystemTestTrait;
use PHPUnit\Framework\TestCase;
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
            debug: false,
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
}
