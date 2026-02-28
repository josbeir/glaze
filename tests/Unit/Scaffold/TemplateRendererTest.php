<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Scaffold;

use Glaze\Scaffold\TemplateRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Tests for TemplateRenderer.
 */
final class TemplateRendererTest extends TestCase
{
    /**
     * Ensure known tokens are replaced and unknown tokens are left intact.
     */
    public function testRenderReplacesKnownTokensAndPreservesUnknown(): void
    {
        $renderer = new TemplateRenderer();

        $result = $renderer->render(
            'Hello, {name}! Unknown: {unknown}.',
            ['name' => 'World'],
        );

        $this->assertSame('Hello, World! Unknown: {unknown}.', $result);
    }

    /**
     * Ensure rendering with an empty variables map returns the template unchanged.
     */
    public function testRenderWithEmptyVariablesReturnsTemplate(): void
    {
        $renderer = new TemplateRenderer();
        $template = 'No {substitution} here.';

        $this->assertSame($template, $renderer->render($template, []));
    }

    /**
     * Ensure multiple occurrences of the same token are all replaced.
     */
    public function testRenderReplacesAllOccurrences(): void
    {
        $renderer = new TemplateRenderer();

        $result = $renderer->render(
            '{site} / {site} — powered by {site}',
            ['site' => 'Glaze'],
        );

        $this->assertSame('Glaze / Glaze — powered by Glaze', $result);
    }

    /**
     * Ensure JSON-style templates remain valid after substitution.
     *
     * JSON structural braces must not be disturbed by the renderer.
     */
    public function testRenderPreservesJsonStructuralBraces(): void
    {
        $renderer = new TemplateRenderer();
        $template = <<<JSON
{
    "name": {nameJson},
    "scripts": {
        "build": "vite build"
    }
}
JSON;

        $result = $renderer->render($template, ['nameJson' => '"my-site"']);

        $this->assertStringContainsString('"name": "my-site"', $result);
        $this->assertStringContainsString('"scripts": {', $result);
        $this->assertStringContainsString('"build": "vite build"', $result);
    }

    /**
     * Ensure token substitution is case-sensitive.
     */
    public function testRenderIsCaseSensitive(): void
    {
        $renderer = new TemplateRenderer();

        $result = $renderer->render(
            '{Name} and {name}',
            ['name' => 'lower'],
        );

        $this->assertSame('{Name} and lower', $result);
    }

    /**
     * Ensure all provided variables are substituted in a single pass.
     */
    public function testRenderAppliesAllVariablesInOnePass(): void
    {
        $renderer = new TemplateRenderer();

        $result = $renderer->render(
            '{a}{b}{c}',
            ['a' => 'X', 'b' => 'Y', 'c' => 'Z'],
        );

        $this->assertSame('XYZ', $result);
    }
}
