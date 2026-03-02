<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Render\Djot;

use Djot\Node\Block\CodeBlock;
use Glaze\Render\Djot\PhikiCodeBlockRenderer;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\CacheInterface;

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
        $this->assertMatchesRegularExpression('/language-djot/i', $html);
        $this->assertMatchesRegularExpression('/data-language="djot"/i', $html);
    }

    /**
     * Ensure Djot frontmatter is handled by the custom grammar.
     */
    public function testRenderDjotFrontmatterWithPlusFence(): void
    {
        $renderer = new PhikiCodeBlockRenderer();
        $html = $renderer->render(new CodeBlock("+++\ntitle: Hello\n+++\n\n# Intro\n", 'djot'));

        $this->assertStringContainsString('class="phiki', $html);
        $this->assertMatchesRegularExpression('/language-djot/i', $html);
        $this->assertStringContainsString('+++', $html);
    }

    /**
     * Ensure Sugar templates are highlighted with the custom Sugar grammar.
     */
    public function testRenderUsesCustomSugarGrammar(): void
    {
        $renderer = new PhikiCodeBlockRenderer();
                $html = $renderer->render(new CodeBlock(<<<'SUGAR'
<s-template s:if="$show">
    <?= $title ?>
</s-template>
SUGAR, 'sugar'));

        $this->assertStringContainsString('class="phiki', $html);
        $this->assertStringContainsString('language-sugar', $html);
        $this->assertStringContainsString('data-language="sugar"', $html);
    }

    /**
     * Ensure repeated renders of the same block produce identical output (cache round-trip sanity check).
     */
    public function testRenderProducesIdenticalOutputOnSubsequentCallsForSameBlock(): void
    {
        $renderer = new PhikiCodeBlockRenderer();
        $block = new CodeBlock("echo 1;\n", 'php');

        $this->assertSame($renderer->render($block), $renderer->render($block));
    }

    /**
     * Ensure consecutive renders of the same block use the cache and avoid re-processing.
     */
    public function testRenderServesCachedHtmlOnSubsequentCallsWithSameBlock(): void
    {
        $setCalls = 0;
        $getCalls = 0;

        $spy = new class ($setCalls, $getCalls) implements CacheInterface {
            /**
             * @var array<string, mixed>
             */
            private array $store = [];

            public function __construct(private int &$setCount, private int &$getCount)
            {
            }

            public function get(string $key, mixed $default = null): mixed
            {
                ++$this->getCount;

                return array_key_exists($key, $this->store) ? $this->store[$key] : $default;
            }

            public function set(string $key, mixed $value, mixed $ttl = null): bool
            {
                ++$this->setCount;
                $this->store[$key] = $value;

                return true;
            }

            public function has(string $key): bool
            {
                return array_key_exists($key, $this->store);
            }

            public function delete(string $key): bool
            {
                unset($this->store[$key]);

                return true;
            }

            public function clear(): bool
            {
                $this->store = [];

                return true;
            }

            public function getMultiple(iterable $keys, mixed $default = null): iterable
            {
                $result = [];
                foreach ($keys as $k) {
                    $result[$k] = $this->get($k, $default);
                }

                return $result;
            }

            /**
             * @param iterable<string, mixed> $values
             */
            public function setMultiple(iterable $values, mixed $ttl = null): bool
            {
                foreach ($values as $k => $v) {
                    $this->set((string)$k, $v);
                }

                return true;
            }

            public function deleteMultiple(iterable $keys): bool
            {
                foreach ($keys as $k) {
                    $this->delete($k);
                }

                return true;
            }
        };

        $renderer = new PhikiCodeBlockRenderer(cache: $spy);
        $block = new CodeBlock("echo 1;\n", 'php');

        $first = $renderer->render($block);
        $second = $renderer->render($block);

        // The HTML output must be identical on both calls.
        $this->assertSame($first, $second);

        // The first render must have stored one entry and the second must have retrieved it.
        $this->assertSame(1, $setCalls, 'Cache set() must be called exactly once for a unique block');
        $this->assertSame(1, $getCalls, 'Cache get() must be called exactly once on the second render (after has() hit)');
    }
}
