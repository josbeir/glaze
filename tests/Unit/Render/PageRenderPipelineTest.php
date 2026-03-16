<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Render;

use Glaze\Config\BuildConfig;
use Glaze\Content\ContentPage;
use Glaze\Render\PageRenderOutput;
use Glaze\Render\PageRenderPipeline;
use Glaze\Tests\Helper\ContainerTestTrait;
use Glaze\Tests\Helper\FilesystemTestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the page render pipeline.
 */
final class PageRenderPipelineTest extends TestCase
{
    use ContainerTestTrait;
    use FilesystemTestTrait;

    /**
     * Ensure extractToc returns TOC entries from Djot source without a full render.
     */
    public function testExtractTocReturnsHeadingEntries(): void
    {
        $projectRoot = $this->copyFixtureToTemp('projects/basic');
        $config = BuildConfig::fromProjectRoot($projectRoot);

        $page = new ContentPage(
            sourcePath: $projectRoot . '/content/index.dj',
            relativePath: 'index.dj',
            slug: 'index',
            urlPath: '/index',
            outputRelativePath: 'index.html',
            title: 'Home',
            source: "# Home\n\n## Setup\n\nContent.\n\n## Usage\n\nMore.\n",
            draft: false,
            meta: [],
        );

        $pipeline = $this->createPipeline();
        $toc = $pipeline->extractToc($page, $config);

        $this->assertCount(3, $toc);

        $this->assertSame(1, $toc[0]->level);
        $this->assertSame('Home', $toc[0]->text);

        $this->assertSame(2, $toc[1]->level);
        $this->assertSame('Setup', $toc[1]->text);

        $this->assertSame(2, $toc[2]->level);
        $this->assertSame('Usage', $toc[2]->text);
    }

    /**
     * Ensure extractToc returns an empty array when no headings exist.
     */
    public function testExtractTocReturnsEmptyForNoHeadings(): void
    {
        $projectRoot = $this->copyFixtureToTemp('projects/basic');
        $config = BuildConfig::fromProjectRoot($projectRoot);

        $page = new ContentPage(
            sourcePath: $projectRoot . '/content/index.dj',
            relativePath: 'index.dj',
            slug: 'index',
            urlPath: '/index',
            outputRelativePath: 'index.html',
            title: 'Home',
            source: "Just a paragraph of text.\n",
            draft: false,
            meta: [],
        );

        $pipeline = $this->createPipeline();
        $toc = $pipeline->extractToc($page, $config);

        $this->assertSame([], $toc);
    }

    /**
     * Ensure SiteContext reports live mode during live rendering.
     */
    public function testRenderExposesLiveModeTrueInLiveMode(): void
    {
        $projectRoot = $this->copyFixtureToTemp('projects/basic');
        file_put_contents(
            $projectRoot . '/templates/page.sugar.php',
            '<?= $this->isLiveMode() ? "serve" : "build" ?>',
        );

        $config = BuildConfig::fromProjectRoot($projectRoot);
        $page = new ContentPage(
            sourcePath: $projectRoot . '/content/index.dj',
            relativePath: 'index.dj',
            slug: 'index',
            urlPath: '/index',
            outputRelativePath: 'index.html',
            title: 'Home',
            source: 'Hello.',
            draft: false,
            meta: [],
        );

        $output = $this->createPipeline()->render(
            config: $config,
            page: $page,
            pageTemplate: 'page',
            liveMode: true,
        );

        $this->assertSame('serve', $output->html);
    }

    /**
     * Ensure SiteContext reports build mode during static rendering.
     */
    public function testRenderExposesLiveModeFalseInBuildMode(): void
    {
        $projectRoot = $this->copyFixtureToTemp('projects/basic');
        file_put_contents(
            $projectRoot . '/templates/page.sugar.php',
            '<?= $this->isLiveMode() ? "serve" : "build" ?>',
        );

        $config = BuildConfig::fromProjectRoot($projectRoot);
        $page = new ContentPage(
            sourcePath: $projectRoot . '/content/index.dj',
            relativePath: 'index.dj',
            slug: 'index',
            urlPath: '/index',
            outputRelativePath: 'index.html',
            title: 'Home',
            source: 'Hello.',
            draft: false,
            meta: [],
        );

        $output = $this->createPipeline()->render(
            config: $config,
            page: $page,
            pageTemplate: 'page',
            liveMode: false,
        );

        $this->assertSame('build', $output->html);
    }

    /**
     * Ensure SiteContext exposes live mode for project templates.
     */
    public function testRenderExposesLiveModeViaSiteContextMethod(): void
    {
        $projectRoot = $this->copyFixtureToTemp('projects/basic');
        file_put_contents(
            $projectRoot . '/templates/page.sugar.php',
            '<?= $this->isLiveMode() ? "serve" : "build" ?>',
        );

        $config = BuildConfig::fromProjectRoot($projectRoot);
        $page = new ContentPage(
            sourcePath: $projectRoot . '/content/index.dj',
            relativePath: 'index.dj',
            slug: 'index',
            urlPath: '/index',
            outputRelativePath: 'index.html',
            title: 'Home',
            source: 'Hello.',
            draft: false,
            meta: [],
        );

        $outputLive = $this->createPipeline()->render(
            config: $config,
            page: $page,
            pageTemplate: 'page',
            liveMode: true,
        );

        $outputBuild = $this->createPipeline()->render(
            config: $config,
            page: $page,
            pageTemplate: 'page',
            liveMode: false,
        );

        $this->assertSame('serve', $outputLive->html);
        $this->assertSame('build', $outputBuild->html);
    }

    /**
     * Ensure render returns a PageRenderOutput with TOC-enriched page.
     */
    public function testRenderReturnsPageRenderOutputWithToc(): void
    {
        $projectRoot = $this->copyFixtureToTemp('projects/basic');
        file_put_contents(
            $projectRoot . '/content/index.dj',
            "# Home\n\n## Installation\n\nContent.\n",
        );

        $config = BuildConfig::fromProjectRoot($projectRoot);

        $page = new ContentPage(
            sourcePath: $projectRoot . '/content/index.dj',
            relativePath: 'index.dj',
            slug: 'index',
            urlPath: '/index',
            outputRelativePath: 'index.html',
            title: 'Home',
            source: "# Home\n\n## Installation\n\nContent.\n",
            draft: false,
            meta: [],
        );

        $pipeline = $this->createPipeline();
        $output = $pipeline->render(
            config: $config,
            page: $page,
            pageTemplate: 'page',
            liveMode: true,
        );

        $this->assertInstanceOf(PageRenderOutput::class, $output);
        $this->assertNotEmpty($output->html);
        $this->assertCount(2, $output->page->toc);
        $this->assertSame('Home', $output->page->toc[0]->text);
        $this->assertSame('Installation', $output->page->toc[1]->text);
    }

    /**
     * Create a PageRenderPipeline instance via the DI container.
     */
    protected function createPipeline(): PageRenderPipeline
    {
        /** @var \Glaze\Render\PageRenderPipeline */
        return $this->service(PageRenderPipeline::class);
    }
}
