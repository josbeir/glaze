<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Render\Djot;

use Closure;
use Djot\DjotConverter;
use Glaze\Config\SiteConfig;
use Glaze\Render\Djot\InternalDjotLinkExtension;
use Glaze\Support\ResourcePathRewriter;
use PHPUnit\Framework\TestCase;

/**
 * Tests for internal Djot link and image rewriting extension.
 */
final class InternalDjotLinkExtensionTest extends TestCase
{
    /**
     * Ensure internal Djot links are rewritten without extension and suffix preserved.
     */
    public function testRegisterRewritesDjotLinksAndPreservesSuffix(): void
    {
        $html = $this->render(
            '[Guide](guide.dj?mode=full#top)',
        );

        $this->assertStringContainsString('href="guide?mode=full#top"', $html);
        $this->assertStringNotContainsString('href="guide.dj?mode=full#top"', $html);
    }

    /**
     * Ensure external links remain unchanged.
     */
    public function testRegisterSkipsExternalLinks(): void
    {
        $html = $this->render('[External](https://example.com/guide.dj)');

        $this->assertStringContainsString('href="https://example.com/guide.dj"', $html);
    }

    /**
     * Ensure image sources are rewritten with site base path and keep title/attributes.
     */
    public function testRegisterRewritesImageSourceAndRetainsMetadata(): void
    {
        $extension = new InternalDjotLinkExtension(
            resourcePathRewriter: new ResourcePathRewriter(),
            siteConfig: new SiteConfig(basePath: '/docs'),
            relativePagePath: 'guides/intro.dj',
        );

        $attributes = $this->callProtected($extension, 'renderNodeAttributes', [
            'loading' => 'lazy',
            'data-id' => 'hero',
        ]);

        $this->assertIsString($attributes);

        $this->assertStringContainsString(' loading="lazy"', $attributes);
        $this->assertStringContainsString(' data-id="hero"', $attributes);

        $html = $this->render(
            '![Hero](../images/hero.jpg)',
            new SiteConfig(basePath: '/docs'),
            'guides/intro.dj',
        );

        $this->assertStringContainsString('src="/docs/images/hero.jpg"', $html);
        $this->assertStringContainsString('alt="Hero"', $html);
    }

    /**
     * Ensure image rewriting is skipped when site context is missing.
     */
    public function testRegisterSkipsImageRewriteWithoutSiteContext(): void
    {
        $html = $this->render('![Hero](../images/hero.jpg)');

        $this->assertStringContainsString('src="../images/hero.jpg"', $html);
    }

    /**
     * Ensure attribute rendering skips invalid names and escapes values.
     */
    public function testRenderNodeAttributesSkipsInvalidKeysAndEscapesValues(): void
    {
        $extension = new InternalDjotLinkExtension(new ResourcePathRewriter());

        $attributes = $this->callProtected($extension, 'renderNodeAttributes', [
            'data-title' => 'Tom & Jerry',
            '' => 'ignored',
            12 => 'ignored-int-key',
        ]);

        $this->assertIsString($attributes);

        $this->assertStringContainsString(' data-title="Tom &amp; Jerry"', $attributes);
        $this->assertStringNotContainsString('ignored', $attributes);
    }

    /**
     * Ensure .dj stripping only applies to trailing Djot extension.
     */
    public function testStripDjotExtensionOnlyForTrailingExtension(): void
    {
        $extension = new InternalDjotLinkExtension(new ResourcePathRewriter());

        $stripped = $this->callProtected($extension, 'stripDjotExtension', 'posts/hello.dj#top');
        $untouched = $this->callProtected($extension, 'stripDjotExtension', 'posts/hello.md');

        $this->assertSame('posts/hello#top', $stripped);
        $this->assertSame('posts/hello.md', $untouched);
    }

    /**
     * Render Djot source with extension under test.
     *
     * @param string $source Djot source.
     * @param \Glaze\Config\SiteConfig|null $siteConfig Optional site config.
     * @param string|null $relativePagePath Optional page-relative source path.
     */
    protected function render(string $source, ?SiteConfig $siteConfig = null, ?string $relativePagePath = null): string
    {
        $converter = new DjotConverter();
        $converter->addExtension(new InternalDjotLinkExtension(
            resourcePathRewriter: new ResourcePathRewriter(),
            siteConfig: $siteConfig,
            relativePagePath: $relativePagePath,
        ));

        return $converter->convert($source);
    }

    /**
     * Invoke protected method on extension for branch coverage.
     *
     * @param object $object Extension instance.
     * @param string $method Method name.
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
