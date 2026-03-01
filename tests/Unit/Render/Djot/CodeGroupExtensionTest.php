<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Render\Djot;

use Djot\DjotConverter;
use Glaze\Render\Djot\CodeGroupExtension;
use Glaze\Render\Djot\PhikiCodeBlockRenderer;
use PHPUnit\Framework\TestCase;

/**
 * Tests for code-group Djot extension rendering.
 */
final class CodeGroupExtensionTest extends TestCase
{
    /**
     * Ensure code-group containers render tabbed panes with labels.
     */
    public function testCodeGroupRendersTabbedCodePanes(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new CodeGroupExtension(new PhikiCodeBlockRenderer()));

        $html = $converter->convert(
            "::: code-group\n\n```js [config.js]\nconst a=1\n```\n\n```ts [config.ts]\nconst b: number = 1\n```\n\n:::\n",
        );

        $this->assertStringContainsString('class="glaze-code-group"', $html);
        $this->assertStringContainsString('class="glaze-code-group-tab"', $html);
        $this->assertStringContainsString('class="glaze-code-group-panel"', $html);
        $this->assertStringContainsString('role="tablist"', $html);
        $this->assertStringContainsString('aria-label="config.js"', $html);
        $this->assertStringContainsString('aria-label="config.ts"', $html);
        $this->assertStringContainsString('role="tabpanel"', $html);
        $this->assertStringContainsString('class="phiki', $html);
        $this->assertStringContainsString('data-language="javascript"', $html);
        $this->assertStringContainsString('data-language="typescript"', $html);
    }

    /**
     * Ensure non code-group fenced divs keep default Djot rendering.
     */
    public function testNonCodeGroupDivKeepsDefaultRendering(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new CodeGroupExtension(new PhikiCodeBlockRenderer()));

        $html = $converter->convert("::: note\n\nText\n\n:::\n");

        $this->assertStringContainsString('<div class="note">', $html);
        $this->assertStringContainsString('<p>Text</p>', $html);
        $this->assertStringNotContainsString('glaze-code-group', $html);
    }

    /**
     * Ensure plain rendering mode still supports tabbed grouping without Phiki.
     */
    public function testCodeGroupCanRenderWithoutPhiki(): void
    {
        $converter = new DjotConverter();
        $converter->addExtension(new CodeGroupExtension());

        $html = $converter->convert(
            "::: code-group\n\n```php\necho 1;\n```\n\n```\nhello\n```\n\n:::\n",
        );

        $this->assertStringContainsString('class="glaze-code-group"', $html);
        $this->assertStringContainsString('<pre><code class="language-php">echo 1;</code></pre>', $html);
        $this->assertStringContainsString('<pre><code>hello</code></pre>', $html);
        $this->assertStringContainsString('aria-label="php"', $html);
        $this->assertStringContainsString('aria-label="Code 2"', $html);
    }
}
