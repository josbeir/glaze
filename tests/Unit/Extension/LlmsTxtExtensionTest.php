<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Extension;

use Glaze\Build\Event\BuildCompletedEvent;
use Glaze\Build\Event\PageWrittenEvent;
use Glaze\Config\BuildConfig;
use Glaze\Config\SiteConfig;
use Glaze\Content\ContentPage;
use Glaze\Extension\LlmsTxtExtension;
use Glaze\Tests\Helper\FilesystemTestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Tests for LlmsTxtExtension including options, exclude filtering and file output.
 */
final class LlmsTxtExtensionTest extends TestCase
{
    use FilesystemTestTrait;

    // -------------------------------------------------------------------------
    // Construction and fromConfig
    // -------------------------------------------------------------------------

    /**
     * Default construction produces a valid instance.
     */
    public function testDefaultConstructionIsValid(): void
    {
        $ext = new LlmsTxtExtension();

        $this->assertInstanceOf(LlmsTxtExtension::class, $ext);
    }

    /**
     * fromConfig() with an empty array returns a default instance.
     */
    public function testFromConfigWithEmptyOptionsReturnsDefault(): void
    {
        $ext = LlmsTxtExtension::fromConfig([]);

        $this->assertInstanceOf(LlmsTxtExtension::class, $ext);
    }

    /**
     * fromConfig() accepts a title string.
     */
    public function testFromConfigAcceptsTitle(): void
    {
        $ext = LlmsTxtExtension::fromConfig(['title' => 'My Custom Title']);

        [$projectRoot, $config] = $this->makeConfig();
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $content = (string)file_get_contents($projectRoot . '/public/llms.txt');
        $this->assertStringContainsString('# My Custom Title', $content);
    }

    /**
     * fromConfig() treats blank-only title as null (falls back to site.title).
     */
    public function testFromConfigIgnoresBlankTitle(): void
    {
        $ext = LlmsTxtExtension::fromConfig(['title' => '   ']);

        [$projectRoot, $config] = $this->makeConfig(siteTitle: 'Site Title Fallback');
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $content = (string)file_get_contents($projectRoot . '/public/llms.txt');
        $this->assertStringContainsString('# Site Title Fallback', $content);
    }

    /**
     * fromConfig() accepts a pitch string used as the > quote line.
     */
    public function testFromConfigAcceptsPitch(): void
    {
        $ext = LlmsTxtExtension::fromConfig(['pitch' => 'A custom tagline.']);

        [$projectRoot, $config] = $this->makeConfig();
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $content = (string)file_get_contents($projectRoot . '/public/llms.txt');
        $this->assertStringContainsString('> A custom tagline.', $content);
    }

    /**
     * fromConfig() accepts a context string.
     */
    public function testFromConfigAcceptsContext(): void
    {
        $ext = LlmsTxtExtension::fromConfig(['context' => 'Custom context paragraph.']);

        [$projectRoot, $config] = $this->makeConfig();
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $content = (string)file_get_contents($projectRoot . '/public/llms.txt');
        $this->assertStringContainsString('Custom context paragraph.', $content);
    }

    /**
     * fromConfig() accepts a list of exclude prefixes.
     */
    public function testFromConfigAcceptsExcludeList(): void
    {
        $ext = LlmsTxtExtension::fromConfig(['exclude' => ['/private', '/drafts']]);

        [$projectRoot, $config] = $this->makeConfig();
        $ext->collect($this->makePageWrittenEvent('/private/secret', $config));
        $ext->collect($this->makePageWrittenEvent('/docs/guide', $config));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $content = (string)file_get_contents($projectRoot . '/public/llms.txt');
        $this->assertStringNotContainsString('/private', $content);
        $this->assertStringContainsString('/docs/guide', $content);
    }

    /**
     * fromConfig() ignores exclude entries that are not non-empty strings.
     */
    public function testFromConfigIgnoresInvalidExcludeEntries(): void
    {
        $ext = LlmsTxtExtension::fromConfig(['exclude' => ['', null, 42, '/valid']]);

        [$projectRoot, $config] = $this->makeConfig();
        $ext->collect($this->makePageWrittenEvent('/valid/page', $config));
        $ext->collect($this->makePageWrittenEvent('/other/page', $config));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $content = (string)file_get_contents($projectRoot . '/public/llms.txt');
        $this->assertStringNotContainsString('/valid', $content);
        $this->assertStringContainsString('/other/page', $content);
    }

    // -------------------------------------------------------------------------
    // collect() exclude behaviour
    // -------------------------------------------------------------------------

    /**
     * collect() skips pages whose URL path starts with a configured exclude prefix.
     */
    public function testCollectSkipsExcludedPrefixes(): void
    {
        $ext = new LlmsTxtExtension(exclude: ['/hidden']);

        [$projectRoot, $config] = $this->makeConfig();
        $ext->collect($this->makePageWrittenEvent('/hidden/page', $config));
        $ext->collect($this->makePageWrittenEvent('/docs/api', $config));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $content = (string)file_get_contents($projectRoot . '/public/llms.txt');
        $this->assertStringNotContainsString('/hidden', $content);
        $this->assertStringContainsString('/docs/api', $content);
    }

    /**
     * collect() includes pages that do not match any exclude prefix.
     */
    public function testCollectIncludesNonExcludedPages(): void
    {
        $ext = new LlmsTxtExtension(exclude: ['/drafts']);

        [$projectRoot, $config] = $this->makeConfig();
        $ext->collect($this->makePageWrittenEvent('/blog/post', $config));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $content = (string)file_get_contents($projectRoot . '/public/llms.txt');
        $this->assertStringContainsString('/blog/post', $content);
    }

    /**
     * collect() with multiple exclude prefixes skips all matching paths.
     */
    public function testCollectRespectsMultipleExcludePrefixes(): void
    {
        $ext = new LlmsTxtExtension(exclude: ['/internal', '/staging']);

        [$projectRoot, $config] = $this->makeConfig();
        $ext->collect($this->makePageWrittenEvent('/internal/notes', $config));
        $ext->collect($this->makePageWrittenEvent('/staging/preview', $config));
        $ext->collect($this->makePageWrittenEvent('/docs/guide', $config));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $content = (string)file_get_contents($projectRoot . '/public/llms.txt');
        $this->assertStringNotContainsString('/internal', $content);
        $this->assertStringNotContainsString('/staging', $content);
        $this->assertStringContainsString('/docs/guide', $content);
    }

    // -------------------------------------------------------------------------
    // write() output
    // -------------------------------------------------------------------------

    /**
     * write() uses the site.title as the # heading when no title override is set.
     */
    public function testWriteUsesSiteTitleWhenNoOverride(): void
    {
        $ext = new LlmsTxtExtension();

        [$projectRoot, $config] = $this->makeConfig(siteTitle: 'Acme Docs');
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $content = (string)file_get_contents($projectRoot . '/public/llms.txt');
        $this->assertStringContainsString('# Acme Docs', $content);
    }

    /**
     * write() uses the title override instead of site.title when configured.
     */
    public function testWriteUsesTitleOverride(): void
    {
        $ext = new LlmsTxtExtension(title: 'Override Title');

        [$projectRoot, $config] = $this->makeConfig(siteTitle: 'Site Title');
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $content = (string)file_get_contents($projectRoot . '/public/llms.txt');
        $this->assertStringContainsString('# Override Title', $content);
        $this->assertStringNotContainsString('# Site Title', $content);
    }

    /**
     * write() uses the pitch override as the > line when configured.
     */
    public function testWriteUsesPitchOverride(): void
    {
        $ext = new LlmsTxtExtension(pitch: 'Custom pitch text.');

        [$projectRoot, $config] = $this->makeConfig();
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $content = (string)file_get_contents($projectRoot . '/public/llms.txt');
        $this->assertStringContainsString('> Custom pitch text.', $content);
    }

    /**
     * write() uses the context override as the context paragraph when configured.
     */
    public function testWriteUsesContextOverride(): void
    {
        $ext = new LlmsTxtExtension(context: 'Find all docs below.');

        [$projectRoot, $config] = $this->makeConfig();
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $content = (string)file_get_contents($projectRoot . '/public/llms.txt');
        $this->assertStringContainsString('Find all docs below.', $content);
    }

    /**
     * write() always creates an llms.txt file.
     */
    public function testWriteCreatesFile(): void
    {
        $ext = new LlmsTxtExtension();

        [$projectRoot, $config] = $this->makeConfig();
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $this->assertFileExists($projectRoot . '/public/llms.txt');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Create a temp project root with a public output directory and matching BuildConfig.
     *
     * Returns `[$projectRoot, $config]`.
     *
     * @param string|null $siteTitle Optional site title.
     * @param string|null $baseUrl   Optional site base URL.
     * @return array{0: string, 1: \Glaze\Config\BuildConfig}
     */
    private function makeConfig(?string $siteTitle = null, ?string $baseUrl = null): array
    {
        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/public', 0755, true);

        $config = new BuildConfig(
            projectRoot: $projectRoot,
            site: new SiteConfig(title: $siteTitle, baseUrl: $baseUrl),
        );

        return [$projectRoot, $config];
    }

    /**
     * Create a minimal PageWrittenEvent for the given URL path.
     *
     * @param string $urlPath URL path for the fake page.
     * @param \Glaze\Config\BuildConfig $config Build config.
     */
    private function makePageWrittenEvent(string $urlPath, BuildConfig $config): PageWrittenEvent
    {
        $page = new ContentPage(
            sourcePath: '',
            relativePath: ltrim($urlPath, '/') . '.dj',
            slug: ltrim($urlPath, '/'),
            urlPath: $urlPath,
            outputRelativePath: ltrim($urlPath, '/') . '/index.html',
            title: 'Test Page',
            source: '',
            draft: false,
            meta: [],
        );

        return new PageWrittenEvent($page, '/tmp/output' . $urlPath . '/index.html', $config);
    }
}
