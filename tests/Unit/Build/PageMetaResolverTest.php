<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Build;

use Glaze\Build\PageMetaResolver;
use Glaze\Config\SiteConfig;
use Glaze\Content\ContentPage;
use PHPUnit\Framework\TestCase;

/**
 * Tests for effective page meta resolution.
 */
final class PageMetaResolverTest extends TestCase
{
    /**
     * Ensure site defaults are used when page does not override values.
     */
    public function testResolveUsesSiteDefaults(): void
    {
        $resolver = new PageMetaResolver();
        $page = $this->makePage([]);
        $site = new SiteConfig(
            description: 'Default description',
            meta: ['robots' => 'index,follow'],
        );

        $meta = $resolver->resolve($page, $site);

        $this->assertSame([
            'robots' => 'index,follow',
            'description' => 'Default description',
        ], $meta);
    }

    /**
     * Ensure page-level overrides win over site defaults.
     */
    public function testResolveAppliesPageOverrides(): void
    {
        $resolver = new PageMetaResolver();
        $page = $this->makePage([
            'description' => 'Page description',
            'meta' => [
                'robots' => 'noindex',
                'viewport' => 'width=device-width, initial-scale=1',
            ],
        ]);
        $site = new SiteConfig(
            description: 'Default description',
            meta: [
                'robots' => 'index,follow',
                'author' => 'Glaze',
            ],
        );

        $meta = $resolver->resolve($page, $site);

        $this->assertSame([
            'robots' => 'noindex',
            'author' => 'Glaze',
            'description' => 'Page description',
            'viewport' => 'width=device-width, initial-scale=1',
        ], $meta);
    }

    /**
     * Ensure invalid page-level meta input is ignored safely.
     */
    public function testResolveIgnoresInvalidPageMetaStructures(): void
    {
        $resolver = new PageMetaResolver();
        $page = $this->makePage([
            'description' => '  ',
            'meta' => true,
        ]);
        $site = new SiteConfig(meta: ['robots' => 'index,follow']);

        $meta = $resolver->resolve($page, $site);

        $this->assertSame(['robots' => 'index,follow'], $meta);
    }

    /**
     * Ensure invalid entries in page meta maps are ignored during normalization.
     */
    public function testResolveIgnoresInvalidEntriesInsideMetaMap(): void
    {
        $resolver = new PageMetaResolver();
        $page = $this->makePage([
            'meta' => [
                0 => 'ignored-numeric-key',
                '' => 'ignored-empty-key',
                'robots' => ' noindex ',
                'complex' => ['invalid'],
            ],
        ]);

        $meta = $resolver->resolve($page, new SiteConfig());

        $this->assertSame(['robots' => 'noindex'], $meta);
    }

    /**
     * Build a minimal content page instance for resolver tests.
     *
     * @param array<string, mixed> $meta Page metadata.
     */
    protected function makePage(array $meta): ContentPage
    {
        return new ContentPage(
            sourcePath: '/tmp/index.dj',
            relativePath: 'index.dj',
            slug: 'index',
            urlPath: '/',
            outputRelativePath: 'index.html',
            title: 'Home',
            source: '# Home',
            draft: false,
            meta: $meta,
        );
    }
}
