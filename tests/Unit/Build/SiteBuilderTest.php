<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Build;

use Glaze\Build\Event\BuildCompletedEvent;
use Glaze\Build\Event\BuildEvent;
use Glaze\Build\Event\BuildStartedEvent;
use Glaze\Build\Event\ContentDiscoveredEvent;
use Glaze\Build\Event\EventDispatcher;
use Glaze\Build\Event\PageRenderedEvent;
use Glaze\Build\Event\PageWrittenEvent;
use Glaze\Build\SiteBuilder;
use Glaze\Config\BuildConfig;
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
            . '<?php $blogSection = $this->section("blog"); ?>'
            . '<p class="section"><?= $blogSection ? $blogSection->count() : 0 ?></p>'
            . '<p class="tags"><?= $this->taxonomyTerm("tags", "php")->count() ?></p>'
            . '<?php $pager = $this->paginate($blogSection ?? [], 1, 2, "/blog/"); ?>'
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
        mkdir($projectRoot . '/public/.vite', 0755, true);

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
            $projectRoot . '/public/.vite/manifest.json',
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
        $this->assertStringContainsString('/assets/app-def456.css', $output);
        $this->assertStringContainsString('/assets/app-abc123.js', $output);
    }

    /**
     * Ensure static builds apply site basePath to Vite production asset tags.
     */
    public function testBuildRendersViteProductionTagsWithBasePathWhenConfigured(): void
    {
        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/content', 0755, true);
        mkdir($projectRoot . '/templates', 0755, true);
        mkdir($projectRoot . '/public/.vite', 0755, true);

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
            $projectRoot . '/public/.vite/manifest.json',
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
        $this->assertStringContainsString('/glaze/assets/app-def456.css', $output);
        $this->assertStringContainsString('/glaze/assets/app-abc123.js', $output);
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

    // ------------------------------------------------------------------ //
    // Event system
    // ------------------------------------------------------------------ //

    /**
     * Ensure the BuildStarted event is dispatched at the start of a build.
     */
    public function testBuildDispatchesBuildStartedEvent(): void
    {
        $projectRoot = $this->copyFixtureToTemp('projects/basic');
        $config = BuildConfig::fromProjectRoot($projectRoot);
        $dispatcher = new EventDispatcher();
        $received = [];

        $dispatcher->on(BuildEvent::BuildStarted, function (BuildStartedEvent $event) use (&$received): void {
            $received[] = $event->config->projectRoot;
        });

        $this->createSiteBuilder()->build($config, dispatcher: $dispatcher);

        $this->assertCount(1, $received);
        $this->assertSame($projectRoot, $received[0]);
    }

    /**
     * Ensure a ContentDiscovered listener can inject an additional virtual page.
     */
    public function testBuildContentDiscoveredPagesAreMutable(): void
    {
        $projectRoot = $this->copyFixtureToTemp('projects/basic');
        $config = BuildConfig::fromProjectRoot($projectRoot);
        $dispatcher = new EventDispatcher();

        $dispatcher->on(BuildEvent::ContentDiscovered, function (ContentDiscoveredEvent $event): void {
            // Remove all discovered pages — build should complete with zero rendered pages.
            $event->pages = [];
        });

        $writtenFiles = $this->createSiteBuilder()->build($config, dispatcher: $dispatcher);

        $this->assertSame([], $writtenFiles);
    }

    /**
     * Ensure a PageRendered listener can overwrite the HTML before it is written to disk.
     */
    public function testBuildPageRenderedHtmlIsMutable(): void
    {
        $projectRoot = $this->copyFixtureToTemp('projects/basic');
        $config = BuildConfig::fromProjectRoot($projectRoot);
        $dispatcher = new EventDispatcher();

        $dispatcher->on(BuildEvent::PageRendered, function (PageRenderedEvent $event): void {
            $event->html = '<!-- injected -->';
        });

        $this->createSiteBuilder()->build($config, dispatcher: $dispatcher);

        $output = file_get_contents($projectRoot . '/public/index.html');
        $this->assertSame('<!-- injected -->', $output);
    }

    /**
     * Ensure a PageWritten event is dispatched for every rendered page.
     */
    public function testBuildDispatchesPageWrittenForEachPage(): void
    {
        $projectRoot = $this->copyFixtureToTemp('projects/basic');
        $config = BuildConfig::fromProjectRoot($projectRoot);
        $dispatcher = new EventDispatcher();
        $destinations = [];

        $dispatcher->on(BuildEvent::PageWritten, function (PageWrittenEvent $event) use (&$destinations): void {
            $destinations[] = $event->destination;
        });

        $writtenFiles = $this->createSiteBuilder()->build($config, dispatcher: $dispatcher);

        $this->assertSameSize($writtenFiles, $destinations);
        foreach ($destinations as $dest) {
            $this->assertContains($dest, $writtenFiles);
        }
    }

    /**
     * Ensure the BuildCompleted event is dispatched after build with a positive duration.
     */
    public function testBuildDispatchesBuildCompletedWithDuration(): void
    {
        $projectRoot = $this->copyFixtureToTemp('projects/basic');
        $config = BuildConfig::fromProjectRoot($projectRoot);
        $dispatcher = new EventDispatcher();
        $completedEvents = [];

        $dispatcher->on(BuildEvent::BuildCompleted, function (BuildCompletedEvent $event) use (&$completedEvents): void {
            $completedEvents[] = $event;
        });

        $writtenFiles = $this->createSiteBuilder()->build($config, dispatcher: $dispatcher);

        $this->assertCount(1, $completedEvents);
        $this->assertGreaterThan(0.0, $completedEvents[0]->duration);
        $this->assertSame($writtenFiles, $completedEvents[0]->writtenFiles);
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
