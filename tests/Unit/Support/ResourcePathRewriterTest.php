<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Support;

use Glaze\Config\SiteConfig;
use Glaze\Support\ResourcePathRewriter;
use PHPUnit\Framework\TestCase;

/**
 * Tests for shared resource path rewriting behavior.
 */
final class ResourcePathRewriterTest extends TestCase
{
    /**
     * Ensure Djot resource rewriting resolves page-relative paths and applies base path.
     */
    public function testRewriteDjotResourcePathResolvesRelativePathAndBasePath(): void
    {
        $rewriter = new ResourcePathRewriter();
        $siteConfig = new SiteConfig(basePath: '/docs');

        $rewritten = $rewriter->rewriteDjotResourcePath('../images/logo.png', 'guides/intro.dj', $siteConfig);

        $this->assertSame('/docs/images/logo.png', $rewritten);
    }

    /**
     * Ensure template path rewriting applies configured base path for root-relative links.
     */
    public function testRewriteTemplateResourcePathAppliesBasePath(): void
    {
        $rewriter = new ResourcePathRewriter();
        $siteConfig = new SiteConfig(basePath: '/docs');

        $rewritten = $rewriter->rewriteTemplateResourcePath('/assets/app.css', $siteConfig);

        $this->assertSame('/docs/assets/app.css', $rewritten);
    }

    /**
     * Ensure external template paths remain unchanged.
     */
    public function testRewriteTemplateResourcePathSkipsExternalUrl(): void
    {
        $rewriter = new ResourcePathRewriter();
        $siteConfig = new SiteConfig(basePath: '/docs');

        $rewritten = $rewriter->rewriteTemplateResourcePath('https://example.com/app.css', $siteConfig);

        $this->assertSame('https://example.com/app.css', $rewritten);
    }

    /**
     * Ensure base path stripping removes only configured prefix.
     */
    public function testStripBasePathFromPathRemovesConfiguredPrefix(): void
    {
        $rewriter = new ResourcePathRewriter();
        $siteConfig = new SiteConfig(basePath: '/docs');

        $stripped = $rewriter->stripBasePathFromPath('/docs/images/photo.jpg', $siteConfig);

        $this->assertSame('/images/photo.jpg', $stripped);
    }
}
