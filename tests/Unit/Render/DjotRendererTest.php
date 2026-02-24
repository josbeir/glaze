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
            ['enabled' => false, 'theme' => 'nord', 'withGutter' => false],
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
            ['enabled' => true, 'theme' => 'nord', 'withGutter' => true],
        );

        $this->assertStringContainsString('class="phiki', $html);
        $this->assertStringNotContainsString('language-php', $html);
        $this->assertStringNotContainsString('data-language="php"', $html);
        $this->assertStringContainsString('line-number', $html);
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
}
