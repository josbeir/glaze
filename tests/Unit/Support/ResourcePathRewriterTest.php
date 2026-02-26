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

    /**
     * Ensure root and already-prefixed paths are handled when applying base path.
     */
    public function testApplyBasePathToPathHandlesRootAndAlreadyPrefixedValues(): void
    {
        $rewriter = new ResourcePathRewriter();
        $siteConfig = new SiteConfig(basePath: '/docs');

        $rootPath = $rewriter->applyBasePathToPath('/', $siteConfig);
        $alreadyPrefixed = $rewriter->applyBasePathToPath('/docs/guide', $siteConfig);

        $this->assertSame('/docs/', $rootPath);
        $this->assertSame('/docs/guide', $alreadyPrefixed);
    }

    /**
     * Ensure template rewriting skips non-root-relative paths.
     */
    public function testRewriteTemplateResourcePathSkipsRelativeTemplatePaths(): void
    {
        $rewriter = new ResourcePathRewriter();
        $siteConfig = new SiteConfig(basePath: '/docs');

        $rewritten = $rewriter->rewriteTemplateResourcePath('assets/site.css', $siteConfig);

        $this->assertSame('assets/site.css', $rewritten);
    }

    /**
     * Ensure content-relative paths normalize dot segments and preserve suffixes.
     */
    public function testToContentAbsoluteResourcePathNormalizesSegmentsAndPreservesSuffix(): void
    {
        $rewriter = new ResourcePathRewriter();

        $absolute = $rewriter->toContentAbsoluteResourcePath('../images/./hero.png?size=2x#top', 'guides/intro.dj');

        $this->assertSame('/images/hero.png?size=2x#top', $absolute);
    }

    /**
     * Ensure external-path detection covers anchors, protocol-relative paths, and URI schemes.
     */
    public function testIsExternalResourcePathDetectsExternalForms(): void
    {
        $rewriter = new ResourcePathRewriter();

        $this->assertTrue($rewriter->isExternalResourcePath('#section'));
        $this->assertTrue($rewriter->isExternalResourcePath('//cdn.example.com/app.css'));
        $this->assertTrue($rewriter->isExternalResourcePath('mailto:test@example.com'));
        $this->assertFalse($rewriter->isExternalResourcePath('/images/logo.png'));
    }

    /**
     * Ensure absolute-path detection differentiates relative resource values.
     */
    public function testIsAbsoluteResourcePathDistinguishesRelativeValues(): void
    {
        $rewriter = new ResourcePathRewriter();

        $this->assertTrue($rewriter->isAbsoluteResourcePath('/docs/page'));
        $this->assertTrue($rewriter->isAbsoluteResourcePath('https://example.com/page'));
        $this->assertFalse($rewriter->isAbsoluteResourcePath('docs/page'));
    }

    /**
     * Ensure segment normalization resolves current and parent-directory markers.
     */
    public function testNormalizePathSegmentsResolvesDotsAndDotDots(): void
    {
        $rewriter = new ResourcePathRewriter();

        $normalized = $rewriter->normalizePathSegments('docs/./guides/../images/hero.png');

        $this->assertSame('docs/images/hero.png', $normalized);
    }
}
