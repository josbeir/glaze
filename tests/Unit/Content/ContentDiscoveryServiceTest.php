<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Content;

use Glaze\Content\ContentDiscoveryService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Djot content discovery and route mapping.
 */
final class ContentDiscoveryServiceTest extends TestCase
{
    /**
     * Validate page discovery and derived route metadata.
     */
    public function testDiscoverBuildsPageMetadata(): void
    {
        $rootPath = $this->createTempDirectory();
        $contentPath = $rootPath . '/content';
        mkdir($contentPath . '/blog', 0755, true);
        file_put_contents($contentPath . '/index.dj', "# Home\n");
        file_put_contents($contentPath . '/blog/Hello World.dj', "# Blog\n");

        $service = new ContentDiscoveryService();
        $pages = $service->discover($contentPath);

        $this->assertCount(2, $pages);
        $slugs = array_map(static fn($page): string => $page->slug, $pages);
        $this->assertSame(['blog/hello-world', 'index'], $slugs);

        $indexPage = $pages[1];
        $this->assertSame('/', $indexPage->urlPath);
        $this->assertSame('index.html', $indexPage->outputRelativePath);
        $this->assertFalse($indexPage->draft);
        $this->assertSame([], $indexPage->meta);

        $blogPage = $pages[0];
        $this->assertSame('/blog/hello-world/', $blogPage->urlPath);
        $this->assertSame('blog/hello-world/index.html', $blogPage->outputRelativePath);
        $this->assertFalse($blogPage->draft);
        $this->assertSame([], $blogPage->meta);
    }

    /**
     * Validate NEON frontmatter metadata overrides title and slug values.
     */
    public function testDiscoverAppliesNeonFrontMatterMetadata(): void
    {
        $rootPath = $this->createTempDirectory();
        $contentPath = $rootPath . '/content';
        mkdir($contentPath, 0755, true);
        file_put_contents(
            $contentPath . '/index.dj',
            "+++\ntitle: Front Home\nslug: custom/home\ndraft: true\n+++\n# Home\n",
        );

        $service = new ContentDiscoveryService();
        $pages = $service->discover($contentPath);

        $this->assertCount(1, $pages);
        $page = $pages[0];
        $this->assertSame('custom/home', $page->slug);
        $this->assertSame('/custom/home/', $page->urlPath);
        $this->assertSame('custom/home/index.html', $page->outputRelativePath);
        $this->assertSame('Front Home', $page->title);
        $this->assertTrue($page->draft);
        $this->assertSame('Front Home', $page->meta['title']);
        $this->assertSame('custom/home', $page->meta['slug']);
        $this->assertTrue($page->meta['draft']);
        $this->assertSame("# Home\n", $page->source);
    }

    /**
     * Validate nested index files resolve to directory URLs.
     */
    public function testDiscoverMapsNestedIndexToDirectoryUrl(): void
    {
        $rootPath = $this->createTempDirectory();
        $contentPath = $rootPath . '/content';
        mkdir($contentPath . '/blog', 0755, true);
        file_put_contents($contentPath . '/blog/index.dj', "# Blog\n");

        $service = new ContentDiscoveryService();
        $pages = $service->discover($contentPath);

        $this->assertCount(1, $pages);
        $page = $pages[0];
        $this->assertSame('blog', $page->slug);
        $this->assertSame('/blog/', $page->urlPath);
        $this->assertSame('blog/index.html', $page->outputRelativePath);
    }

    /**
     * Create a temporary directory for isolated test execution.
     */
    protected function createTempDirectory(): string
    {
        $path = sys_get_temp_dir() . '/glaze_test_' . uniqid('', true);
        mkdir($path, 0755, true);

        return $path;
    }
}
