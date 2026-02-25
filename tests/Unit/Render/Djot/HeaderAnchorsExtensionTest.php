<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Render\Djot;

use Djot\DjotConverter;
use Glaze\Render\Djot\HeaderAnchorsExtension;
use PHPUnit\Framework\TestCase;

/**
 * Tests for Djot header anchor extension registration and rendering behavior.
 */
final class HeaderAnchorsExtensionTest extends TestCase
{
    /**
     * Ensure configured anchor links are appended to matching heading levels.
     */
    public function testRegisterInjectsAnchorsForConfiguredLevels(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new HeaderAnchorsExtension(
            symbol: '¶',
            position: 'after',
            cssClass: 'docs-anchor',
            ariaLabel: 'Copy heading URL',
            levels: [2, 3],
        ));

        $html = $converter->convert("## Setup\n");

        $this->assertStringContainsString('id="Setup"', $html);
        $this->assertStringContainsString('href="#Setup"', $html);
        $this->assertStringContainsString('class="docs-anchor"', $html);
        $this->assertStringContainsString('aria-label="Copy heading URL"', $html);
        $this->assertStringContainsString('>¶</a>', $html);
    }

    /**
     * Ensure anchor links can be prepended before heading text.
     */
    public function testRegisterCanPrependAnchorBeforeHeadingText(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new HeaderAnchorsExtension(
            symbol: '#',
            position: 'before',
            cssClass: 'header-anchor',
            ariaLabel: 'Anchor link',
            levels: [2],
        ));

        $html = $converter->convert("## Setup\n");

        $this->assertStringContainsString('<section id="Setup">', $html);
        $this->assertStringContainsString('<h2><a href="#Setup" class="header-anchor" aria-label="Anchor link">#</a> Setup</h2>', $html);
    }

    /**
     * Ensure headings outside configured levels remain unchanged.
     */
    public function testRegisterSkipsHeadingsOutsideConfiguredLevels(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new HeaderAnchorsExtension(levels: [2]));

        $html = $converter->convert("# Intro\n");

        $this->assertStringContainsString('<section id="Intro">', $html);
        $this->assertStringContainsString('<h1>Intro</h1>', $html);
        $this->assertStringNotContainsString('class="header-anchor"', $html);
    }
}
