<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Render;

use Djot\Extension\SemanticSpanExtension;
use Glaze\Config\DjotOptions;
use Glaze\Config\SiteConfig;
use Glaze\Render\Djot\DjotConverterFactory;
use Glaze\Render\Djot\PhikiThemeResolver;
use Glaze\Render\DjotRenderer;
use Glaze\Render\RenderResult;
use Glaze\Support\ResourcePathRewriter;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Djot rendering with optional Phiki highlighting.
 */
final class DjotRendererTest extends TestCase
{
    /**
     * Ensure default rendering highlights fenced code blocks through Phiki.
     */
    public function testRenderHighlightsCodeBlocksByDefault(): void
    {
        $renderer = $this->createRenderer();
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
        $renderer = $this->createRenderer();
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
        $renderer = $this->createRenderer();
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
     * Ensure named multi-theme configuration produces Phiki theme variables.
     */
    public function testRenderSupportsMultipleCodeHighlightingThemes(): void
    {
        $renderer = $this->createRenderer();
        $html = $renderer->render(
            "```php\necho 1;\n```\n",
            $this->withCodeHighlighting([
                'enabled' => true,
                'theme' => 'nord',
                'themes' => ['dark' => 'github-dark', 'light' => 'github-light'],
                'withGutter' => false,
            ]),
        );

        $this->assertStringContainsString('class="phiki', $html);
        $this->assertStringContainsString('phiki-themes', $html);
        $this->assertStringContainsString('github-light', $html);
        $this->assertStringContainsString('--phiki-light-background-color', $html);
    }

    /**
     * Ensure `neon` fenced blocks are highlighted via the YAML grammar alias.
     */
    public function testRenderMapsNeonFenceLanguageToYamlGrammar(): void
    {
        $renderer = $this->createRenderer();
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
        $renderer = $this->createRenderer();
        $html = $renderer->render("```djot\n# Intro\n```\n");

        $this->assertStringContainsString('class="phiki', $html);
        $this->assertMatchesRegularExpression('/language-djot/i', $html);
        $this->assertMatchesRegularExpression('/data-language="djot"/i', $html);
    }

    /**
     * Ensure code-group blocks render as tabbed panes with labels.
     */
    public function testRenderTransformsCodeGroupIntoTabbedHtml(): void
    {
        $renderer = $this->createRenderer();
        $html = $renderer->render(
            "::: code-group\n\n```js [config.js]\nconst config = {}\n```\n\n```ts [config.ts]\nconst config: Record<string, mixed> = {}\n```\n\n:::\n",
        );

        $this->assertStringContainsString('class="glaze-code-group"', $html);
        $this->assertStringContainsString('class="glaze-code-group-tab"', $html);
        $this->assertStringContainsString('role="tablist"', $html);
        $this->assertStringContainsString('aria-label="config.js"', $html);
        $this->assertStringContainsString('aria-label="config.ts"', $html);
        $this->assertStringContainsString('class="phiki', $html);
    }

    /**
     * Ensure code-group transformation still works when code highlighting is disabled.
     */
    public function testRenderTransformsCodeGroupWithoutPhikiWhenDisabled(): void
    {
        $renderer = $this->createRenderer();
        $html = $renderer->render(
            "::: code-group\n\n```php [example.php]\necho 1;\n```\n\n:::\n",
            $this->withCodeHighlighting(['enabled' => false, 'theme' => 'nord', 'withGutter' => false]),
        );

        $this->assertStringContainsString('class="glaze-code-group"', $html);
        $this->assertStringContainsString('class="glaze-code-group-tab"', $html);
        $this->assertStringContainsString('aria-label="example.php"', $html);
        $this->assertStringContainsString('<pre><code class="language-php">echo 1;</code></pre>', $html);
        $this->assertStringNotContainsString('class="phiki', $html);
    }

    /**
     * Ensure internal Djot links are rendered without a file extension.
     */
    public function testRenderRewritesInternalDjotLinksToExtensionlessPaths(): void
    {
        $renderer = $this->createRenderer();
        $html = $renderer->render('[Quick start](quick-start.dj)');

        $this->assertStringContainsString('href="quick-start"', $html);
        $this->assertStringNotContainsString('href="quick-start.dj"', $html);
    }

    /**
     * Ensure rewriting keeps query and fragment suffixes intact.
     */
    public function testRenderPreservesSuffixWhenRewritingDjotLinks(): void
    {
        $renderer = $this->createRenderer();
        $html = $renderer->render('[Guide](guide.dj?mode=full#top)');

        $this->assertStringContainsString('href="guide?mode=full#top"', $html);
    }

    /**
     * Ensure custom internal link extension rewrites page-relative links with site base path.
     */
    public function testRenderUsesConfiguredInternalLinkExtensionForPageRelativeLink(): void
    {
        $renderer = $this->createRenderer();
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
        $renderer = $this->createRenderer();
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
        $renderer = $this->createRenderer();
        $html = $renderer->render("# Intro\n");

        $this->assertStringNotContainsString('class="header-anchor"', $html);
    }

    /**
     * Ensure heading anchors can be injected through Djot options.
     */
    public function testRenderCanInjectHeadingAnchorsWhenEnabled(): void
    {
        $renderer = $this->createRenderer();
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
        $renderer = $this->createRenderer();
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
        $renderer = $this->createRenderer();
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
        $renderer = $this->createRenderer();
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
        $renderer = $this->createRenderer();
        $result = $renderer->renderWithToc("## Getting Started\n");

        $this->assertStringContainsString('id="Getting-Started"', $result->html);
        $this->assertSame('Getting-Started', $result->toc[0]->id);
    }

    /**
     * Ensure autolink extension is inactive by default.
     */
    public function testRenderDoesNotAutolinkUrlsByDefault(): void
    {
        $renderer = $this->createRenderer();
        $html = $renderer->render("Visit https://example.com today.\n");

        $this->assertStringNotContainsString('<a href="https://example.com">', $html);
    }

    /**
     * Ensure autolink extension converts bare URLs to anchor links when enabled.
     */
    public function testRenderAutolinksUrlsWhenEnabled(): void
    {
        $renderer = $this->createRenderer();
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
        $renderer = $this->createRenderer();
        $html = $renderer->render("[link](https://external.com)\n");

        $this->assertStringNotContainsString('target="_blank"', $html);
    }

    /**
     * Ensure external links extension adds target and rel attributes when enabled.
     */
    public function testRenderAddsExternalLinkAttributesWhenEnabled(): void
    {
        $renderer = $this->createRenderer();
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
        $renderer = $this->createRenderer();
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
        $renderer = $this->createRenderer();
        $html = $renderer->render("She said \"hello\".\n");

        // Djot already outputs English curly quotes by default; German opening quote should not appear
        $this->assertStringNotContainsString("\u{201E}", $html);
    }

    /**
     * Ensure smart quotes extension uses locale-specific quote characters when enabled with a locale.
     */
    public function testRenderAppliesSmartQuotesWhenEnabled(): void
    {
        $renderer = $this->createRenderer();
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
        $renderer = $this->createRenderer();
        $html = $renderer->render("Hello @johndoe!\n");

        $this->assertStringNotContainsString('/users/view/johndoe', $html);
    }

    /**
     * Ensure mentions extension converts @username to a link when enabled.
     */
    public function testRenderExpandsMentionsToLinksWhenEnabled(): void
    {
        $renderer = $this->createRenderer();
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
        $renderer = $this->createRenderer();
        $html = $renderer->render("[text]{kbd=Enter}\n");

        $this->assertStringNotContainsString('<kbd>', $html);
    }

    /**
     * Ensure semantic span extension wraps spans in semantic HTML elements when enabled.
     */
    public function testRenderAppliesSemanticSpansWhenEnabled(): void
    {
        if (!class_exists(SemanticSpanExtension::class)) {
            $this->markTestSkipped('SemanticSpanExtension is not available in this Djot version.');
        }

        $renderer = $this->createRenderer();
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
        $renderer = $this->createRenderer();
        $html = $renderer->render("# Heading\n");

        $this->assertStringNotContainsString('class="my-heading"', $html);
    }

    /**
     * Ensure default attributes extension injects configured attributes onto elements when enabled.
     */
    public function testRenderAddsDefaultAttributesToElementsWhenEnabled(): void
    {
        $renderer = $this->createRenderer();
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
     * Build a renderer instance with concrete dependencies.
     */
    protected function createRenderer(): DjotRenderer
    {
        return new DjotRenderer(
            new DjotConverterFactory(new ResourcePathRewriter(), new PhikiThemeResolver()),
        );
    }

    /**
     * Merge code highlighting overrides into default Djot options.
     *
     * @param array{enabled: bool, theme: string, themes?: array<string, string>, withGutter: bool} $codeHighlighting
     */
    protected function withCodeHighlighting(array $codeHighlighting): DjotOptions
    {
        $defaults = $this->defaultDjotOptionsConfig();
        $defaults['codeHighlighting'] = $codeHighlighting;

        return DjotOptions::fromProjectConfig($defaults);
    }

    /**
     * Merge heading anchor overrides into default Djot options.
     *
     * @param array{enabled: bool, symbol: string, position: string, cssClass: string, ariaLabel: string, levels: array<int>} $headerAnchors
     */
    protected function withHeaderAnchors(array $headerAnchors): DjotOptions
    {
        $defaults = $this->defaultDjotOptionsConfig();
        $defaults['headerAnchors'] = $headerAnchors;

        return DjotOptions::fromProjectConfig($defaults);
    }

    /**
     * Merge autolink overrides into default Djot options.
     *
     * @param array{enabled: bool, allowedSchemes: array<string>} $autolink
     */
    protected function withAutolink(array $autolink): DjotOptions
    {
        $defaults = $this->defaultDjotOptionsConfig();
        $defaults['autolink'] = $autolink;

        return DjotOptions::fromProjectConfig($defaults);
    }

    /**
     * Merge external links overrides into default Djot options.
     *
     * @param array{enabled: bool, internalHosts: array<string>, target: string, rel: string, nofollow: bool} $externalLinks
     */
    protected function withExternalLinks(array $externalLinks): DjotOptions
    {
        $defaults = $this->defaultDjotOptionsConfig();
        $defaults['externalLinks'] = $externalLinks;

        return DjotOptions::fromProjectConfig($defaults);
    }

    /**
     * Merge smart quotes overrides into default Djot options.
     *
     * @param array{enabled: bool, locale: string|null, openDouble: string|null, closeDouble: string|null, openSingle: string|null, closeSingle: string|null} $smartQuotes
     */
    protected function withSmartQuotes(array $smartQuotes): DjotOptions
    {
        $defaults = $this->defaultDjotOptionsConfig();
        $defaults['smartQuotes'] = $smartQuotes;

        return DjotOptions::fromProjectConfig($defaults);
    }

    /**
     * Merge mentions overrides into default Djot options.
     *
     * @param array{enabled: bool, urlTemplate: string, cssClass: string} $mentions
     */
    protected function withMentions(array $mentions): DjotOptions
    {
        $defaults = $this->defaultDjotOptionsConfig();
        $defaults['mentions'] = $mentions;

        return DjotOptions::fromProjectConfig($defaults);
    }

    /**
     * Merge semantic span overrides into default Djot options.
     *
     * @param array{enabled: bool} $semanticSpan
     */
    protected function withSemanticSpan(array $semanticSpan): DjotOptions
    {
        $defaults = $this->defaultDjotOptionsConfig();
        $defaults['semanticSpan'] = $semanticSpan;

        return DjotOptions::fromProjectConfig($defaults);
    }

    /**
     * Merge default attributes overrides into default Djot options.
     *
     * @param array{enabled: bool, defaults: array<string, array<string, string>>} $defaultAttributes
     */
    protected function withDefaultAttributes(array $defaultAttributes): DjotOptions
    {
        $defaults = $this->defaultDjotOptionsConfig();
        $defaults['defaultAttributes'] = $defaultAttributes;

        return DjotOptions::fromProjectConfig($defaults);
    }

    /**
     * Get default Djot renderer options used in tests.
     */
    protected function defaultDjotOptions(): DjotOptions
    {
        return DjotOptions::fromProjectConfig($this->defaultDjotOptionsConfig());
    }

    /**
     * Get default raw Djot renderer options map used in tests.
     *
     * @return array<string, mixed>
     */
    protected function defaultDjotOptionsConfig(): array
    {
        return [
            'codeHighlighting' => [
                'enabled' => true,
                'theme' => 'nord',
                'themes' => [],
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
