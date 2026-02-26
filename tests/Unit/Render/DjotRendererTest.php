<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Render;

use Glaze\Config\SiteConfig;
use Glaze\Render\DjotRenderer;
use Glaze\Render\RenderResult;
use Glaze\Support\ResourcePathRewriter;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Djot rendering with optional Phiki highlighting.
 *
 * @phpstan-import-type DjotOptions from \Glaze\Render\DjotRenderer
 */
final class DjotRendererTest extends TestCase
{
    /**
     * Ensure default rendering highlights fenced code blocks through Phiki.
     */
    public function testRenderHighlightsCodeBlocksByDefault(): void
    {
        $renderer = new DjotRenderer(new ResourcePathRewriter());
        $html = $renderer->render("```php\necho 1;\n```\n");

        $this->assertStringContainsString('class="phiki', $html);
        $this->assertStringContainsString('language-php', $html);
        $this->assertStringContainsString('data-language="php"', $html);
    }

    /**
     * Ensure code highlighting can be disabled through render options.
     */
    public function testRenderCanDisableCodeHighlighting(): void
    {
        $renderer = new DjotRenderer(new ResourcePathRewriter());
        $html = $renderer->render(
            "```php\necho 1;\n```\n",
            $this->withCodeHighlighting(['enabled' => false, 'theme' => 'nord', 'withGutter' => false]),
        );

        $this->assertStringNotContainsString('class="phiki', $html);
        $this->assertStringContainsString('<pre><code class="language-php">', $html);
    }

    /**
     * Ensure unknown code languages safely fall back to txt grammar.
     */
    public function testRenderFallsBackToTxtGrammarForUnknownLanguage(): void
    {
        $renderer = new DjotRenderer(new ResourcePathRewriter());
        $html = $renderer->render(
            "```unknownlang\nhello\n```\n",
            $this->withCodeHighlighting(['enabled' => true, 'theme' => 'nord', 'withGutter' => true]),
        );

        $this->assertStringContainsString('class="phiki', $html);
        $this->assertStringNotContainsString('language-php', $html);
        $this->assertStringNotContainsString('data-language="php"', $html);
        $this->assertStringContainsString('line-number', $html);
    }

    /**
     * Ensure `neon` fenced blocks are highlighted via the YAML grammar alias.
     */
    public function testRenderMapsNeonFenceLanguageToYamlGrammar(): void
    {
        $renderer = new DjotRenderer(new ResourcePathRewriter());
        $html = $renderer->render("```neon\nsite:\n  title: Glaze\n```\n");

        $this->assertStringContainsString('class="phiki', $html);
        $this->assertStringContainsString('language-yaml', $html);
        $this->assertStringContainsString('data-language="yaml"', $html);
    }

    /**
     * Ensure `djot` fenced blocks use the custom Djot grammar.
     */
    public function testRenderUsesCustomDjotFenceGrammar(): void
    {
        $renderer = new DjotRenderer(new ResourcePathRewriter());
        $html = $renderer->render("```djot\n# Intro\n```\n");

        $this->assertStringContainsString('class="phiki', $html);
        $this->assertStringContainsString('language-Djot', $html);
        $this->assertStringContainsString('data-language="Djot"', $html);
    }

    /**
     * Ensure internal Djot links are rendered without a file extension.
     */
    public function testRenderRewritesInternalDjotLinksToExtensionlessPaths(): void
    {
        $renderer = new DjotRenderer(new ResourcePathRewriter());
        $html = $renderer->render('[Quick start](quick-start.dj)');

        $this->assertStringContainsString('href="quick-start"', $html);
        $this->assertStringNotContainsString('href="quick-start.dj"', $html);
    }

    /**
     * Ensure rewriting keeps query and fragment suffixes intact.
     */
    public function testRenderPreservesSuffixWhenRewritingDjotLinks(): void
    {
        $renderer = new DjotRenderer(new ResourcePathRewriter());
        $html = $renderer->render('[Guide](guide.dj?mode=full#top)');

        $this->assertStringContainsString('href="guide?mode=full#top"', $html);
    }

    /**
     * Ensure custom internal link extension rewrites page-relative links with site base path.
     */
    public function testRenderUsesConfiguredInternalLinkExtensionForPageRelativeLink(): void
    {
        $renderer = new DjotRenderer(new ResourcePathRewriter());
        $html = $renderer->render(
            '[Logo](../images/logo.png)',
            $this->defaultDjotOptions(),
            new SiteConfig(basePath: '/docs'),
            'guides/intro.dj',
        );

        $this->assertStringContainsString('href="/docs/images/logo.png"', $html);
    }

    /**
     * Ensure custom internal link extension rewrites Djot image sources.
     */
    public function testRenderUsesConfiguredInternalLinkExtensionForImageSource(): void
    {
        $renderer = new DjotRenderer(new ResourcePathRewriter());
        $html = $renderer->render(
            '![Hero](../images/hero.jpg)',
            $this->defaultDjotOptions(),
            new SiteConfig(basePath: '/docs'),
            'guides/intro.dj',
        );

        $this->assertStringContainsString('src="/docs/images/hero.jpg"', $html);
        $this->assertStringContainsString('alt="Hero"', $html);
    }

    /**
     * Ensure heading anchors are disabled by default.
     */
    public function testRenderDoesNotInjectHeadingAnchorsByDefault(): void
    {
        $renderer = new DjotRenderer(new ResourcePathRewriter());
        $html = $renderer->render("# Intro\n");

        $this->assertStringNotContainsString('class="header-anchor"', $html);
    }

    /**
     * Ensure heading anchors can be injected through Djot options.
     */
    public function testRenderCanInjectHeadingAnchorsWhenEnabled(): void
    {
        $renderer = new DjotRenderer(new ResourcePathRewriter());
        $html = $renderer->render(
            "## Setup\n",
            $this->withHeaderAnchors([
                'enabled' => true,
                'symbol' => '¶',
                'position' => 'after',
                'cssClass' => 'docs-anchor',
                'ariaLabel' => 'Copy section link',
                'levels' => [2],
            ]),
        );

        $this->assertStringContainsString('id="Setup"', $html);
        $this->assertStringContainsString('href="#Setup"', $html);
        $this->assertStringContainsString('class="docs-anchor"', $html);
        $this->assertStringContainsString('aria-label="Copy section link"', $html);
        $this->assertStringContainsString('>¶</a>', $html);
    }

    /**
     * Ensure renderWithToc() returns a RenderResult with HTML and TOC entries.
     */
    public function testRenderWithTocReturnsTocEntriesForHeadings(): void
    {
        $renderer = new DjotRenderer(new ResourcePathRewriter());
        $result = $renderer->renderWithToc("# Intro\n\n## Setup\n\n### Requirements\n");

        $this->assertInstanceOf(RenderResult::class, $result);
        $this->assertCount(3, $result->toc);

        $this->assertSame(1, $result->toc[0]->level);
        $this->assertSame('Intro', $result->toc[0]->text);

        $this->assertSame(2, $result->toc[1]->level);
        $this->assertSame('Setup', $result->toc[1]->text);

        $this->assertSame(3, $result->toc[2]->level);
        $this->assertSame('Requirements', $result->toc[2]->text);
    }

    /**
     * Ensure renderWithToc() injects TOC HTML in place of the [[toc]] directive.
     */
    public function testRenderWithTocInjectsTocHtmlForTocDirective(): void
    {
        $renderer = new DjotRenderer(new ResourcePathRewriter());
        $result = $renderer->renderWithToc("[[toc]]\n\n## Setup\n\n## Usage\n");

        $this->assertStringNotContainsString('[[toc]]', $result->html);
        $this->assertStringContainsString('<nav class="toc">', $result->html);
        $this->assertStringContainsString('href="#Setup"', $result->html);
        $this->assertStringContainsString('href="#Usage"', $result->html);
    }

    /**
     * Ensure renderWithToc() returns an empty toc list for pages with no headings.
     */
    public function testRenderWithTocReturnsEmptyTocForPageWithNoHeadings(): void
    {
        $renderer = new DjotRenderer(new ResourcePathRewriter());
        $result = $renderer->renderWithToc("Just a paragraph.\n");

        $this->assertInstanceOf(RenderResult::class, $result);
        $this->assertSame([], $result->toc);
        $this->assertStringContainsString('<p>Just a paragraph.</p>', $result->html);
    }

    /**
     * Ensure renderWithToc() assigns id attributes to headings so TOC links resolve.
     */
    public function testRenderWithTocAssignsHeadingIdAttributes(): void
    {
        $renderer = new DjotRenderer(new ResourcePathRewriter());
        $result = $renderer->renderWithToc("## Getting Started\n");

        $this->assertStringContainsString('id="Getting-Started"', $result->html);
        $this->assertSame('Getting-Started', $result->toc[0]->id);
    }

    /**
     * Ensure autolink extension is inactive by default.
     */
    public function testRenderDoesNotAutolinkUrlsByDefault(): void
    {
        $renderer = new DjotRenderer(new ResourcePathRewriter());
        $html = $renderer->render("Visit https://example.com today.\n");

        $this->assertStringNotContainsString('<a href="https://example.com">', $html);
    }

    /**
     * Ensure autolink extension converts bare URLs to anchor links when enabled.
     */
    public function testRenderAutolinksUrlsWhenEnabled(): void
    {
        $renderer = new DjotRenderer(new ResourcePathRewriter());
        $html = $renderer->render(
            "Visit https://example.com today.\n",
            $this->withAutolink(['enabled' => true, 'allowedSchemes' => ['https', 'http', 'mailto']]),
        );

        $this->assertStringContainsString('<a href="https://example.com">', $html);
    }

    /**
     * Ensure external links extension is inactive by default.
     */
    public function testRenderDoesNotAddExternalLinkAttributesByDefault(): void
    {
        $renderer = new DjotRenderer(new ResourcePathRewriter());
        $html = $renderer->render("[link](https://external.com)\n");

        $this->assertStringNotContainsString('target="_blank"', $html);
    }

    /**
     * Ensure external links extension adds target and rel attributes when enabled.
     */
    public function testRenderAddsExternalLinkAttributesWhenEnabled(): void
    {
        $renderer = new DjotRenderer(new ResourcePathRewriter());
        $html = $renderer->render(
            "[link](https://external.com)\n",
            $this->withExternalLinks([
                'enabled' => true,
                'internalHosts' => [],
                'target' => '_blank',
                'rel' => 'noopener noreferrer',
                'nofollow' => false,
            ]),
        );

        $this->assertStringContainsString('target="_blank"', $html);
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
    }

    /**
     * Ensure external links are not modified for internal hosts.
     */
    public function testRenderDoesNotModifyInternalHostLinks(): void
    {
        $renderer = new DjotRenderer(new ResourcePathRewriter());
        $html = $renderer->render(
            "[link](https://example.com/page)\n",
            $this->withExternalLinks([
                'enabled' => true,
                'internalHosts' => ['example.com'],
                'target' => '_blank',
                'rel' => 'noopener noreferrer',
                'nofollow' => false,
            ]),
        );

        $this->assertStringNotContainsString('target="_blank"', $html);
    }

    /**
     * Ensure smart quotes extension does not change the default English quote behaviour.
     */
    public function testRenderDoesNotApplySmartQuotesByDefault(): void
    {
        $renderer = new DjotRenderer(new ResourcePathRewriter());
        $html = $renderer->render("She said \"hello\".\n");

        // Djot already outputs English curly quotes by default; German opening quote should not appear
        $this->assertStringNotContainsString("\u{201E}", $html);
    }

    /**
     * Ensure smart quotes extension uses locale-specific quote characters when enabled with a locale.
     */
    public function testRenderAppliesSmartQuotesWhenEnabled(): void
    {
        $renderer = new DjotRenderer(new ResourcePathRewriter());
        $html = $renderer->render(
            "She said \"hello\".\n",
            $this->withSmartQuotes([
                'enabled' => true,
                'locale' => 'de',
                'openDouble' => null,
                'closeDouble' => null,
                'openSingle' => null,
                'closeSingle' => null,
            ]),
        );

        // German locale uses „ (U+201E) as the opening double quote
        $this->assertStringContainsString("\u{201E}", $html);
    }

    /**
     * Ensure mentions extension is inactive by default.
     */
    public function testRenderDoesNotExpandMentionsByDefault(): void
    {
        $renderer = new DjotRenderer(new ResourcePathRewriter());
        $html = $renderer->render("Hello @johndoe!\n");

        $this->assertStringNotContainsString('/users/view/johndoe', $html);
    }

    /**
     * Ensure mentions extension converts @username to a link when enabled.
     */
    public function testRenderExpandsMentionsToLinksWhenEnabled(): void
    {
        $renderer = new DjotRenderer(new ResourcePathRewriter());
        $html = $renderer->render(
            "Hello @johndoe!\n",
            $this->withMentions([
                'enabled' => true,
                'urlTemplate' => '/users/view/{username}',
                'cssClass' => 'mention',
            ]),
        );

        $this->assertStringContainsString('href="/users/view/johndoe"', $html);
        $this->assertStringContainsString('data-username="johndoe"', $html);
        $this->assertStringContainsString('@johndoe', $html);
    }

    /**
     * Ensure semantic span extension is inactive by default.
     */
    public function testRenderDoesNotApplySemanticSpansByDefault(): void
    {
        $renderer = new DjotRenderer(new ResourcePathRewriter());
        $html = $renderer->render("[text]{kbd=Enter}\n");

        $this->assertStringNotContainsString('<kbd>', $html);
    }

    /**
     * Ensure semantic span extension wraps spans in semantic HTML elements when enabled.
     */
    public function testRenderAppliesSemanticSpansWhenEnabled(): void
    {
        $renderer = new DjotRenderer(new ResourcePathRewriter());
        $html = $renderer->render(
            "[text]{kbd=Enter}\n",
            $this->withSemanticSpan(['enabled' => true]),
        );

        $this->assertStringContainsString('<kbd>', $html);
    }

    /**
     * Ensure default attributes extension is inactive by default.
     */
    public function testRenderDoesNotAddDefaultAttributesByDefault(): void
    {
        $renderer = new DjotRenderer(new ResourcePathRewriter());
        $html = $renderer->render("# Heading\n");

        $this->assertStringNotContainsString('class="my-heading"', $html);
    }

    /**
     * Ensure default attributes extension injects configured attributes onto elements when enabled.
     */
    public function testRenderAddsDefaultAttributesToElementsWhenEnabled(): void
    {
        $renderer = new DjotRenderer(new ResourcePathRewriter());
        $html = $renderer->render(
            "# Heading\n",
            $this->withDefaultAttributes([
                'enabled' => true,
                'defaults' => ['heading' => ['class' => 'my-heading']],
            ]),
        );

        $this->assertStringContainsString('class="my-heading"', $html);
    }

    /**
     * Merge code highlighting overrides into default Djot options.
     *
     * @param array{enabled: bool, theme: string, withGutter: bool} $codeHighlighting
     * @phpstan-return DjotOptions
     */
    protected function withCodeHighlighting(array $codeHighlighting): array
    {
        $defaults = $this->defaultDjotOptions();
        $defaults['codeHighlighting'] = $codeHighlighting;

        return $defaults;
    }

    /**
     * Merge heading anchor overrides into default Djot options.
     *
     * @param array{enabled: bool, symbol: string, position: string, cssClass: string, ariaLabel: string, levels: array<int>} $headerAnchors
     * @phpstan-return DjotOptions
     */
    protected function withHeaderAnchors(array $headerAnchors): array
    {
        $defaults = $this->defaultDjotOptions();
        $defaults['headerAnchors'] = $headerAnchors;

        return $defaults;
    }

    /**
     * Merge autolink overrides into default Djot options.
     *
     * @param array{enabled: bool, allowedSchemes: array<string>} $autolink
     * @phpstan-return DjotOptions
     */
    protected function withAutolink(array $autolink): array
    {
        $defaults = $this->defaultDjotOptions();
        $defaults['autolink'] = $autolink;

        return $defaults;
    }

    /**
     * Merge external links overrides into default Djot options.
     *
     * @param array{enabled: bool, internalHosts: array<string>, target: string, rel: string, nofollow: bool} $externalLinks
     * @phpstan-return DjotOptions
     */
    protected function withExternalLinks(array $externalLinks): array
    {
        $defaults = $this->defaultDjotOptions();
        $defaults['externalLinks'] = $externalLinks;

        return $defaults;
    }

    /**
     * Merge smart quotes overrides into default Djot options.
     *
     * @param array{enabled: bool, locale: string|null, openDouble: string|null, closeDouble: string|null, openSingle: string|null, closeSingle: string|null} $smartQuotes
     * @phpstan-return DjotOptions
     */
    protected function withSmartQuotes(array $smartQuotes): array
    {
        $defaults = $this->defaultDjotOptions();
        $defaults['smartQuotes'] = $smartQuotes;

        return $defaults;
    }

    /**
     * Merge mentions overrides into default Djot options.
     *
     * @param array{enabled: bool, urlTemplate: string, cssClass: string} $mentions
     * @phpstan-return DjotOptions
     */
    protected function withMentions(array $mentions): array
    {
        $defaults = $this->defaultDjotOptions();
        $defaults['mentions'] = $mentions;

        return $defaults;
    }

    /**
     * Merge semantic span overrides into default Djot options.
     *
     * @param array{enabled: bool} $semanticSpan
     * @phpstan-return DjotOptions
     */
    protected function withSemanticSpan(array $semanticSpan): array
    {
        $defaults = $this->defaultDjotOptions();
        $defaults['semanticSpan'] = $semanticSpan;

        return $defaults;
    }

    /**
     * Merge default attributes overrides into default Djot options.
     *
     * @param array{enabled: bool, defaults: array<string, array<string, string>>} $defaultAttributes
     * @phpstan-return DjotOptions
     */
    protected function withDefaultAttributes(array $defaultAttributes): array
    {
        $defaults = $this->defaultDjotOptions();
        $defaults['defaultAttributes'] = $defaultAttributes;

        return $defaults;
    }

    /**
     * Get default Djot renderer options used in tests.
     *
     * @phpstan-return DjotOptions
     */
    protected function defaultDjotOptions(): array
    {
        return [
            'codeHighlighting' => [
                'enabled' => true,
                'theme' => 'nord',
                'withGutter' => false,
            ],
            'headerAnchors' => [
                'enabled' => false,
                'symbol' => '#',
                'position' => 'after',
                'cssClass' => 'header-anchor',
                'ariaLabel' => 'Anchor link',
                'levels' => [1, 2, 3, 4, 5, 6],
            ],
            'autolink' => [
                'enabled' => false,
                'allowedSchemes' => ['https', 'http', 'mailto'],
            ],
            'externalLinks' => [
                'enabled' => false,
                'internalHosts' => [],
                'target' => '_blank',
                'rel' => 'noopener noreferrer',
                'nofollow' => false,
            ],
            'smartQuotes' => [
                'enabled' => false,
                'locale' => null,
                'openDouble' => null,
                'closeDouble' => null,
                'openSingle' => null,
                'closeSingle' => null,
            ],
            'mentions' => [
                'enabled' => false,
                'urlTemplate' => '/users/view/{username}',
                'cssClass' => 'mention',
            ],
            'semanticSpan' => [
                'enabled' => false,
            ],
            'defaultAttributes' => [
                'enabled' => false,
                'defaults' => [],
            ],
        ];
    }
}
