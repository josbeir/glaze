<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Extension;

use Glaze\Build\Event\BuildCompletedEvent;
use Glaze\Build\Event\ContentDiscoveredEvent;
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
    // registerVirtualPage()
    // -------------------------------------------------------------------------

    /**
     * registerVirtualPage() appends a /llms.txt virtual page to the discovery event.
     */
    public function testRegisterVirtualPageAddsVirtualPage(): void
    {
        $ext = new LlmsTxtExtension();
        [, $config] = $this->makeConfig();

        $event = new ContentDiscoveredEvent([], $config);
        $ext->registerVirtualPage($event);

        $paths = array_map(static fn(ContentPage $p): string => $p->urlPath, $event->pages);
        $this->assertContains('/llms.txt', $paths);
    }

    // -------------------------------------------------------------------------
    // collect() / resolveDescription() fallback chain
    // -------------------------------------------------------------------------

    /**
     * collect() captures the 'description' frontmatter key as the page description.
     */
    public function testCollectUsesDescriptionFrontmatterKey(): void
    {
        $ext = new LlmsTxtExtension();

        [$projectRoot, $config] = $this->makeConfig();
        $ext->collect($this->makePageWithMeta('/about', ['description' => 'About page desc.'], $config));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $content = (string)file_get_contents($projectRoot . '/public/llms.txt');
        $this->assertStringContainsString('About page desc.', $content);
    }

    /**
     * collect() falls back to the 'summary' frontmatter key when no description is present.
     */
    public function testCollectUsesSummaryFrontmatterKey(): void
    {
        $ext = new LlmsTxtExtension();

        [$projectRoot, $config] = $this->makeConfig();
        $ext->collect($this->makePageWithMeta('/about', ['summary' => 'A summary text.'], $config));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $content = (string)file_get_contents($projectRoot . '/public/llms.txt');
        $this->assertStringContainsString('A summary text.', $content);
    }

    /**
     * collect() falls back to the 'excerpt' frontmatter key as third-priority description.
     */
    public function testCollectUsesExcerptFrontmatterKey(): void
    {
        $ext = new LlmsTxtExtension();

        [$projectRoot, $config] = $this->makeConfig();
        $ext->collect($this->makePageWithMeta('/about', ['excerpt' => 'An excerpt text.'], $config));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $content = (string)file_get_contents($projectRoot . '/public/llms.txt');
        $this->assertStringContainsString('An excerpt text.', $content);
    }

    /**
     * collect() falls back to "No description provided." when no meta keys yield a value.
     */
    public function testCollectFallsBackToDefaultDescription(): void
    {
        $ext = new LlmsTxtExtension();

        [$projectRoot, $config] = $this->makeConfig();
        $ext->collect($this->makePageWithMeta('/about', [], $config));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $content = (string)file_get_contents($projectRoot . '/public/llms.txt');
        $this->assertStringContainsString('No description provided.', $content);
    }

    // -------------------------------------------------------------------------
    // write() — resolveProjectPitch() branches
    // -------------------------------------------------------------------------

    /**
     * write() uses site.meta.llmsPitch as the pitch when configured.
     */
    public function testWritePitchUsesLlmsPitchSiteMeta(): void
    {
        $ext = new LlmsTxtExtension();

        [$projectRoot, $config] = $this->makeConfig(siteMeta: ['llmsPitch' => 'From llmsPitch meta.']);
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $content = (string)file_get_contents($projectRoot . '/public/llms.txt');
        $this->assertStringContainsString('> From llmsPitch meta.', $content);
    }

    /**
     * write() falls back to site.description as the pitch when llmsPitch is absent.
     */
    public function testWritePitchUsesSiteDescription(): void
    {
        $ext = new LlmsTxtExtension();

        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/public', 0755, true);
        $config = new BuildConfig(
            projectRoot: $projectRoot,
            site: new SiteConfig(description: 'Site-level description.'),
        );
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $content = (string)file_get_contents($projectRoot . '/public/llms.txt');
        $this->assertStringContainsString('> Site-level description.', $content);
    }

    /**
     * write() falls back to the first real page description when no site description is set.
     */
    public function testWritePitchUsesBestPageDescription(): void
    {
        $ext = new LlmsTxtExtension();

        [$projectRoot, $config] = $this->makeConfig();
        $ext->collect($this->makePageWithMeta('/docs/intro', ['description' => 'Intro page description.'], $config));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $content = (string)file_get_contents($projectRoot . '/public/llms.txt');
        $this->assertStringContainsString('> Intro page description.', $content);
    }

    /**
     * write() uses a generic fallback pitch when no description source is available.
     */
    public function testWritePitchUsesGenericFallback(): void
    {
        $ext = new LlmsTxtExtension();

        [$projectRoot, $config] = $this->makeConfig(siteTitle: 'MyProject');
        $ext->collect($this->makePageWithMeta('/a', [], $config));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $content = (string)file_get_contents($projectRoot . '/public/llms.txt');
        $this->assertStringContainsString('> Documentation index for MyProject.', $content);
    }

    // -------------------------------------------------------------------------
    // write() — resolveProjectContext() branches
    // -------------------------------------------------------------------------

    /**
     * write() uses site.meta.llmsContext as the context when configured.
     */
    public function testWriteContextUsesLlmsContextSiteMeta(): void
    {
        $ext = new LlmsTxtExtension();

        [$projectRoot, $config] = $this->makeConfig(siteMeta: ['llmsContext' => 'Custom context paragraph.']);
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $content = (string)file_get_contents($projectRoot . '/public/llms.txt');
        $this->assertStringContainsString('Custom context paragraph.', $content);
    }

    /**
     * write() builds context from page descriptions when no llmsContext is configured.
     */
    public function testWriteContextUsesPageDescriptions(): void
    {
        $ext = new LlmsTxtExtension();

        [$projectRoot, $config] = $this->makeConfig();
        $ext->collect($this->makePageWithMeta('/a', ['description' => 'Alpha desc.'], $config));
        $ext->collect($this->makePageWithMeta('/b', ['description' => 'Beta desc.'], $config));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $content = (string)file_get_contents($projectRoot . '/public/llms.txt');
        $this->assertStringContainsString('Alpha desc.', $content);
        $this->assertStringContainsString('Beta desc.', $content);
    }

    /**
     * write() builds context from raw page source when no descriptions are available.
     */
    public function testWriteContextUsesSourceSummary(): void
    {
        $ext = new LlmsTxtExtension();

        [$projectRoot, $config] = $this->makeConfig();
        $event = $this->makePageWithMeta('/a', [], $config, source: 'Page source text that explains the content.');
        $ext->collect($event);
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $content = (string)file_get_contents($projectRoot . '/public/llms.txt');
        $this->assertStringContainsString('Page source text that explains the content.', $content);
    }

    /**
     * write() uses the generic fallback context when no other source is available.
     */
    public function testWriteContextUsesGenericFallback(): void
    {
        $ext = new LlmsTxtExtension();

        [$projectRoot, $config] = $this->makeConfig();
        $ext->collect($this->makePageWithMeta('/a', [], $config));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $content = (string)file_get_contents($projectRoot . '/public/llms.txt');
        $this->assertStringContainsString('Use the documentation links below', $content);
    }

    // -------------------------------------------------------------------------
    // write() — llms-full.txt section and edge cases
    // -------------------------------------------------------------------------

    /**
     * write() includes the ## Optional section when llms-full.txt is present in the output dir.
     */
    public function testWriteIncludesLlmsFullTxtSection(): void
    {
        $ext = new LlmsTxtExtension();

        [$projectRoot, $config] = $this->makeConfig();
        file_put_contents($projectRoot . '/public/llms-full.txt', 'full content');
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $content = (string)file_get_contents($projectRoot . '/public/llms.txt');
        $this->assertStringContainsString('## Optional: llms-full.txt', $content);
        $this->assertStringContainsString('Full Documentation', $content);
    }

    /**
     * write() emits "No documentation pages found." when no pages were collected.
     */
    public function testWriteWithNoPagesShowsEmptyMessage(): void
    {
        $ext = new LlmsTxtExtension();

        [$projectRoot, $config] = $this->makeConfig();
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $content = (string)file_get_contents($projectRoot . '/public/llms.txt');
        $this->assertStringContainsString('No documentation pages found.', $content);
    }

    /**
     * write() sorts pages ascending by URL path.
     */
    public function testWritePagesSortedByUrlPath(): void
    {
        $ext = new LlmsTxtExtension();

        [$projectRoot, $config] = $this->makeConfig();
        $ext->collect($this->makePageWithMeta('/z-last', [], $config));
        $ext->collect($this->makePageWithMeta('/a-first', [], $config));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $content = (string)file_get_contents($projectRoot . '/public/llms.txt');
        $posFirst = strpos($content, '/a-first');
        $posLast = strpos($content, '/z-last');
        $this->assertNotFalse($posFirst);
        $this->assertNotFalse($posLast);
        $this->assertLessThan($posLast, $posFirst);
    }

    /**
     * write() formats each page entry as "- [Title](url): description".
     */
    public function testWriteFormatsPageEntries(): void
    {
        $ext = new LlmsTxtExtension();

        [$projectRoot, $config] = $this->makeConfig(baseUrl: 'https://example.com');
        $ext->collect($this->makePageWithMeta('/guide', ['description' => 'The guide.'], $config, title: 'Guide Page'));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $content = (string)file_get_contents($projectRoot . '/public/llms.txt');
        $this->assertStringContainsString('- [Guide Page](https://example.com/guide): The guide.', $content);
    }

    /**
     * write() prepends basePath to page URLs in entries when site basePath is configured.
     */
    public function testWriteFormatsPageEntriesWithBasePath(): void
    {
        $ext = new LlmsTxtExtension();

        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/public', 0755, true);
        $config = new BuildConfig(
            projectRoot: $projectRoot,
            site: new SiteConfig(basePath: '/docs'),
        );
        $ext->collect($this->makePageWithMeta('/guide', ['description' => 'The guide.'], $config, title: 'Guide'));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $content = (string)file_get_contents($projectRoot . '/public/llms.txt');
        $this->assertStringContainsString('/docs/guide', $content);
    }

    /**
     * write() prepends basePath to the root path producing basePath/ in the URL.
     */
    public function testWriteFormatsRootPageEntryWithBasePath(): void
    {
        $ext = new LlmsTxtExtension();

        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/public', 0755, true);
        $config = new BuildConfig(
            projectRoot: $projectRoot,
            site: new SiteConfig(baseUrl: 'https://example.com', basePath: '/docs'),
        );
        $ext->collect($this->makePageWithMeta('/', ['description' => 'Home.'], $config, title: 'Home'));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $content = (string)file_get_contents($projectRoot . '/public/llms.txt');
        $this->assertStringContainsString('https://example.com/docs/', $content);
    }

    /**
     * write() context is built from first 3 page descriptions only, skipping further ones.
     */
    public function testWriteContextLimitsToThreeDescriptionSegments(): void
    {
        $ext = new LlmsTxtExtension();

        [$projectRoot, $config] = $this->makeConfig();
        $ext->collect($this->makePageWithMeta('/a', ['description' => 'Desc A.'], $config));
        $ext->collect($this->makePageWithMeta('/b', ['description' => 'Desc B.'], $config));
        $ext->collect($this->makePageWithMeta('/c', ['description' => 'Desc C.'], $config));
        $ext->collect($this->makePageWithMeta('/d', ['description' => 'Desc D should be excluded.'], $config));
        $ext->write(new BuildCompletedEvent([], $config, 0.0));

        $content = (string)file_get_contents($projectRoot . '/public/llms.txt');
        $this->assertStringContainsString('Desc A.', $content);
        $this->assertStringContainsString('Desc B.', $content);
        $this->assertStringContainsString('Desc C.', $content);
        // The 4th description must not appear in the context paragraph (only in the page entry)
        $contextArea = explode('## Documentation', $content)[0];
        $this->assertStringNotContainsString('Desc D should be excluded.', $contextArea);
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
     * @param array<string, mixed> $siteMeta Optional site meta values (e.g. llmsPitch, llmsContext).
     * @return array{0: string, 1: \Glaze\Config\BuildConfig}
     */
    private function makeConfig(
        ?string $siteTitle = null,
        ?string $baseUrl = null,
        array $siteMeta = [],
    ): array {
        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/public', 0755, true);

        $config = new BuildConfig(
            projectRoot: $projectRoot,
            site: new SiteConfig(title: $siteTitle, baseUrl: $baseUrl, meta: $siteMeta),
        );

        return [$projectRoot, $config];
    }

    /**
     * Create a minimal PageWrittenEvent for a URL path with optional frontmatter meta.
     *
     * @param string $urlPath URL path for the fake page.
     * @param array<string, mixed> $meta Frontmatter meta values.
     * @param \Glaze\Config\BuildConfig $config Build config.
     * @param string $title Optional page title.
     * @param string $source Optional raw page source text.
     */
    private function makePageWithMeta(
        string $urlPath,
        array $meta,
        BuildConfig $config,
        string $title = 'Test Page',
        string $source = '',
    ): PageWrittenEvent {
        $page = new ContentPage(
            sourcePath: '',
            relativePath: ltrim($urlPath, '/') . '.dj',
            slug: ltrim($urlPath, '/'),
            urlPath: $urlPath,
            outputRelativePath: ltrim($urlPath, '/') . '/index.html',
            title: $title,
            source: $source,
            draft: false,
            meta: $meta,
        );

        return new PageWrittenEvent($page, '/tmp/output' . $urlPath . '/index.html', $config);
    }

    /**
     * Create a minimal PageWrittenEvent for the given URL path.
     *
     * @param string $urlPath URL path for the fake page.
     * @param \Glaze\Config\BuildConfig $config Build config.
     */
    private function makePageWrittenEvent(string $urlPath, BuildConfig $config): PageWrittenEvent
    {
        return $this->makePageWithMeta($urlPath, [], $config);
    }
}
