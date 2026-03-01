<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Template;

use Glaze\Content\ContentPage;
use Glaze\Template\ContentAssetResolver;
use Glaze\Tests\Helper\FilesystemTestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Tests for content asset resolver scanning behavior.
 */
final class ContentAssetResolverTest extends TestCase
{
    use FilesystemTestTrait;

    /**
     * Ensure direct directory scanning excludes Djot files.
     */
    public function testForDirectoryReturnsDirectAssetsOnly(): void
    {
        $contentPath = $this->createTempDirectory();
        mkdir($contentPath . '/post', 0755, true);
        file_put_contents($contentPath . '/post/index.dj', '# Post');
        file_put_contents($contentPath . '/post/one.png', '1');
        file_put_contents($contentPath . '/post/two.jpg', '22');
        mkdir($contentPath . '/post/gallery', 0755, true);
        file_put_contents($contentPath . '/post/gallery/three.png', '333');

        $resolver = new ContentAssetResolver($contentPath);
        $assets = $resolver->forDirectory('post');

        $this->assertCount(2, $assets);
        $this->assertSame(['one.png', 'two.jpg'], array_map(static fn($asset) => $asset->filename, $assets->sortByName()->all()));
    }

    /**
     * Ensure recursive directory scanning includes child directories.
     */
    public function testForDirectoryRecursiveIncludesChildDirectories(): void
    {
        $contentPath = $this->createTempDirectory();
        mkdir($contentPath . '/post/gallery', 0755, true);
        file_put_contents($contentPath . '/post/cover.png', '1');
        file_put_contents($contentPath . '/post/gallery/photo.png', '22');

        $resolver = new ContentAssetResolver($contentPath);
        $assets = $resolver->forDirectoryRecursive('post')->sortByName();

        $this->assertCount(2, $assets);
        $this->assertSame('post/cover.png', $assets->first()?->relativePath);
        $this->assertSame('post/gallery/photo.png', $assets->last()?->relativePath);
    }

    /**
     * Ensure page-level scanning supports bundle and leaf page structures.
     */
    public function testForPageResolvesBundleAndLeafPageDirectories(): void
    {
        $contentPath = $this->createTempDirectory();
        mkdir($contentPath . '/my-post/gallery', 0755, true);
        file_put_contents($contentPath . '/my-post/index.dj', '# Post');
        file_put_contents($contentPath . '/my-post/image1.png', '1');
        file_put_contents($contentPath . '/my-post/gallery/image2.jpg', '22');
        file_put_contents($contentPath . '/about.dj', '# About');
        file_put_contents($contentPath . '/logo.svg', '<svg></svg>');

        $bundlePage = $this->makePage($contentPath . '/my-post/index.dj', 'my-post/index.dj', 'my-post');
        $leafPage = $this->makePage($contentPath . '/about.dj', 'about.dj', 'about');

        $resolver = new ContentAssetResolver($contentPath);

        $bundleAssets = $resolver->forPage($bundlePage)->sortByName();
        $this->assertCount(1, $bundleAssets);
        $this->assertSame('my-post/image1.png', $bundleAssets->first()?->relativePath);

        $galleryAssets = $resolver->forPage($bundlePage, 'gallery');
        $this->assertCount(1, $galleryAssets);
        $this->assertSame('my-post/gallery/image2.jpg', $galleryAssets->first()?->relativePath);

        $leafAssets = $resolver->forPage($leafPage);
        $this->assertCount(1, $leafAssets);
        $this->assertSame('logo.svg', $leafAssets->first()?->filename);
    }

    /**
     * Ensure generated URLs include site base path when configured.
     */
    public function testResolverAppliesBasePathToAssetUrls(): void
    {
        $contentPath = $this->createTempDirectory();
        mkdir($contentPath . '/gallery', 0755, true);
        file_put_contents($contentPath . '/gallery/image.png', 'x');

        $resolver = new ContentAssetResolver($contentPath, '/docs');
        $asset = $resolver->forDirectory('gallery')->first();

        $this->assertSame('/docs/gallery/image.png', $asset?->urlPath);
    }

    /**
     * Ensure missing directories return an empty collection.
     */
    public function testForDirectoryReturnsEmptyForMissingDirectory(): void
    {
        $contentPath = $this->createTempDirectory();

        $resolver = new ContentAssetResolver($contentPath);

        $this->assertCount(0, $resolver->forDirectory('missing/path'));
        $this->assertCount(0, $resolver->forDirectoryRecursive('missing/path'));
    }

    /**
     * Ensure null relative path scans content root directly.
     */
    public function testForDirectoryScansContentRootWhenRelativePathIsNull(): void
    {
        $contentPath = $this->createTempDirectory();
        file_put_contents($contentPath . '/index.dj', '# Home');
        file_put_contents($contentPath . '/logo.svg', '<svg></svg>');

        $resolver = new ContentAssetResolver($contentPath);
        $assets = $resolver->forDirectory();

        $this->assertCount(1, $assets);
        $this->assertSame('logo.svg', $assets->first()?->relativePath);
    }

    /**
     * Ensure forPage handles pages with an empty relative path and subdirectories.
     */
    public function testForPageSupportsEmptyRelativePathWithSubdirectory(): void
    {
        $contentPath = $this->createTempDirectory();
        mkdir($contentPath . '/images', 0755, true);
        file_put_contents($contentPath . '/images/logo.png', 'logo');

        $page = $this->makePage($contentPath . '/index.dj', '', 'home');
        $resolver = new ContentAssetResolver($contentPath);

        $assets = $resolver->forPage($page, 'images');

        $this->assertCount(1, $assets);
        $this->assertSame('images/logo.png', $assets->first()?->relativePath);
    }

    /**
     * Build a minimal page value object for resolver scenarios.
     *
     * @param string $sourcePath Absolute source file path.
     * @param string $relativePath Relative source path.
     * @param string $slug Page slug.
     */
    protected function makePage(string $sourcePath, string $relativePath, string $slug): ContentPage
    {
        return new ContentPage(
            sourcePath: $sourcePath,
            relativePath: $relativePath,
            slug: $slug,
            urlPath: '/' . $slug . '/',
            outputRelativePath: $slug . '/index.html',
            title: 'Page',
            source: '# Page',
            draft: false,
            meta: [],
            taxonomies: [],
        );
    }
}
