<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Build;

use Closure;
use Glaze\Build\SiteBuilder;
use Glaze\Config\BuildConfig;
use Glaze\Config\SiteConfig;
use Glaze\Tests\Helper\FilesystemTestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Tests for site build orchestration.
 */
final class SiteBuilderTest extends TestCase
{
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

        $builder = new SiteBuilder();
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

        $builder = new SiteBuilder();
        $config = BuildConfig::fromProjectRoot($projectRoot);
        $builder->build($config);

        $this->assertFileExists($projectRoot . '/public/blog/my-post/photo.png');
        $this->assertSame('png-bytes', file_get_contents($projectRoot . '/public/blog/my-post/photo.png'));
    }

    /**
     * Ensure request rendering reuses the build rendering pipeline.
     */
    public function testRenderRequestReturnsHtmlForKnownPath(): void
    {
        $projectRoot = $this->copyFixtureToTemp('projects/basic');

        $builder = new SiteBuilder();
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

        $builder = new SiteBuilder();
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

        $builder = new SiteBuilder();
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

        $builder = new SiteBuilder();
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

        $builder = new SiteBuilder();
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

        $builder = new SiteBuilder();
        $config = BuildConfig::fromProjectRoot($projectRoot);
        $html = $builder->renderRequest($config, '/');

        $this->assertIsString($html);
        $this->assertStringContainsString('<p>Hello world</p>', $html);
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

        $builder = new SiteBuilder();
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

        $builder = new SiteBuilder();
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

        $builder = new SiteBuilder();
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

        $builder = new SiteBuilder();
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

        $builder = new SiteBuilder();
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
        $builder = new SiteBuilder();

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
        $builder = new SiteBuilder();

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
}
