<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Scaffold;

use Glaze\Scaffold\PageScaffoldOptions;
use Glaze\Scaffold\PageScaffoldService;
use Glaze\Tests\Helper\FilesystemTestTrait;
use Glaze\Utility\Normalization;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for page scaffold service.
 */
final class PageScaffoldServiceTest extends TestCase
{
    use FilesystemTestTrait;

    /**
     * Ensure scaffold creates page at type rule path and writes expected frontmatter.
     */
    public function testScaffoldCreatesTypedPage(): void
    {
        $projectRoot = $this->createTempDirectory();
        $contentPath = $projectRoot . '/content';

        $service = new PageScaffoldService();
        $targetPath = $service->scaffold(
            $contentPath,
            new PageScaffoldOptions(
                title: 'My Post',
                date: '2026-02-24T10:00:00+00:00',
                type: 'blog',
                draft: true,
                slug: 'my-post',
                titleSlug: 'my-post',
                pathRule: ['match' => 'blog', 'createPattern' => null],
                pathPrefix: null,
                asIndex: false,
                force: false,
            ),
        );

        $this->assertSame(
            Normalization::path($contentPath . '/blog/my-post.dj'),
            Normalization::path($targetPath),
        );
        $this->assertFileExists($targetPath);

        $source = file_get_contents($targetPath);
        $this->assertIsString($source);
        $this->assertStringContainsString('title: My Post', $source);
        $this->assertStringContainsString("date: '2026-02-24T10:00:00+00:00'", $source);
        $this->assertStringContainsString('type: blog', $source);
        $this->assertStringContainsString('draft: true', $source);
    }

    /**
     * Ensure scaffold supports explicit path prefixes and index bundle layout.
     */
    public function testScaffoldCreatesIndexBundleWithPathPrefix(): void
    {
        $projectRoot = $this->createTempDirectory();
        $contentPath = $projectRoot . '/content';

        $service = new PageScaffoldService();
        $targetPath = $service->scaffold(
            $contentPath,
            new PageScaffoldOptions(
                title: 'My Post',
                date: '2026-02-24T10:00:00+00:00',
                type: null,
                draft: false,
                slug: 'my-post',
                titleSlug: 'my-post',
                pathRule: null,
                pathPrefix: 'posts/2026',
                asIndex: true,
                force: false,
            ),
        );

        $this->assertSame(
            Normalization::path($contentPath . '/posts/2026/my-post/index.dj'),
            Normalization::path($targetPath),
        );
        $this->assertFileExists($targetPath);
    }

    /**
     * Ensure date tokens in createPattern are expanded in scaffold target path.
     */
    public function testScaffoldResolvesCreatePatternDateTokens(): void
    {
        $projectRoot = $this->createTempDirectory();
        $contentPath = $projectRoot . '/content';

        $service = new PageScaffoldService();
        $targetPath = $service->scaffold(
            $contentPath,
            new PageScaffoldOptions(
                title: 'My Post',
                date: '2026-02-24T10:00:00+00:00',
                type: 'blog',
                draft: false,
                slug: 'my-post',
                titleSlug: 'my-post',
                pathRule: ['match' => 'blog', 'createPattern' => 'blog/{date:Y/m}'],
                pathPrefix: null,
                asIndex: false,
                force: false,
            ),
        );

        $this->assertSame(
            Normalization::path($contentPath . '/blog/2026/02/my-post.dj'),
            Normalization::path($targetPath),
        );
        $this->assertFileExists($targetPath);
    }

    /**
     * Ensure scaffold rejects overwrite unless force is enabled.
     */
    public function testScaffoldRespectsForceOverwriteOption(): void
    {
        $projectRoot = $this->createTempDirectory();
        $contentPath = $projectRoot . '/content';
        mkdir($contentPath . '/blog', 0755, true);
        file_put_contents($contentPath . '/blog/my-post.dj', 'existing');

        $service = new PageScaffoldService();
        $options = new PageScaffoldOptions(
            title: 'My Post',
            date: '2026-02-24T10:00:00+00:00',
            type: 'blog',
            draft: false,
            slug: 'my-post',
            titleSlug: 'my-post',
            pathRule: ['match' => 'blog', 'createPattern' => null],
            pathPrefix: null,
            asIndex: false,
            force: false,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Target file already exists');

        $service->scaffold($contentPath, $options);
    }

    /**
     * Ensure path rule utilities normalize legacy and object path definitions.
     */
    public function testTypePathRulesNormalizationAndLookup(): void
    {
        $service = new PageScaffoldService();

        $rules = $service->typePathRules([
            'blog',
            ['match' => 'posts', 'createPattern' => 'posts/{date:Y}'],
            ['match' => '', 'createPattern' => null],
            true,
        ]);

        $this->assertSame([
            ['match' => 'blog', 'createPattern' => null],
            ['match' => 'posts', 'createPattern' => 'posts/{date:Y}'],
        ], $rules);

        $this->assertSame(
            ['match' => 'posts', 'createPattern' => 'posts/{date:Y}'],
            $service->findPathRuleByMatch($rules, 'POSTS'),
        );
        $this->assertNull($service->findPathRuleByMatch($rules, 'docs'));
    }

    /**
     * Ensure force true allows overwriting existing files.
     */
    public function testScaffoldAllowsOverwriteWhenForceEnabled(): void
    {
        $projectRoot = $this->createTempDirectory();
        $contentPath = $projectRoot . '/content';
        mkdir($contentPath . '/blog', 0755, true);
        file_put_contents($contentPath . '/blog/my-post.dj', 'existing');

        $service = new PageScaffoldService();
        $targetPath = $service->scaffold(
            $contentPath,
            new PageScaffoldOptions(
                title: 'My Post',
                date: '2026-02-24T10:00:00+00:00',
                type: 'blog',
                draft: false,
                slug: 'my-post',
                titleSlug: 'my-post',
                pathRule: ['match' => 'blog', 'createPattern' => null],
                pathPrefix: null,
                asIndex: false,
                force: true,
            ),
        );

        $this->assertFileExists($targetPath);
    }
}
