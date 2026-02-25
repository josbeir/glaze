<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Build;

use Closure;
use Glaze\Build\SiteBuilder;
use Glaze\Config\BuildConfig;
use Glaze\Config\SiteConfig;
use Glaze\Tests\Helper\ContainerTestTrait;
use Glaze\Tests\Helper\FilesystemTestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Tests for site build orchestration.
 */
final class SiteBuilderTest extends TestCase
{
    use ContainerTestTrait;
    use FilesystemTestTrait;

    /**
     * Ensure Djot content is rendered and written to expected output files.
     */
    public function testBuildWritesRenderedOutputFiles(): void
    {
        $projectRoot = $this->copyFixtureToTemp('projects/basic');
        mkdir($projectRoot . '/content/docs', 0755, true);
        mkdir($projectRoot . '/content/docs/images', 0755, true);

        file_put_contents($projectRoot . '/content/docs/getting-started.dj', "# Start\n");
        file_put_contents($projectRoot . '/content/docs/images/cover.jpg', 'binary-image');

        $builder = $this->createSiteBuilder();
        $config = BuildConfig::fromProjectRoot($projectRoot);
        $writtenFiles = $builder->build($config);

        $this->assertCount(2, $writtenFiles);
        $this->assertFileExists($projectRoot . '/public/index.html');
        $this->assertFileExists($projectRoot . '/public/docs/getting-started/index.html');
        $this->assertFileExists($projectRoot . '/public/docs/images/cover.jpg');
        $this->assertFileDoesNotExist($projectRoot . '/public/docs/getting-started.dj');

        $homeOutput = file_get_contents($projectRoot . '/public/index.html');
        $docsOutput = file_get_contents($projectRoot . '/public/docs/getting-started/index.html');
        $this->assertIsString($homeOutput);
        $this->assertIsString($docsOutput);
        $this->assertStringContainsString('<h1>Home</h1>', $homeOutput);
        $this->assertStringContainsString('<h1>Home</h1>', $homeOutput);
        $this->assertStringContainsString('<h1>Start</h1>', $docsOutput);
    }

    /**
     * Ensure build copies nested content assets preserving relative paths.
     */
    public function testBuildCopiesContentAssetsPreservingStructure(): void
    {
        $projectRoot = $this->copyFixtureToTemp('projects/basic');
        mkdir($projectRoot . '/content/blog/my-post', 0755, true);

        file_put_contents($projectRoot . '/content/blog/my-post/index.dj', "# Post\n");
        file_put_contents($projectRoot . '/content/blog/my-post/photo.png', 'png-bytes');

        $builder = $this->createSiteBuilder();
        $config = BuildConfig::fromProjectRoot($projectRoot);
        $builder->build($config);

        $this->assertFileExists($projectRoot . '/public/blog/my-post/photo.png');
        $this->assertSame('png-bytes', file_get_contents($projectRoot . '/public/blog/my-post/photo.png'));
    }

    /**
     * Ensure build copies static assets into output root preserving relative paths.
     */
    public function testBuildCopiesStaticAssetsToOutputRoot(): void
    {
        $projectRoot = $this->copyFixtureToTemp('projects/basic');
        mkdir($projectRoot . '/static/js', 0755, true);

        file_put_contents($projectRoot . '/static/robots.txt', "User-agent: *\nAllow: /\n");
        file_put_contents($projectRoot . '/static/js/app.js', 'console.log("ok");');

        $builder = $this->createSiteBuilder();
        $config = BuildConfig::fromProjectRoot($projectRoot);
        $builder->build($config);

        $this->assertFileExists($projectRoot . '/public/robots.txt');
        $this->assertFileExists($projectRoot . '/public/js/app.js');
        $this->assertSame('console.log("ok");', file_get_contents($projectRoot . '/public/js/app.js'));
    }

    /**
     * Ensure request rendering reuses the build rendering pipeline.
     */
    public function testRenderRequestReturnsHtmlForKnownPath(): void
    {
        $projectRoot = $this->copyFixtureToTemp('projects/basic');

        $builder = $this->createSiteBuilder();
        $config = BuildConfig::fromProjectRoot($projectRoot);
        $html = $builder->renderRequest($config, '/');

        $this->assertIsString($html);
        $this->assertStringContainsString('<h1>Home</h1>', $html);
        $this->assertStringContainsString('<section id="Home">', $html);
    }

    /**
     * Ensure unknown request paths return null in live mode rendering.
     */
    public function testRenderRequestReturnsNullForUnknownPath(): void
    {
        $projectRoot = $this->copyFixtureToTemp('projects/basic');

        $builder = $this->createSiteBuilder();
        $config = BuildConfig::fromProjectRoot($projectRoot);
        $html = $builder->renderRequest($config, '/missing-page/');

        $this->assertNull($html);
    }

    /**
     * Ensure clean-output mode removes stale files before regenerating pages.
     */
    public function testBuildWithCleanOutputRemovesStaleFiles(): void
    {
        $projectRoot = $this->copyFixtureToTemp('projects/basic');
        mkdir($projectRoot . '/public/old', 0755, true);

        file_put_contents($projectRoot . '/public/old/stale.html', 'stale');

        $builder = $this->createSiteBuilder();
        $config = BuildConfig::fromProjectRoot($projectRoot);
        $builder->build($config, true);

        $this->assertFileDoesNotExist($projectRoot . '/public/old/stale.html');
        $this->assertFileExists($projectRoot . '/public/index.html');
    }

    /**
     * Ensure draft pages are skipped by default during static builds.
     */
    public function testBuildSkipsDraftPagesByDefault(): void
    {
        $projectRoot = $this->copyFixtureToTemp('projects/with-draft');

        $builder = $this->createSiteBuilder();
        $config = BuildConfig::fromProjectRoot($projectRoot);
        $writtenFiles = $builder->build($config);

        $this->assertCount(1, $writtenFiles);
        $this->assertFileExists($projectRoot . '/public/index.html');
        $this->assertFileDoesNotExist($projectRoot . '/public/draft/index.html');
    }

    /**
     * Ensure draft pages can be included explicitly.
     */
    public function testBuildIncludesDraftPagesWhenConfigured(): void
    {
        $projectRoot = $this->copyFixtureToTemp('projects/with-draft');

        $builder = $this->createSiteBuilder();
        $config = BuildConfig::fromProjectRoot($projectRoot, true);
        $writtenFiles = $builder->build($config);

        $this->assertCount(2, $writtenFiles);
        $this->assertFileExists($projectRoot . '/public/draft/index.html');
    }

    /**
     * Ensure metadata is exposed to templates through the shared render context.
     */
    public function testRenderRequestExposesMetaDataToTemplate(): void
    {
        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/content', 0755, true);
        mkdir($projectRoot . '/templates', 0755, true);

        file_put_contents($projectRoot . '/content/index.dj', "+++\ndescription: Hello world\n+++\n# Welcome\n");
        file_put_contents($projectRoot . '/templates/page.sugar.php', '<p><?= $meta["description"] ?? "none" ?></p><?= $content |> raw() ?>');

        $builder = $this->createSiteBuilder();
        $config = BuildConfig::fromProjectRoot($projectRoot);
        $html = $builder->renderRequest($config, '/');

        $this->assertIsString($html);
        $this->assertStringContainsString('<p>Hello world</p>', $html);
    }

    /**
     * Ensure content type defaults are merged and can drive template selection.
     */
    public function testRenderRequestAppliesContentTypeDefaults(): void
    {
        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/content/blog', 0755, true);
        mkdir($projectRoot . '/templates', 0755, true);

        file_put_contents(
            $projectRoot . '/glaze.neon',
            "contentTypes:\n  blog:\n    paths:\n      - blog\n    defaults:\n      template: blog\n",
        );
        file_put_contents($projectRoot . '/content/blog/post.dj', "# Post\n");
        file_put_contents($projectRoot . '/templates/page.sugar.php', '<p class="template">default</p>');
        file_put_contents($projectRoot . '/templates/blog.sugar.php', '<p class="template">blog</p><p class="type"><?= $page->type ?></p>');

        $builder = $this->createSiteBuilder();
        $config = BuildConfig::fromProjectRoot($projectRoot, true);
        $html = $builder->renderRequest($config, '/blog/post/');

        $this->assertIsString($html);
        $this->assertStringContainsString('<p class="template">blog</p>', $html);
        $this->assertStringContainsString('<p class="type">blog</p>', $html);
        $this->assertStringNotContainsString('<p class="template">default</p>', $html);
    }

    /**
     * Ensure template context exposes collections, taxonomy, and pagination helpers.
     */
    public function testRenderRequestExposesTemplateSiteContext(): void
    {
        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/content/blog', 0755, true);
        mkdir($projectRoot . '/templates', 0755, true);

        file_put_contents(
            $projectRoot . '/content/index.dj',
            "+++\ntags:\n  - home\n+++\n# Home\n",
        );
        file_put_contents(
            $projectRoot . '/content/blog/post-a.dj',
            "+++\ntags:\n  - php\n+++\n# Post A\n",
        );
        file_put_contents(
            $projectRoot . '/content/blog/post-b.dj',
            "+++\ntags:\n  - php\n+++\n# Post B\n",
        );
        file_put_contents(
            $projectRoot . '/templates/page.sugar.php',
            '<p class="regular"><?= $this->regularPages()->count() ?></p>'
            . '<p class="section"><?= $this->section("blog")->count() ?></p>'
            . '<p class="tags"><?= $this->taxonomyTerm("tags", "php")->count() ?></p>'
            . '<?php $pager = $this->paginate($this->section("blog"), 1, 2, "/blog/"); ?>'
            . '<p class="pager-url"><?= $pager->url() ?></p>'
            . '<p class="pager-prev"><?= $pager->prevUrl() ?? "none" ?></p>'
            . '<?= $content |> raw() ?>',
        );

        $builder = $this->createSiteBuilder();
        $config = BuildConfig::fromProjectRoot($projectRoot, true);
        $html = $builder->renderRequest($config, '/blog/post-a/');

        $this->assertIsString($html);
        $this->assertStringContainsString('<p class="regular">3</p>', $html);
        $this->assertStringContainsString('<p class="section">2</p>', $html);
        $this->assertStringContainsString('<p class="tags">2</p>', $html);
        $this->assertStringContainsString('<p class="pager-url">/blog/page/2/</p>', $html);
        $this->assertStringContainsString('<p class="pager-prev">/blog/</p>', $html);
    }

    /**
     * Ensure relative image sources remain content-root based when slug is overridden.
     */
    public function testRenderRequestRewritesRelativeImageSourceWhenSlugIsOverridden(): void
    {
        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/content/blog', 0755, true);
        mkdir($projectRoot . '/templates', 0755, true);

        file_put_contents(
            $projectRoot . '/content/blog/index.dj',
            "---\nslug: /blog/asset-test-page\n---\n## Hello\n\n![test](test2.jpg)\n",
        );
        file_put_contents($projectRoot . '/content/blog/test2.jpg', 'jpg-bytes');
        file_put_contents($projectRoot . '/templates/page.sugar.php', '<?= $content |> raw() ?>');

        $builder = $this->createSiteBuilder();
        $config = BuildConfig::fromProjectRoot($projectRoot, true);
        $html = $builder->renderRequest($config, '/blog/asset-test-page/');

        $this->assertIsString($html);
        $this->assertStringContainsString('<img alt="test" src="/blog/test2.jpg">', $html);
    }

    /**
     * Ensure static build output rewrites relative image sources with slug overrides.
     */
    public function testBuildRewritesRelativeImageSourceWhenSlugIsOverridden(): void
    {
        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/content/blog', 0755, true);
        mkdir($projectRoot . '/templates', 0755, true);

        file_put_contents(
            $projectRoot . '/content/blog/index.dj',
            "---\nslug: /blog/asset-test-page\n---\n## Hello\n\n![test](test2.jpg)\n",
        );
        file_put_contents($projectRoot . '/content/blog/test2.jpg', 'jpg-bytes');
        file_put_contents($projectRoot . '/templates/page.sugar.php', '<?= $content |> raw() ?>');

        $builder = $this->createSiteBuilder();
        $config = BuildConfig::fromProjectRoot($projectRoot, true);
        $builder->build($config);

        $output = file_get_contents($projectRoot . '/public/blog/asset-test-page/index.html');
        $this->assertIsString($output);
        $this->assertStringContainsString('<img alt="test" src="/blog/test2.jpg">', $output);
        $this->assertFileExists($projectRoot . '/public/blog/test2.jpg');
    }

    /**
     * Ensure image source rewriting handles absolute, external, anchor, and dot-segment paths.
     */
    public function testRenderRequestImageSourceRewriteEdgeCases(): void
    {
        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/content/blog', 0755, true);
        mkdir($projectRoot . '/templates', 0755, true);

        file_put_contents(
            $projectRoot . '/content/blog/index.dj',
            "## Hello\n\n![](/already/absolute.jpg)\n\n![](https://example.com/ext.jpg)\n\n![](#anchor)\n\n![](//cdn.example.com/img.jpg)\n\n![](./local.jpg)\n\n![](../shared.jpg)\n",
        );
        file_put_contents($projectRoot . '/templates/page.sugar.php', '<?= $content |> raw() ?>');

        $builder = $this->createSiteBuilder();
        $config = BuildConfig::fromProjectRoot($projectRoot, true);
        $html = $builder->renderRequest($config, '/blog/');

        $this->assertIsString($html);
        $this->assertStringContainsString('src="/already/absolute.jpg"', $html);
        $this->assertStringContainsString('src="https://example.com/ext.jpg"', $html);
        $this->assertStringContainsString('src="#anchor"', $html);
        $this->assertStringContainsString('src="//cdn.example.com/img.jpg"', $html);
        $this->assertStringContainsString('src="/blog/local.jpg"', $html);
        $this->assertStringContainsString('src="/shared.jpg"', $html);
    }

    /**
     * Ensure configured basePath prefixes internal links, images, and URL template value.
     */
    public function testRenderRequestAppliesBasePathToInternalUrls(): void
    {
        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/content/blog', 0755, true);
        mkdir($projectRoot . '/templates', 0755, true);

        file_put_contents($projectRoot . '/glaze.neon', "site:\n  basePath: /docs\n");
        file_put_contents(
            $projectRoot . '/content/blog/index.dj',
            "## Hello\n\n[About](/about/)\n\n![Local](test2.jpg)\n\n[External](https://example.com)\n",
        );
        file_put_contents($projectRoot . '/content/blog/test2.jpg', 'jpg-bytes');
        file_put_contents(
            $projectRoot . '/templates/page.sugar.php',
            '<p class="url"><?= $url ?></p><?= $content |> raw() ?>',
        );

        $builder = $this->createSiteBuilder();
        $config = BuildConfig::fromProjectRoot($projectRoot, true);
        $html = $builder->renderRequest($config, '/blog/');

        $this->assertIsString($html);
        $this->assertStringContainsString('<p class="url">/docs/blog/</p>', $html);
        $this->assertStringContainsString('href="/docs/about/"', $html);
        $this->assertStringContainsString('src="/docs/blog/test2.jpg"', $html);
        $this->assertStringContainsString('href="https://example.com"', $html);
    }

    /**
     * Ensure protected resource-path helper branches behave as expected.
     */
    public function testResourcePathHelpersHandleEmptyAndDotSegments(): void
    {
        $builder = $this->createSiteBuilder();

        $empty = $this->callProtected(
            $builder,
            'toContentAbsoluteResourcePath',
            '',
            'blog/index.dj',
        );
        $normalized = $this->callProtected(
            $builder,
            'normalizePathSegments',
            'blog/./images/../cover.jpg',
        );
        $absolute = $this->callProtected($builder, 'isAbsoluteResourcePath', '/a.jpg');
        $protocolRelative = $this->callProtected($builder, 'isAbsoluteResourcePath', '//cdn/a.jpg');
        $anchor = $this->callProtected($builder, 'isAbsoluteResourcePath', '#hash');
        $scheme = $this->callProtected($builder, 'isAbsoluteResourcePath', 'https://example.com/a.jpg');

        $this->assertSame('', $empty);
        $this->assertSame('blog/cover.jpg', $normalized);
        $this->assertTrue($absolute);
        $this->assertTrue($protocolRelative);
        $this->assertTrue($anchor);
        $this->assertTrue($scheme);
    }

    /**
     * Ensure basePath URL helper handles root, suffixes, pre-prefixed, and no-prefix cases.
     */
    public function testApplyBasePathToPathHandlesVariants(): void
    {
        $builder = $this->createSiteBuilder();

        $withBasePath = new SiteConfig(basePath: '/docs');
        $withoutBasePath = new SiteConfig(basePath: null);

        $root = $this->callProtected($builder, 'applyBasePathToPath', '/', $withBasePath);
        $withSuffix = $this->callProtected($builder, 'applyBasePathToPath', '/about/?q=1#x', $withBasePath);
        $prefixed = $this->callProtected($builder, 'applyBasePathToPath', '/docs/about/', $withBasePath);
        $unchanged = $this->callProtected($builder, 'applyBasePathToPath', '/about/', $withoutBasePath);
        $externalMailto = $this->callProtected($builder, 'isExternalResourcePath', 'mailto:test@example.com');

        $this->assertSame('/docs/', $root);
        $this->assertSame('/docs/about/?q=1#x', $withSuffix);
        $this->assertSame('/docs/about/', $prefixed);
        $this->assertSame('/about/', $unchanged);
        $this->assertTrue($externalMailto);
    }

    /**
     * Ensure basePath stripping helper returns expected internal source paths.
     */
    public function testStripBasePathFromPathHandlesVariants(): void
    {
        $builder = $this->createSiteBuilder();

        $stripped = $this->callProtected($builder, 'stripBasePathFromPath', '/docs/blog/photo.jpg', new SiteConfig(basePath: '/docs'));
        $root = $this->callProtected($builder, 'stripBasePathFromPath', '/docs', new SiteConfig(basePath: '/docs'));
        $unchanged = $this->callProtected($builder, 'stripBasePathFromPath', '/blog/photo.jpg', new SiteConfig(basePath: '/docs'));

        $this->assertSame('/blog/photo.jpg', $stripped);
        $this->assertSame('/', $root);
        $this->assertSame('/blog/photo.jpg', $unchanged);
    }

    /**
     * Ensure build Glide publisher copies transformed assets into static output.
     */
    public function testPublishBuildGlideAssetCopiesFileAndReturnsPublicUrl(): void
    {
        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/public', 0755, true);
        file_put_contents($projectRoot . '/glaze.neon', "site:\n  basePath: /docs\n");

        $transformed = $projectRoot . '/tmp-transform.jpg';
        file_put_contents($transformed, 'transformed-bytes');

        $config = BuildConfig::fromProjectRoot($projectRoot, true);
        $builder = $this->createSiteBuilder();

        $url = $this->callProtected(
            $builder,
            'publishBuildGlideAsset',
            $transformed,
            '/images/hero.jpg',
            'w=100&h=50',
            $config,
        );

        $hash = hash('xxh3', '/images/hero.jpg?w=100&h=50');
        $expectedFile = $projectRoot . '/public/_glide/' . $hash . '.jpg';

        $this->assertSame('/docs/_glide/' . $hash . '.jpg', $url);
        $this->assertFileExists($expectedFile);
        $this->assertSame('transformed-bytes', file_get_contents($expectedFile));
    }

    /**
     * Ensure build Glide publisher keeps hashed filename when source extension is missing.
     */
    public function testPublishBuildGlideAssetUsesHashedNameWhenExtensionMissing(): void
    {
        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/public', 0755, true);

        $transformed = $projectRoot . '/tmp-transform.bin';
        file_put_contents($transformed, 'transformed-bytes');

        $config = BuildConfig::fromProjectRoot($projectRoot, true);
        $builder = $this->createSiteBuilder();

        $url = $this->callProtected(
            $builder,
            'publishBuildGlideAsset',
            $transformed,
            '/images/hero',
            'w=100&h=50',
            $config,
        );

        $hash = hash('xxh3', '/images/hero?w=100&h=50');
        $expectedFile = $projectRoot . '/public/_glide/' . $hash;

        $this->assertSame('/_glide/' . $hash, $url);
        $this->assertFileExists($expectedFile);
        $this->assertSame('transformed-bytes', file_get_contents($expectedFile));
    }

    /**
     * Ensure build-time Glide rewriting updates image src and publishes transformed assets.
     */
    public function testRewriteBuildGlideImageSourcesRewritesQueryImageUrls(): void
    {
        if (!function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD extension is required for Glide image transformation tests.');
        }

        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/content/images', 0755, true);
        mkdir($projectRoot . '/public', 0755, true);

        $image = imagecreatetruecolor(2, 2);
        $this->assertNotFalse($image);
        $fillColor = imagecolorallocate($image, 0, 0, 0);
        $this->assertIsInt($fillColor);
        imagefill($image, 0, 0, $fillColor);
        imagepng($image, $projectRoot . '/content/images/hero.png');

        $builder = $this->createSiteBuilder();
        $config = BuildConfig::fromProjectRoot($projectRoot, true);

        $html = '<img src="/images/hero.png?w=100&h=50"><img src="/images/plain.png"><img src="https://example.com/img.png?w=100">';

        $rewritten = $this->callProtected($builder, 'rewriteBuildGlideImageSources', $html, $config);
        $this->assertIsString($rewritten);

        $hash = hash('xxh3', '/images/hero.png?w=100&h=50');
        $this->assertStringContainsString('/_glide/' . $hash . '.png', $rewritten);
        $this->assertStringContainsString('/images/plain.png', $rewritten);
        $this->assertStringContainsString('https://example.com/img.png?w=100', $rewritten);
        $this->assertFileExists($projectRoot . '/public/_glide/' . $hash . '.png');
    }

    /**
     * Ensure live rendering enables Sugar Vite extension and uses runtime Vite URL.
     */
    public function testRenderRequestRendersViteDevelopmentTagsWhenEnabled(): void
    {
        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/content', 0755, true);
        mkdir($projectRoot . '/templates', 0755, true);

        file_put_contents(
            $projectRoot . '/glaze.neon',
            "devServer:\n  vite:\n    enabled: true\n",
        );
        file_put_contents($projectRoot . '/content/index.dj', "# Home\n");
        file_put_contents(
            $projectRoot . '/templates/page.sugar.php',
            '<s-template s:vite="\'resources/js/app.ts\'" /><?= $content |> raw() ?>',
        );

        $builder = $this->createSiteBuilder();
        $config = BuildConfig::fromProjectRoot($projectRoot, true);

        $originalViteEnabled = getenv('GLAZE_VITE_ENABLED');
        $originalViteUrl = getenv('GLAZE_VITE_URL');

        try {
            putenv('GLAZE_VITE_ENABLED=1');
            putenv('GLAZE_VITE_URL=http://127.0.0.1:5179');

            $html = $builder->renderRequest($config, '/');
        } finally {
            $this->restoreVariable('GLAZE_VITE_ENABLED', $originalViteEnabled);
            $this->restoreVariable('GLAZE_VITE_URL', $originalViteUrl);
        }

        $this->assertIsString($html);
        $this->assertStringContainsString('http://127.0.0.1:5179/@vite/client', $html);
        $this->assertStringContainsString('http://127.0.0.1:5179/resources/js/app.ts', $html);
    }

    /**
     * Ensure static builds enable Sugar Vite extension and render tags from manifest assets.
     */
    public function testBuildRendersViteProductionTagsWhenEnabled(): void
    {
        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/content', 0755, true);
        mkdir($projectRoot . '/templates', 0755, true);
        mkdir($projectRoot . '/public/assets/.vite', 0755, true);

        file_put_contents(
            $projectRoot . '/glaze.neon',
            "build:\n  vite:\n    enabled: true\n",
        );
        file_put_contents($projectRoot . '/content/index.dj', "# Home\n");
        file_put_contents(
            $projectRoot . '/templates/page.sugar.php',
            '<s-template s:vite="\'resources/js/app.ts\'" /><?= $content |> raw() ?>',
        );
        file_put_contents(
            $projectRoot . '/public/assets/.vite/manifest.json',
            json_encode([
                'resources/js/app.ts' => [
                    'file' => 'assets/app-abc123.js',
                    'css' => ['assets/app-def456.css'],
                ],
            ], JSON_THROW_ON_ERROR),
        );

        $builder = $this->createSiteBuilder();
        $config = BuildConfig::fromProjectRoot($projectRoot, true);
        $builder->build($config);

        $output = file_get_contents($projectRoot . '/public/index.html');
        $this->assertIsString($output);
        $this->assertStringContainsString('/assets/assets/app-def456.css', $output);
        $this->assertStringContainsString('/assets/assets/app-abc123.js', $output);
    }

    /**
     * Ensure static builds apply site basePath to Vite production asset tags.
     */
    public function testBuildRendersViteProductionTagsWithBasePathWhenConfigured(): void
    {
        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/content', 0755, true);
        mkdir($projectRoot . '/templates', 0755, true);
        mkdir($projectRoot . '/public/assets/.vite', 0755, true);

        file_put_contents(
            $projectRoot . '/glaze.neon',
            "site:\n  basePath: /glaze\nbuild:\n  vite:\n    enabled: true\n",
        );
        file_put_contents($projectRoot . '/content/index.dj', "# Home\n");
        file_put_contents(
            $projectRoot . '/templates/page.sugar.php',
            '<s-template s:vite="\'resources/js/app.ts\'" /><?= $content |> raw() ?>',
        );
        file_put_contents(
            $projectRoot . '/public/assets/.vite/manifest.json',
            json_encode([
                'resources/js/app.ts' => [
                    'file' => 'assets/app-abc123.js',
                    'css' => ['assets/app-def456.css'],
                ],
            ], JSON_THROW_ON_ERROR),
        );

        $builder = $this->createSiteBuilder();
        $config = BuildConfig::fromProjectRoot($projectRoot, true);
        $builder->build($config);

        $output = file_get_contents($projectRoot . '/public/index.html');
        $this->assertIsString($output);
        $this->assertStringContainsString('/glaze/assets/assets/app-def456.css', $output);
        $this->assertStringContainsString('/glaze/assets/assets/app-abc123.js', $output);
    }

    /**
     * Invoke a protected method using scope-bound closure.
     *
     * @param object $object Object to invoke method on.
     * @param string $method Protected method name.
     * @param mixed ...$arguments Method arguments.
     */
    protected function callProtected(object $object, string $method, mixed ...$arguments): mixed
    {
        $invoker = Closure::bind(
            function (string $method, mixed ...$arguments): mixed {
                return $this->{$method}(...$arguments);
            },
            $object,
            $object::class,
        );

        return $invoker($method, ...$arguments);
    }

    /**
     * Restore an environment variable to its previous value.
     *
     * @param string $name Variable name.
     * @param string|false $value Previous variable value from getenv().
     */
    protected function restoreVariable(string $name, string|false $value): void
    {
        if ($value === false) {
            putenv($name);

            return;
        }

        putenv($name . '=' . $value);
    }

    /**
     * Create a site builder instance with concrete dependencies.
     */
    protected function createSiteBuilder(): SiteBuilder
    {
        /** @var \Glaze\Build\SiteBuilder */
        return $this->service(SiteBuilder::class);
    }
}
