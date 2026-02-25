<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Render;

use Glaze\Render\DjotRenderer;
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
        $renderer = new DjotRenderer();
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
        $renderer = new DjotRenderer();
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
        $renderer = new DjotRenderer();
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
        $renderer = new DjotRenderer();
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
        $renderer = new DjotRenderer();
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
        $renderer = new DjotRenderer();
        $html = $renderer->render('[Quick start](quick-start.dj)');

        $this->assertStringContainsString('href="quick-start"', $html);
        $this->assertStringNotContainsString('href="quick-start.dj"', $html);
    }

    /**
     * Ensure rewriting keeps query and fragment suffixes intact.
     */
    public function testRenderPreservesSuffixWhenRewritingDjotLinks(): void
    {
        $renderer = new DjotRenderer();
        $html = $renderer->render('[Guide](guide.dj?mode=full#top)');

        $this->assertStringContainsString('href="guide?mode=full#top"', $html);
    }

    /**
     * Ensure heading anchors are disabled by default.
     */
    public function testRenderDoesNotInjectHeadingAnchorsByDefault(): void
    {
        $renderer = new DjotRenderer();
        $html = $renderer->render("# Intro\n");

        $this->assertStringNotContainsString('class="header-anchor"', $html);
    }

    /**
     * Ensure heading anchors can be injected through Djot options.
     */
    public function testRenderCanInjectHeadingAnchorsWhenEnabled(): void
    {
        $renderer = new DjotRenderer();
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
     * Merge code highlighting overrides into default Djot options.
     *
     * @param array{enabled: bool, theme: string, withGutter: bool} $codeHighlighting
     * @return array{codeHighlighting: array{enabled: bool, theme: string, withGutter: bool}, headerAnchors: array{enabled: bool, symbol: string, position: string, cssClass: string, ariaLabel: string, levels: array<int>}}
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
     * @return array{codeHighlighting: array{enabled: bool, theme: string, withGutter: bool}, headerAnchors: array{enabled: bool, symbol: string, position: string, cssClass: string, ariaLabel: string, levels: array<int>}}
     */
    protected function withHeaderAnchors(array $headerAnchors): array
    {
        $defaults = $this->defaultDjotOptions();
        $defaults['headerAnchors'] = $headerAnchors;

        return $defaults;
    }

    /**
     * Get default Djot renderer options used in tests.
     *
     * @return array{codeHighlighting: array{enabled: bool, theme: string, withGutter: bool}, headerAnchors: array{enabled: bool, symbol: string, position: string, cssClass: string, ariaLabel: string, levels: array<int>}}
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
        ];
    }
}
