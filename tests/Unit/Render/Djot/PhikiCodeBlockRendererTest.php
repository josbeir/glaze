<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Render\Djot;

use Djot\Node\Block\CodeBlock;
use Glaze\Render\Djot\PhikiCodeBlockRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Phiki Djot code block renderer.
 */
final class PhikiCodeBlockRendererTest extends TestCase
{
    /**
     * Ensure known languages are mapped to corresponding grammar names.
     */
    public function testRenderUsesRequestedLanguageGrammar(): void
    {
        $renderer = new PhikiCodeBlockRenderer();
        $html = $renderer->render(new CodeBlock("echo 1;\n", 'php'));

        $this->assertStringContainsString('class="phiki', $html);
        $this->assertStringContainsString('language-php', $html);
        $this->assertStringContainsString('data-language="php"', $html);
    }

    /**
     * Ensure unknown language hints fall back to plain text grammar.
     */
    public function testRenderFallsBackToTxtGrammarForUnknownLanguage(): void
    {
        $renderer = new PhikiCodeBlockRenderer();
        $html = $renderer->render(new CodeBlock("hello\n", 'my-custom-lang'));

        $this->assertStringContainsString('class="phiki', $html);
        $this->assertStringNotContainsString('language-my-custom-lang', $html);
        $this->assertStringNotContainsString('data-language="my-custom-lang"', $html);
    }

    /**
     * Ensure the `neon` language hint maps to the YAML grammar.
     */
    public function testRenderMapsNeonLanguageToYamlGrammar(): void
    {
        $renderer = new PhikiCodeBlockRenderer();
        $html = $renderer->render(new CodeBlock("site:\n  title: Glaze\n", 'neon'));

        $this->assertStringContainsString('class="phiki', $html);
        $this->assertStringContainsString('language-yaml', $html);
        $this->assertStringContainsString('data-language="yaml"', $html);
        $this->assertStringNotContainsString('language-txt', $html);
    }

    /**
     * Ensure the `djot` language hint uses the custom Djot grammar.
     */
    public function testRenderUsesCustomDjotGrammar(): void
    {
        $renderer = new PhikiCodeBlockRenderer();
        $html = $renderer->render(new CodeBlock("# Title\n\nParagraph\n", 'djot'));

        $this->assertStringContainsString('class="phiki', $html);
        $this->assertStringContainsString('language-Djot', $html);
        $this->assertStringContainsString('data-language="Djot"', $html);
    }

    /**
     * Ensure Djot frontmatter is handled by the custom grammar.
     */
    public function testRenderDjotFrontmatterWithPlusFence(): void
    {
        $renderer = new PhikiCodeBlockRenderer();
        $html = $renderer->render(new CodeBlock("+++\ntitle: Hello\n+++\n\n# Intro\n", 'djot'));

        $this->assertStringContainsString('class="phiki', $html);
        $this->assertStringContainsString('language-Djot', $html);
        $this->assertStringContainsString('+++', $html);
    }
}
