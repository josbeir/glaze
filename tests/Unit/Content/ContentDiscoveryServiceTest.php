<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Content;

use Cake\Chronos\Chronos;
use Closure;
use Glaze\Content\ContentDiscoveryService;
use Glaze\Tests\Helper\ContainerTestTrait;
use Glaze\Tests\Helper\FilesystemTestTrait;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for Djot content discovery and route mapping.
 */
final class ContentDiscoveryServiceTest extends TestCase
{
    use ContainerTestTrait;
    use FilesystemTestTrait;

    /**
     * Validate discover returns no pages for missing content directory.
     */
    public function testDiscoverReturnsEmptyArrayWhenContentDirectoryMissing(): void
    {
        $service = $this->createService();
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

        $service = $this->createService();
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
            "+++\ntitle: Front Home\nslug: custom/home\ndraft: true\ndate: 2026-02-24T14:30:00+01:00\n+++\n# Home\n",
        );

        $service = $this->createService();
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
        $this->assertInstanceOf(Chronos::class, $page->meta['date']);
        $this->assertSame('2026-02-24T14:30:00+01:00', $page->meta['date']->format('Y-m-d\TH:i:sP'));
        $this->assertSame("# Home\n", $page->source);
    }

    /**
     * Validate quoted date values are normalized to Chronos in metadata.
     */
    public function testDiscoverNormalizesQuotedDateMetadataToChronos(): void
    {
        $rootPath = $this->createTempDirectory();
        $contentPath = $rootPath . '/content';
        mkdir($contentPath, 0755, true);
        file_put_contents(
            $contentPath . '/index.dj',
            "+++\ndate: \"2026-02-24T14:30:00+01:00\"\nmeta:\n  date: \"2026-02-01\"\n+++\n# Home\n",
        );

        $service = $this->createService();
        $pages = $service->discover($contentPath);

        $this->assertCount(1, $pages);
        $page = $pages[0];
        $date = $page->meta['date'] ?? null;
        $metaMap = $page->meta['meta'] ?? null;
        $nestedDate = is_array($metaMap) ? ($metaMap['date'] ?? null) : null;

        $this->assertInstanceOf(Chronos::class, $date);
        $this->assertInstanceOf(Chronos::class, $nestedDate);
        $this->assertSame('2026-02-24T14:30:00+01:00', $date->format('Y-m-d\TH:i:sP'));
        $this->assertSame('2026-02-01', $nestedDate->format('Y-m-d'));
    }

    /**
     * Validate invalid top-level date metadata throws an explicit exception.
     */
    public function testDiscoverThrowsOnInvalidDateMetadata(): void
    {
        $rootPath = $this->createTempDirectory();
        $contentPath = $rootPath . '/content';
        mkdir($contentPath, 0755, true);
        file_put_contents(
            $contentPath . '/index.dj',
            "+++\ndate: not-a-date\n+++\n# Home\n",
        );

        $service = $this->createService();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid frontmatter "date" value');
        $service->discover($contentPath);
    }

    /**
     * Validate invalid nested meta.date metadata throws an explicit exception.
     */
    public function testDiscoverThrowsOnInvalidNestedDateMetadata(): void
    {
        $rootPath = $this->createTempDirectory();
        $contentPath = $rootPath . '/content';
        mkdir($contentPath, 0755, true);
        file_put_contents(
            $contentPath . '/index.dj',
            "+++\nmeta:\n  date: invalid-date\n+++\n# Home\n",
        );

        $service = $this->createService();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid frontmatter "date" value');
        $service->discover($contentPath);
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

        $service = $this->createService();
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
            "+++\nslug: ///\nname: 123\ntags:\n  - one\n  - 2\n  - null\n  - { bad: value }\nobj: { foo: bar }\nmeta:\n  robots: noindex\n+++\n# Home\n",
        );

        $service = $this->createService();
        $pages = $service->discover($contentPath);

        $this->assertCount(1, $pages);
        $page = $pages[0];
        $this->assertSame('index', $page->slug);
        $this->assertSame('/', $page->urlPath);
        $this->assertSame(['one'], $page->taxonomies['tags']);
        $this->assertSame(123, $page->meta['name']);
        $this->assertSame(['bar'], $page->meta['obj']);
        $this->assertSame(['robots' => 'noindex'], $page->meta['meta']);
        $this->assertArrayNotHasKey('tags', $page->meta);
    }

    /**
     * Validate configured root-level taxonomy extraction for multiple keys.
     */
    public function testDiscoverExtractsConfiguredTaxonomiesFromFrontmatterRoot(): void
    {
        $rootPath = $this->createTempDirectory();
        $contentPath = $rootPath . '/content';
        mkdir($contentPath, 0755, true);

        file_put_contents(
            $contentPath . '/index.dj',
            "+++\ntitle: Home\ntags:\n  - Tag1\n  - tag2\ncategories:\n  - Category1\n+++\n# Home\n",
        );

        $service = $this->createService();
        $pages = $service->discover($contentPath, ['tags', 'categories']);

        $this->assertCount(1, $pages);
        $page = $pages[0];
        $this->assertSame(['tag1', 'tag2'], $page->taxonomies['tags']);
        $this->assertSame(['category1'], $page->taxonomies['categories']);
        $this->assertArrayNotHasKey('tags', $page->meta);
        $this->assertArrayNotHasKey('categories', $page->meta);
    }

    /**
     * Validate content type is resolved from configured path and defaults are merged.
     */
    public function testDiscoverResolvesContentTypeFromPathAndMergesDefaults(): void
    {
        $rootPath = $this->createTempDirectory();
        $contentPath = $rootPath . '/content';
        mkdir($contentPath . '/blog', 0755, true);

        file_put_contents(
            $contentPath . '/blog/post.dj',
            "+++\ntitle: Custom title\n+++\n# Post\n",
        );

        $service = $this->createService();
        $pages = $service->discover(
            $contentPath,
            ['tags'],
            [
                'blog' => [
                    'paths' => [
                        ['match' => 'blog', 'createPattern' => null],
                    ],
                    'defaults' => [
                        'template' => 'blog-post',
                        'description' => 'Blog description',
                    ],
                ],
            ],
        );

        $this->assertCount(1, $pages);
        $page = $pages[0];
        $this->assertSame('blog', $page->type);
        $this->assertSame('blog', $page->meta['type']);
        $this->assertSame('blog-post', $page->meta['template']);
        $this->assertSame('Blog description', $page->meta['description']);
        $this->assertSame('Custom title', $page->meta['title']);
    }

    /**
     * Validate explicit frontmatter type override wins over path-based rules.
     */
    public function testDiscoverUsesExplicitFrontmatterTypeOverride(): void
    {
        $rootPath = $this->createTempDirectory();
        $contentPath = $rootPath . '/content';
        mkdir($contentPath . '/blog', 0755, true);

        file_put_contents(
            $contentPath . '/blog/post.dj',
            "+++\ntype: docs\n+++\n# Post\n",
        );

        $service = $this->createService();
        $pages = $service->discover(
            $contentPath,
            ['tags'],
            [
                'blog' => [
                    'paths' => [
                        ['match' => 'blog', 'createPattern' => null],
                    ],
                    'defaults' => ['template' => 'blog-post'],
                ],
                'docs' => [
                    'paths' => [
                        ['match' => 'docs', 'createPattern' => null],
                    ],
                    'defaults' => ['template' => 'docs-page'],
                ],
            ],
        );

        $this->assertCount(1, $pages);
        $page = $pages[0];
        $this->assertSame('docs', $page->type);
        $this->assertSame('docs-page', $page->meta['template']);
    }

    /**
     * Validate unknown explicit frontmatter type values throw a clear error.
     */
    public function testDiscoverThrowsOnUnknownExplicitFrontmatterType(): void
    {
        $rootPath = $this->createTempDirectory();
        $contentPath = $rootPath . '/content';
        mkdir($contentPath, 0755, true);

        file_put_contents(
            $contentPath . '/index.dj',
            "+++\ntype: unknown\n+++\n# Home\n",
        );

        $service = $this->createService();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('unknown content type');
        $service->discover(
            $contentPath,
            ['tags'],
            [
                'blog' => [
                    'paths' => [
                        ['match' => 'blog', 'createPattern' => null],
                    ],
                    'defaults' => [],
                ],
            ],
        );
    }

    /**
     * Validate no content type is resolved when no rules match.
     */
    public function testDiscoverLeavesTypeNullWhenNoContentTypeMatches(): void
    {
        $rootPath = $this->createTempDirectory();
        $contentPath = $rootPath . '/content';
        mkdir($contentPath . '/notes', 0755, true);

        file_put_contents(
            $contentPath . '/notes/post.dj',
            "# Note\n",
        );

        $service = $this->createService();
        $pages = $service->discover(
            $contentPath,
            ['tags'],
            [
                'blog' => [
                    'paths' => [
                        ['match' => 'blog', 'createPattern' => null],
                    ],
                    'defaults' => ['template' => 'blog-post'],
                ],
            ],
        );

        $this->assertCount(1, $pages);
        $page = $pages[0];
        $this->assertNull($page->type);
        $this->assertArrayNotHasKey('type', $page->meta);
    }

    /**
     * Ensure protected helper methods handle fallback and error branches.
     */
    public function testProtectedHelpersHandleFallbackPaths(): void
    {
        $service = $this->createService();

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

    /**
     * Create a content discovery service with concrete dependencies.
     */
    protected function createService(): ContentDiscoveryService
    {
        /** @var \Glaze\Content\ContentDiscoveryService */
        return $this->service(ContentDiscoveryService::class);
    }
}
