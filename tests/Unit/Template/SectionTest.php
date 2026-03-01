<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Template;

use Glaze\Content\ContentPage;
use Glaze\Template\Collection\PageCollection;
use Glaze\Template\ContentAssetResolver;
use Glaze\Template\Section;
use Glaze\Tests\Helper\FilesystemTestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Tests for section tree node behavior and helpers.
 */
final class SectionTest extends TestCase
{
    use FilesystemTestTrait;

    /**
     * Validate structural, traversal, and aggregation helpers.
     */
    public function testSectionExposesStructureAndTraversalHelpers(): void
    {
        $introPage = $this->makePage('guides/intro.dj', 'guides/intro');
        $childPage = $this->makePage('guides/getting-started/setup.dj', 'guides/getting-started/setup');

        $childSection = new Section(
            path: 'guides/getting-started',
            label: 'Getting Started',
            weight: 20,
            indexPage: null,
            pages: new PageCollection([$childPage]),
            children: [],
        );

        $section = new Section(
            path: 'guides',
            label: 'Guides',
            weight: 10,
            indexPage: $this->makePage('guides/index.dj', 'guides'),
            pages: new PageCollection([$introPage]),
            children: ['getting-started' => $childSection],
        );

        $this->assertSame('guides', $section->path());
        $this->assertSame('guides', $section->key());
        $this->assertSame(1, $section->depth());
        $this->assertFalse($section->isRoot());
        $this->assertSame('Guides', $section->label());
        $this->assertSame(10, $section->weight());
        $this->assertInstanceOf(ContentPage::class, $section->index());
        $this->assertCount(1, $section->pages());
        $this->assertCount(2, $section->allPages());

        $this->assertTrue($section->hasChildren());
        $this->assertSame($childSection, $section->child('getting-started'));
        $this->assertNotInstanceOf(Section::class, $section->child('missing'));
        $this->assertFalse($section->isEmpty());

        $flat = $section->flatten();
        $this->assertArrayHasKey('guides', $flat);
        $this->assertArrayHasKey('guides/getting-started', $flat);
        $this->assertCount(1, iterator_to_array($section->getIterator()));
        $this->assertCount(1, $section);

        $root = new Section(
            path: '',
            label: 'Root',
            weight: 0,
            indexPage: null,
            pages: new PageCollection([]),
            children: [],
        );

        $this->assertSame('', $root->key());
        $this->assertSame(0, $root->depth());
        $this->assertTrue($root->isRoot());
        $this->assertFalse($root->hasChildren());
        $this->assertTrue($root->isEmpty());
    }

    /**
     * Validate direct and recursive section asset helpers.
     */
    public function testSectionAssetHelpersSupportSubdirectoriesAndMissingResolver(): void
    {
        $contentPath = $this->createTempDirectory();
        mkdir($contentPath . '/guides/gallery', 0755, true);
        mkdir($contentPath . '/guides/child', 0755, true);

        file_put_contents($contentPath . '/guides/index.dj', '# Guides');
        file_put_contents($contentPath . '/guides/cover.png', 'cover');
        file_put_contents($contentPath . '/guides/gallery/photo.jpg', 'photo');
        file_put_contents($contentPath . '/guides/child/index.dj', '# Child');
        file_put_contents($contentPath . '/guides/child/diagram.svg', '<svg/>');

        $resolver = new ContentAssetResolver($contentPath, '/base');
        $section = new Section(
            path: 'guides',
            label: 'Guides',
            weight: 0,
            indexPage: null,
            pages: new PageCollection([]),
            children: [],
            assetResolver: $resolver,
        );

        $this->assertCount(1, $section->assets());
        $this->assertCount(1, $section->assets('gallery'));
        $this->assertCount(1, $section->assets('   '));

        $this->assertCount(3, $section->allAssets());
        $this->assertCount(1, $section->allAssets('child'));
        $this->assertSame('/base/guides/child/diagram.svg', $section->allAssets('child')->first()?->urlPath);

        $withoutResolver = new Section(
            path: 'guides',
            label: 'Guides',
            weight: 0,
            indexPage: null,
            pages: new PageCollection([]),
            children: [],
        );

        $this->assertCount(0, $withoutResolver->assets());
        $this->assertCount(0, $withoutResolver->allAssets());
    }

    /**
     * Build a content page value object fixture.
     *
     * @param string $relativePath Relative source path.
     * @param string $slug Page slug.
     */
    protected function makePage(string $relativePath, string $slug): ContentPage
    {
        return new ContentPage(
            sourcePath: '/tmp/' . str_replace('/', '-', $slug) . '.dj',
            relativePath: $relativePath,
            slug: $slug,
            urlPath: '/' . trim($slug, '/') . '/',
            outputRelativePath: trim($slug, '/') . '/index.html',
            title: 'Page',
            source: '# Page',
            draft: false,
            meta: [],
            taxonomies: [],
        );
    }
}
