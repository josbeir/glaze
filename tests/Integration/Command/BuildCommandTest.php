<?php
declare(strict_types=1);

namespace Glaze\Tests\Integration\Command;

use Glaze\Tests\Helper\IntegrationCommandTestCase;
use RuntimeException;

/**
 * Integration tests for the build command.
 */
final class BuildCommandTest extends IntegrationCommandTestCase
{
    /**
     * Ensure build command generates output from fixture content.
     */
    public function testBuildCommandGeneratesStaticOutput(): void
    {
        $projectRoot = $this->copyFixtureToTemp('projects/basic');

        $this->exec(sprintf('build --root "%s"', $projectRoot));

        $this->assertExitCode(0);
        $this->assertOutputContains('Glaze version');
        $this->assertOutputContains('Build complete: 1 page(s) in ');
        $this->assertFileExists($projectRoot . '/public/index.html');
    }

    /**
     * Ensure drafts are excluded by default and included with --drafts.
     */
    public function testBuildCommandDraftFiltering(): void
    {
        $projectRoot = $this->copyFixtureToTemp('projects/with-draft');

        $this->exec(sprintf('build --root "%s"', $projectRoot));

        $this->assertExitCode(0);
        $this->assertOutputContains('Build complete: 1 page(s) in ');
        $this->assertFileDoesNotExist($projectRoot . '/public/draft/index.html');

        $this->exec(sprintf('build --root "%s" --drafts', $projectRoot));

        $this->assertExitCode(0);
        $this->assertOutputContains('Build complete: 2 page(s) in ');
        $this->assertFileExists($projectRoot . '/public/draft/index.html');
    }

    /**
     * Ensure template context helpers render collection, taxonomy, and pager values.
     */
    public function testBuildCommandRendersTemplateContextFunctions(): void
    {
        $projectRoot = $this->copyFixtureToTemp('projects/template-context');

        $this->exec(sprintf('build --root "%s"', $projectRoot));

        $this->assertExitCode(0);
        $this->assertOutputContains('Build complete: 3 page(s) in ');

        $output = file_get_contents($projectRoot . '/public/blog/post-a/index.html');
        $this->assertIsString($output);
        $this->assertStringContainsString('<p class="regular">3</p>', $output);
        $this->assertStringContainsString('<p class="section">2</p>', $output);
        $this->assertStringContainsString('<p class="tag-php">2</p>', $output);
        $this->assertStringContainsString('<p class="pager-url">/blog/page/2/</p>', $output);
        $this->assertStringContainsString('<p class="prev">none</p>', $output);
        $this->assertStringContainsString('<p class="next">blog/post-b</p>', $output);
    }

    /**
     * Ensure build command renders content asset helpers and copies assets from fixture project.
     */
    public function testBuildCommandRendersTemplateAssetHelpersAndCopiesAssets(): void
    {
        $projectRoot = $this->copyFixtureToTemp('projects/template-assets');

        $this->exec(sprintf('build --root "%s"', $projectRoot));

        $this->assertExitCode(0);
        $this->assertOutputContains('Build complete: 4 page(s) in ');

        $output = file_get_contents($projectRoot . '/public/blog/post-a/index.html');
        $this->assertIsString($output);
        $this->assertStringContainsString('<p class="assets-root-count">1</p>', $output);
        $this->assertStringContainsString('<p class="assets-root-first">/docs/shared/logo.svg</p>', $output);
        $this->assertStringContainsString('<p class="section-direct-count">1</p>', $output);
        $this->assertStringContainsString('<p class="section-recursive-count">4</p>', $output);
        $this->assertStringContainsString('<p class="section-recursive-first">/docs/blog/gallery/a.jpg</p>', $output);
        $this->assertStringContainsString('<p class="section-recursive-last">/docs/blog/post-a/detail.png</p>', $output);
        $this->assertStringContainsString('<p class="current-page-gallery-first">blog/post-a/detail.png</p>', $output);
        $this->assertStringContainsString('<p class="post-a-gallery-first">blog/post-a/detail.png</p>', $output);
        $this->assertStringContainsString('<p class="loop-gallery-count">2</p>', $output);
        $this->assertStringContainsString('<p class="collection-take-reverse">cover.png</p>', $output);

        $this->assertFileExists($projectRoot . '/public/shared/logo.svg');
        $this->assertFileExists($projectRoot . '/public/blog/cover.png');
        $this->assertFileExists($projectRoot . '/public/blog/gallery/a.jpg');
        $this->assertFileExists($projectRoot . '/public/blog/post-a/detail.png');
        $this->assertFileExists($projectRoot . '/public/blog/post-b/detail.jpg');
    }

    /**
     * Ensure custom-taxonomies fixture uses custom glaze configuration for root taxonomies.
     */
    public function testBuildCommandCustomTaxonomiesFixtureWithCustomTaxonomyConfig(): void
    {
        $projectRoot = $this->copyFixtureToTemp('projects/custom-taxonomies');

        $this->exec(sprintf('build --root "%s"', $projectRoot));

        $this->assertExitCode(0);
        $this->assertOutputContains('Build complete: 2 page(s) in ');
        $this->assertFileExists($projectRoot . '/public/blog/post-a/index.html');

        $output = file_get_contents($projectRoot . '/public/blog/post-a/index.html');
        $this->assertIsString($output);
        $this->assertStringContainsString('<p class="tags-php">2</p>', $output);
        $this->assertStringContainsString('<p class="categories-docs">2</p>', $output);
    }

    /**
     * Ensure invalid project configuration is reported as build command error.
     */
    public function testBuildCommandFailsForInvalidProjectConfig(): void
    {
        $projectRoot = $this->createTempDirectory();
        file_put_contents($projectRoot . '/glaze.neon', "taxonomies:\n  - tags: [\n");

        $this->exec(sprintf('build --root "%s"', $projectRoot));

        $this->assertExitCode(1);
        $this->assertErrorContains('Invalid project configuration');
    }

    /**
     * Ensure frontmatter slug override and content asset handling work end-to-end.
     */
    public function testBuildCommandHandlesFrontmatterSlugAndAssets(): void
    {
        $projectRoot = $this->copyFixtureToTemp('projects/frontmatter-slug-assets');

        $this->exec(sprintf('build --root "%s"', $projectRoot));

        $this->assertExitCode(0);
        $this->assertOutputContains('Build complete: 1 page(s) in ');
        $this->assertFileExists($projectRoot . '/public/blog/asset-test-page/index.html');
        $this->assertFileExists($projectRoot . '/public/blog/test2.jpg');

        $output = file_get_contents($projectRoot . '/public/blog/asset-test-page/index.html');
        $this->assertIsString($output);
        $this->assertStringContainsString('<img alt="test" src="/blog/test2.jpg">', $output);
    }

    /**
     * Ensure site config defaults and page-level meta overrides are rendered.
     */
    public function testBuildCommandRendersSiteConfigDefaultsAndPageOverrides(): void
    {
        $projectRoot = $this->copyFixtureToTemp('projects/site-config');

        $this->exec(sprintf('build --root "%s"', $projectRoot));

        $this->assertExitCode(0);
        $this->assertOutputContains('Build complete: 2 page(s) in ');

        $indexOutput = file_get_contents($projectRoot . '/public/index.html');
        $this->assertIsString($indexOutput);
        $this->assertStringContainsString('<p class="site-title">Example Site</p>', $indexOutput);
        $this->assertStringContainsString('<p class="meta-description">Default site description</p>', $indexOutput);
        $this->assertStringContainsString('<p class="meta-robots">index,follow</p>', $indexOutput);

        $aboutOutput = file_get_contents($projectRoot . '/public/about/index.html');
        $this->assertIsString($aboutOutput);
        $this->assertStringContainsString('<p class="site-title">Example Site</p>', $aboutOutput);
        $this->assertStringContainsString('<p class="meta-description">About page description</p>', $aboutOutput);
        $this->assertStringContainsString('<p class="meta-robots">noindex</p>', $aboutOutput);
    }

    /**
     * Ensure build rewrites Glide image query URLs to static transformed assets.
     */
    public function testBuildCommandBuildsStaticGlideImageAssets(): void
    {
        if (!function_exists('imagecreatetruecolor') || !function_exists('imagejpeg')) {
            $this->markTestSkipped('GD image functions are required for Glide build tests.');
        }

        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/content/images', 0755, true);
        mkdir($projectRoot . '/templates', 0755, true);

        $this->createJpegImage($projectRoot . '/content/images/hero.jpg');
        file_put_contents(
            $projectRoot . '/content/index.dj',
            "# Home\n\n![Hero](images/hero.jpg?h=50&w=100&fit=crop)\n",
        );
        file_put_contents(
            $projectRoot . '/templates/page.sugar.php',
            '<?= $content |> raw() ?>',
        );

        $this->exec(sprintf('build --root "%s"', $projectRoot));

        $this->assertExitCode(0);
        $output = file_get_contents($projectRoot . '/public/index.html');
        $this->assertIsString($output);
        $this->assertMatchesRegularExpression('/src="\/_glide\/[a-f0-9]+\.jpg"/', $output);

        preg_match('/src="(\/_glide\/[a-f0-9]+\.jpg)"/', $output, $matches);
        $this->assertArrayHasKey(1, $matches);
        $transformedRelativePath = $matches[1];
        $this->assertFileExists($projectRoot . '/public' . $transformedRelativePath);
    }

    /**
     * Ensure build command copies static directory assets into public output.
     */
    public function testBuildCommandCopiesStaticAssets(): void
    {
        $projectRoot = $this->copyFixtureToTemp('projects/basic');
        mkdir($projectRoot . '/static/fonts', 0755, true);

        file_put_contents($projectRoot . '/static/robots.txt', "User-agent: *\nAllow: /\n");
        file_put_contents($projectRoot . '/static/fonts/site.woff2', 'woff2-binary');

        $this->exec(sprintf('build --root "%s"', $projectRoot));

        $this->assertExitCode(0);
        $this->assertFileExists($projectRoot . '/public/robots.txt');
        $this->assertFileExists($projectRoot . '/public/fonts/site.woff2');
        $this->assertSame('woff2-binary', file_get_contents($projectRoot . '/public/fonts/site.woff2'));
    }

    /**
     * Ensure build output includes Phiki-highlighted code blocks by default.
     */
    public function testBuildCommandRendersHighlightedCodeBlocksByDefault(): void
    {
        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/content', 0755, true);
        mkdir($projectRoot . '/templates', 0755, true);

        file_put_contents(
            $projectRoot . '/content/index.dj',
            "```php\necho 1;\n```\n",
        );
        file_put_contents(
            $projectRoot . '/templates/page.sugar.php',
            '<?= $content |> raw() ?>',
        );

        $this->exec(sprintf('build --root "%s"', $projectRoot));

        $this->assertExitCode(0);
        $output = file_get_contents($projectRoot . '/public/index.html');
        $this->assertIsString($output);
        $this->assertStringContainsString('class="phiki', $output);
        $this->assertStringContainsString('language-php', $output);
    }

    /**
     * Ensure build output uses default Djot code rendering when highlighting is disabled.
     */
    public function testBuildCommandCanDisableCodeHighlighting(): void
    {
        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/content', 0755, true);
        mkdir($projectRoot . '/templates', 0755, true);

        file_put_contents(
            $projectRoot . '/glaze.neon',
            "djot:\n  codeHighlighting:\n    enabled: false\n",
        );
        file_put_contents(
            $projectRoot . '/content/index.dj',
            "```php\necho 1;\n```\n",
        );
        file_put_contents(
            $projectRoot . '/templates/page.sugar.php',
            '<?= $content |> raw() ?>',
        );

        $this->exec(sprintf('build --root "%s"', $projectRoot));

        $this->assertExitCode(0);
        $output = file_get_contents($projectRoot . '/public/index.html');
        $this->assertIsString($output);
        $this->assertStringNotContainsString('class="phiki', $output);
        $this->assertStringContainsString('<pre><code class="language-php">', $output);
    }

    /**
     * Ensure build output includes Phiki multi-theme metadata when configured.
     */
    public function testBuildCommandCanRenderCodeHighlightingWithNamedThemes(): void
    {
        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/content', 0755, true);
        mkdir($projectRoot . '/templates', 0755, true);

        file_put_contents(
            $projectRoot . '/glaze.neon',
            "djot:\n  codeHighlighting:\n    themes:\n      dark: github-dark\n      light: github-light\n",
        );
        file_put_contents(
            $projectRoot . '/content/index.dj',
            "```php\necho 1;\n```\n",
        );
        file_put_contents(
            $projectRoot . '/templates/page.sugar.php',
            '<?= $content |> raw() ?>',
        );

        $this->exec(sprintf('build --root "%s"', $projectRoot));

        $this->assertExitCode(0);
        $output = file_get_contents($projectRoot . '/public/index.html');
        $this->assertIsString($output);
        $this->assertStringContainsString('class="phiki', $output);
        $this->assertStringContainsString('phiki-themes', $output);
        $this->assertStringContainsString('github-light', $output);
        $this->assertStringContainsString('--phiki-light-background-color', $output);
    }

    /**
     * Ensure build command renders Djot code-group blocks as tabbed code snippets.
     */
    public function testBuildCommandRendersCodeGroupBlocksAsTabs(): void
    {
        $projectRoot = $this->copyFixtureToTemp('projects/code-group');

        $this->exec(sprintf('build --root "%s"', $projectRoot));

        $this->assertExitCode(0);
        $this->assertOutputContains('Build complete: 1 page(s) in ');

        $output = file_get_contents($projectRoot . '/public/index.html');
        $this->assertIsString($output);
        $this->assertStringContainsString('class="glaze-code-group"', $output);
        $this->assertStringContainsString('class="glaze-code-group-tab"', $output);
        $this->assertStringContainsString('role="tablist"', $output);
        $this->assertStringContainsString('aria-label="config.js"', $output);
        $this->assertStringContainsString('aria-label="config.ts"', $output);
        $this->assertStringContainsString('class="phiki', $output);
        $this->assertStringContainsString('data-language="javascript"', $output);
        $this->assertStringContainsString('data-language="typescript"', $output);
    }

    /**
     * Build a small JPEG image file for Glide integration tests.
     *
     * @param string $path Destination file path.
     */
    protected function createJpegImage(string $path): void
    {
        $image = imagecreatetruecolor(4, 4);
        if ($image === false) {
            throw new RuntimeException('Unable to create JPEG test image.');
        }

        $color = imagecolorallocate($image, 10, 20, 30);
        if ($color === false) {
            throw new RuntimeException('Unable to allocate JPEG test image color.');
        }

        imagefilledrectangle($image, 0, 0, 3, 3, $color);
        imagejpeg($image, $path, 90);
    }

    /**
     * Ensure frontmatter template override selects the configured custom template.
     */
    public function testBuildCommandSupportsFrontmatterTemplateOverride(): void
    {
        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/content', 0755, true);
        mkdir($projectRoot . '/templates', 0755, true);

        file_put_contents(
            $projectRoot . '/content/index.dj',
            "---\ntitle: Home\ntemplate: landing\n---\n# Hello\n",
        );
        file_put_contents(
            $projectRoot . '/templates/page.sugar.php',
            '<p class="template">default</p><?= $content |> raw() ?>',
        );
        file_put_contents(
            $projectRoot . '/templates/landing.sugar.php',
            '<p class="template">landing</p><?= $content |> raw() ?>',
        );

        $this->exec(sprintf('build --root "%s"', $projectRoot));

        $this->assertExitCode(0);
        $output = file_get_contents($projectRoot . '/public/index.html');
        $this->assertIsString($output);
        $this->assertStringContainsString('<p class="template">landing</p>', $output);
        $this->assertStringNotContainsString('<p class="template">default</p>', $output);
    }

    /**
     * Ensure build command can run an additional Vite build process.
     */
    public function testBuildCommandCanRunViteBuildProcess(): void
    {
        $projectRoot = $this->copyFixtureToTemp('projects/basic');
        $viteScriptPath = $projectRoot . '/vite-build.php';

        file_put_contents(
            $viteScriptPath,
            "<?php\ndeclare(strict_types=1);\nif (!is_dir('public')) {\n    mkdir('public', 0755, true);\n}\nfile_put_contents('public/vite-build.txt', 'vite-built' . PHP_EOL);\n",
        );

        $this->exec(sprintf(
            'build --root "%s" --vite --vite-command "php vite-build.php"',
            $projectRoot,
        ));

        $this->assertExitCode(0);
        $this->assertOutputContains('<success>✓ done</success> <info>[Vite]</info> Running Vite build...');
        $this->assertFileExists($projectRoot . '/public/vite-build.txt');
        $this->assertSame('vite-built' . PHP_EOL, file_get_contents($projectRoot . '/public/vite-build.txt'));
    }

    /**
     * Ensure Vite build failures are surfaced as command errors.
     */
    public function testBuildCommandReportsViteBuildFailure(): void
    {
        $projectRoot = $this->copyFixtureToTemp('projects/basic');
        $viteScriptPath = $projectRoot . '/vite-build-fail.php';

        file_put_contents(
            $viteScriptPath,
            "<?php\ndeclare(strict_types=1);\nfwrite(STDERR, 'vite failed');\nexit(1);\n",
        );

        $this->exec(sprintf(
            'build --root "%s" --vite --vite-command "php vite-build-fail.php"',
            $projectRoot,
        ));

        $this->assertExitCode(1);
        $this->assertErrorContains('Failed to run Vite build command');
    }

    /**
     * Ensure build.clean configuration enables output cleanup by default.
     */
    public function testBuildCommandSupportsCleanDefaultFromConfiguration(): void
    {
        $projectRoot = $this->copyFixtureToTemp('projects/basic');
        mkdir($projectRoot . '/public', 0755, true);
        file_put_contents($projectRoot . '/public/stale.txt', 'stale');
        file_put_contents($projectRoot . '/glaze.neon', "build:\n  clean: true\n");

        $this->exec(sprintf('build --root "%s"', $projectRoot));

        $this->assertExitCode(0);
        $this->assertFileDoesNotExist($projectRoot . '/public/stale.txt');
    }

    /**
     * Ensure output cleanup is disabled by default when build.clean is not configured.
     */
    public function testBuildCommandDoesNotCleanOutputByDefault(): void
    {
        $projectRoot = $this->copyFixtureToTemp('projects/basic');
        mkdir($projectRoot . '/public', 0755, true);
        file_put_contents($projectRoot . '/public/stale.txt', 'stale');

        $this->exec(sprintf('build --root "%s"', $projectRoot));

        $this->assertExitCode(0);
        $this->assertFileExists($projectRoot . '/public/stale.txt');
    }

    /**
     * Ensure build.clean false disables output cleanup.
     */
    public function testBuildCommandSupportsCleanDisableFromConfiguration(): void
    {
        $projectRoot = $this->copyFixtureToTemp('projects/basic');
        mkdir($projectRoot . '/public', 0755, true);
        file_put_contents($projectRoot . '/public/stale.txt', 'stale');
        file_put_contents($projectRoot . '/glaze.neon', "build:\n  clean: false\n");

        $this->exec(sprintf('build --root "%s"', $projectRoot));

        $this->assertExitCode(0);
        $this->assertFileExists($projectRoot . '/public/stale.txt');
    }

    /**
     * Ensure --noclean disables cleanup even when build.clean is enabled in config.
     */
    public function testBuildCommandNoCleanOptionOverridesConfigurationCleanDefault(): void
    {
        $projectRoot = $this->copyFixtureToTemp('projects/basic');
        mkdir($projectRoot . '/public', 0755, true);
        file_put_contents($projectRoot . '/public/stale.txt', 'stale');
        file_put_contents($projectRoot . '/glaze.neon', "build:\n  clean: true\n");

        $this->exec(sprintf('build --root "%s" --noclean', $projectRoot));

        $this->assertExitCode(0);
        $this->assertFileExists($projectRoot . '/public/stale.txt');
    }

    /**
     * Ensure verbose output reports removed files when orphaned page outputs are pruned.
     */
    public function testBuildCommandVerboseReportsRemovedPrunedFiles(): void
    {
        $projectRoot = $this->copyFixtureToTemp('projects/basic');
        mkdir($projectRoot . '/content/docs', 0755, true);
        file_put_contents($projectRoot . '/content/docs/second.dj', "# Second\n");

        $this->exec(sprintf('build --root "%s"', $projectRoot));
        $this->assertExitCode(0);
        $this->assertFileExists($projectRoot . '/public/docs/second/index.html');

        unlink($projectRoot . '/content/docs/second.dj');

        $this->exec(sprintf('build --root "%s" --verbose', $projectRoot));

        $this->assertExitCode(0);
        $this->assertOutputContains('<error>removed</error> public/docs/second/index.html');
        $this->assertFileDoesNotExist($projectRoot . '/public/docs/second/index.html');
    }

    /**
     * Ensure verbose output reports removed files when orphaned content assets are pruned.
     */
    public function testBuildCommandVerboseReportsRemovedPrunedContentAssets(): void
    {
        $projectRoot = $this->copyFixtureToTemp('projects/basic');
        file_put_contents($projectRoot . '/content/my-image.png', 'img-bytes');

        $this->exec(sprintf('build --root "%s"', $projectRoot));
        $this->assertExitCode(0);
        $this->assertFileExists($projectRoot . '/public/my-image.png');

        unlink($projectRoot . '/content/my-image.png');

        $this->exec(sprintf('build --root "%s" --verbose', $projectRoot));

        $this->assertExitCode(0);
        $this->assertOutputContains('<error>removed</error> public/my-image.png');
        $this->assertFileDoesNotExist($projectRoot . '/public/my-image.png');
    }

    /**
     * Ensure build.drafts configuration enables draft rendering by default.
     */
    public function testBuildCommandSupportsDraftsDefaultFromConfiguration(): void
    {
        $projectRoot = $this->copyFixtureToTemp('projects/with-draft');
        file_put_contents($projectRoot . '/glaze.neon', "build:\n  drafts: true\n");

        $this->exec(sprintf('build --root "%s"', $projectRoot));

        $this->assertExitCode(0);
        $this->assertOutputContains('Build complete: 2 page(s) in ');
        $this->assertFileExists($projectRoot . '/public/draft/index.html');
    }

    /**
     * Ensure Vite build runs before page rendering when template uses s:vite in production mode.
     */
    public function testBuildCommandRunsConfiguredViteBuildBeforePageRendering(): void
    {
        $projectRoot = $this->copyFixtureToTemp('projects/basic');
        $viteScriptPath = $projectRoot . '/vite-build-manifest.php';

        file_put_contents(
            $projectRoot . '/templates/page.sugar.php',
            '<s-template s:vite="\'resources/js/app.ts\'" /><?= $content |> raw() ?>',
        );
        file_put_contents(
            $projectRoot . '/glaze.neon',
            "build:\n  vite:\n    enabled: true\n    command: \"php vite-build-manifest.php\"\n",
        );
        file_put_contents(
            $viteScriptPath,
            "<?php\ndeclare(strict_types=1);\nif (!is_dir('public/.vite')) {\n    mkdir('public/.vite', 0755, true);\n}\nfile_put_contents(\n    'public/.vite/manifest.json',\n    json_encode([\n        'resources/js/app.ts' => [\n            'file' => 'assets/app-abc123.js',\n            'isEntry' => true,\n        ],\n    ], JSON_THROW_ON_ERROR),\n);\n",
        );

        $this->exec(sprintf('build --root "%s"', $projectRoot));

        $this->assertExitCode(0);
        $this->assertOutputContains('<success>✓ done</success> <info>[Vite]</info> Running Vite build...');
        $output = file_get_contents($projectRoot . '/public/index.html');
        $this->assertIsString($output);
        $this->assertStringContainsString('/assets/app-abc123.js', $output);
    }
}
