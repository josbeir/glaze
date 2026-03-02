<?php
declare(strict_types=1);

namespace Glaze\Render\Djot;

use Djot\Node\Block\CodeBlock;
use Glaze\Support\FileCache;
use Phiki\Grammar\Grammar;
use Phiki\Phiki;
use Phiki\Theme\Theme;
use Psr\SimpleCache\CacheInterface;

/**
 * Renders Djot code blocks using Phiki syntax highlighting.
 *
 * Each instance wires a PSR-16 {@see CacheInterface} to the underlying {@see Phiki}
 * service so that the final rendered HTML for every unique (code, grammar, theme, gutter)
 * combination can be cached and reused by the selected PSR-16 store.
 */
final class PhikiCodeBlockRenderer
{
    /**
     * Constructor.
     *
     * @param \Phiki\Theme\Theme|array<string, string>|string $theme Phiki theme or multi-theme mapping.
     * @param \Phiki\Phiki $phiki Phiki service.
     * @param bool $withGutter Whether line-number gutter should be rendered.
     * @param \Psr\SimpleCache\CacheInterface|null $cache PSR-16 cache for rendered HTML output.
     */
    public function __construct(
        protected string|array|Theme $theme = Theme::Nord,
        protected Phiki $phiki = new Phiki(),
        protected bool $withGutter = false,
        ?CacheInterface $cache = null,
    ) {
        $this->phiki->cache($cache ?? new FileCache(sys_get_temp_dir() . '/glaze-phiki-html-cache'));
        $this->registerCustomGrammarAliases();
    }

    /**
     * Register custom language aliases used by Glaze docs/content.
     */
    protected function registerCustomGrammarAliases(): void
    {
        $this->phiki->grammar('djot', dirname(__DIR__, 3) . '/resources/grammars/djot.json');
        $this->phiki->alias('dj', 'djot');
        $this->phiki->alias('neon', 'yaml');
    }

    /**
     * Render a Djot code block to highlighted HTML.
     *
     * @param \Djot\Node\Block\CodeBlock $codeBlock Djot code block.
     */
    public function render(CodeBlock $codeBlock): string
    {
        $grammar = $this->detectGrammar($codeBlock->getLanguage());
        $code = rtrim($codeBlock->getContent(), "\n");

        return $this->phiki->codeToHtml($code, $grammar, $this->theme)
            ->withGutter($this->withGutter)
            ->toString();
    }

    /**
     * Resolve grammar from a Djot code fence language hint.
     *
     * @param string|null $language Code fence language value.
     * @return \Phiki\Grammar\Grammar|string
     */
    protected function detectGrammar(?string $language): string|Grammar
    {
        if (!is_string($language) || trim($language) === '') {
            return Grammar::Txt;
        }

        preg_match('/[a-zA-Z0-9_-]+/', $language, $matches);
        $candidate = strtolower($matches[0] ?? '');
        if ($candidate === '') {
            return Grammar::Txt;
        }

        if (!$this->phiki->environment->grammars->has($candidate)) {
            return Grammar::Txt;
        }

        return $candidate;
    }
}
