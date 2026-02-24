<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Content;

use Closure;
use Glaze\Content\ContentDiscoveryService;
use Glaze\Tests\Helper\FilesystemTestTrait;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for Djot content discovery and route mapping.
 */
final class ContentDiscoveryServiceTest extends TestCase
{
    use FilesystemTestTrait;

    /**
     * Validate discover returns no pages for missing content directory.
     */
    public function testDiscoverReturnsEmptyArrayWhenContentDirectoryMissing(): void
    {
        $service = new ContentDiscoveryService();
        $pages = $service->discover($this->createTempDirectory() . '/missing');

        $this->assertSame([], $pages);
    }

    /**
     * Validate page discovery and derived route metadata.
     */
    public function testDiscoverBuildsPageMetadata(): void
    {
        $rootPath = $this->copyFixtureToTemp('projects/basic');
        $contentPath = $rootPath . '/content';
        mkdir($contentPath . '/blog', 0755, true);
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
        $rootPath = $this->copyFixtureToTemp('projects/basic');
        $contentPath = $rootPath . '/content';
        mkdir($contentPath . '/blog', 0755, true);
        file_put_contents($contentPath . '/blog/index.dj', "# Blog\n");

        $service = new ContentDiscoveryService();
        $pages = $service->discover($contentPath);

        $this->assertCount(2, $pages);
        $page = $pages[0];
        $this->assertSame('blog', $page->slug);
        $this->assertSame('/blog/', $page->urlPath);
        $this->assertSame('blog/index.html', $page->outputRelativePath);
    }

    /**
     * Validate metadata normalization for arrays and non-scalar values.
     */
    public function testDiscoverNormalizesComplexMetadataValues(): void
    {
        $rootPath = $this->createTempDirectory();
        $contentPath = $rootPath . '/content';
        mkdir($contentPath, 0755, true);

        file_put_contents(
            $contentPath . '/index.dj',
            "+++\nslug: ///\nname: 123\ntags:\n  - one\n  - 2\n  - null\n  - { bad: value }\nobj: { foo: bar }\n+++\n# Home\n",
        );

        $service = new ContentDiscoveryService();
        $pages = $service->discover($contentPath);

        $this->assertCount(1, $pages);
        $page = $pages[0];
        $this->assertSame('index', $page->slug);
        $this->assertSame('/', $page->urlPath);
        $this->assertSame(['one', 2, null], $page->meta['tags']);
        $this->assertSame(123, $page->meta['name']);
        $this->assertSame(['bar'], $page->meta['obj']);
    }

    /**
     * Ensure protected helper methods handle fallback and error branches.
     */
    public function testProtectedHelpersHandleFallbackPaths(): void
    {
        $service = new ContentDiscoveryService();

        $slug = $this->callProtected($service, 'slugifyPath', '///');
        $title = $this->callProtected($service, 'resolveTitle', 'blog/my-post', ['title' => '']);

        $this->assertSame('index', $slug);
        $this->assertSame('My post', $title);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unable to read content file');
        set_error_handler(static fn(): bool => true);
        try {
            $this->callProtected($service, 'readFile', '/missing-file.dj');
        } finally {
            restore_error_handler();
        }
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
