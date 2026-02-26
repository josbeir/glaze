<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Support;

use Glaze\Config\BuildConfig;
use Glaze\Image\GlideImageTransformer;
use Glaze\Image\ImagePresetResolver;
use Glaze\Support\BuildGlideHtmlRewriter;
use Glaze\Support\ResourcePathRewriter;
use Glaze\Tests\Helper\FilesystemTestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Tests for build-time Glide HTML rewriting behavior.
 */
final class BuildGlideHtmlRewriterTest extends TestCase
{
    use FilesystemTestTrait;

    /**
     * Ensure build-time Glide rewriting updates img/src query URLs and publishes transformed assets.
     */
    public function testRewriteRewritesImageSrcAndPublishesTransformedAsset(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD extension is required for Glide image transformation tests.');
        }

        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/content/images', 0755, true);
        mkdir($projectRoot . '/public', 0755, true);

        $this->createPng($projectRoot . '/content/images/hero.png');

        $config = BuildConfig::fromProjectRoot($projectRoot, true);
        $rewriter = $this->createRewriter();

        $html = '<img src="/images/hero.png?w=100&h=50"><img src="/images/plain.png"><img src="https://example.com/img.png?w=100">';

        $rewritten = $rewriter->rewrite($html, $config);

        $hash = hash('xxh3', '/images/hero.png?w=100&h=50');
        $this->assertStringContainsString('/_glide/' . $hash . '.png', $rewritten);
        $this->assertStringContainsString('/images/plain.png', $rewritten);
        $this->assertStringContainsString('https://example.com/img.png?w=100', $rewritten);
        $this->assertFileExists($projectRoot . '/public/_glide/' . $hash . '.png');
    }

    /**
     * Ensure srcset and source attributes are rewritten candidate-by-candidate.
     */
    public function testRewriteRewritesSrcsetAndSourceCandidates(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD extension is required for Glide image transformation tests.');
        }

        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/content/images', 0755, true);
        mkdir($projectRoot . '/public', 0755, true);

        $this->createPng($projectRoot . '/content/images/hero.png');

        file_put_contents($projectRoot . '/glaze.neon', "site:\n  basePath: /docs\n");

        $config = BuildConfig::fromProjectRoot($projectRoot, true);
        $rewriter = $this->createRewriter();

        $html = '<picture>'
            . '<source src="/images/hero.png?w=300" srcset="/images/hero.png?w=100 1x, /images/hero.png?w=200 2x">'
            . '<img srcset="/images/hero.png?w=100 1x, /images/hero.png?w=200 2x" src="/images/hero.png?w=100">'
            . '</picture>';

        $rewritten = $rewriter->rewrite($html, $config);

        $hash100 = hash('xxh3', '/images/hero.png?w=100');
        $hash200 = hash('xxh3', '/images/hero.png?w=200');
        $hash300 = hash('xxh3', '/images/hero.png?w=300');

        $this->assertStringContainsString('/docs/_glide/' . $hash100 . '.png 1x', $rewritten);
        $this->assertStringContainsString('/docs/_glide/' . $hash200 . '.png 2x', $rewritten);
        $this->assertStringContainsString('src="/docs/_glide/' . $hash300 . '.png"', $rewritten);
        $this->assertFileExists($projectRoot . '/public/_glide/' . $hash100 . '.png');
        $this->assertFileExists($projectRoot . '/public/_glide/' . $hash200 . '.png');
        $this->assertFileExists($projectRoot . '/public/_glide/' . $hash300 . '.png');
    }

    /**
     * Ensure data URLs inside srcset are left untouched to avoid invalid splitting.
     */
    public function testRewriteSkipsDataSrcsetValue(): void
    {
        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/public', 0755, true);

        $config = BuildConfig::fromProjectRoot($projectRoot, true);
        $rewriter = $this->createRewriter();

        $html = '<img srcset="data:image/png;base64,AAA 1x, /images/hero.png?w=200 2x">';
        $rewritten = $rewriter->rewrite($html, $config);

        $this->assertSame($html, $rewritten);
    }

    /**
     * Create service under test with concrete dependencies.
     */
    protected function createRewriter(): BuildGlideHtmlRewriter
    {
        return new BuildGlideHtmlRewriter(
            glideImageTransformer: new GlideImageTransformer(new ImagePresetResolver()),
            resourcePathRewriter: new ResourcePathRewriter(),
        );
    }

    /**
     * Create a tiny PNG file used by Glide transformations.
     *
     * @param string $path Target PNG path.
     */
    protected function createPng(string $path): void
    {
        $image = imagecreatetruecolor(2, 2);
        $this->assertNotFalse($image);
        $fillColor = imagecolorallocate($image, 0, 0, 0);
        $this->assertIsInt($fillColor);
        imagefill($image, 0, 0, $fillColor);
        imagepng($image, $path);
    }
}
