<?php
declare(strict_types=1);

namespace Glaze\Render\Djot;

use Djot\Node\Block\CodeBlock;
use Phiki\Grammar\Grammar;
use Phiki\Phiki;
use Phiki\Theme\Theme;

/**
 * Renders Djot code blocks using Phiki syntax highlighting.
 */
final class PhikiCodeBlockRenderer
{
    /**
     * Constructor.
     *
     * @param \Phiki\Theme\Theme|array<string, string>|string $theme Phiki theme or multi-theme mapping.
     * @param \Phiki\Phiki $phiki Phiki service.
     * @param bool $withGutter Whether line-number gutter should be rendered.
     */
    public function __construct(
        protected string|array|Theme $theme = Theme::Nord,
        protected Phiki $phiki = new Phiki(),
        protected bool $withGutter = false,
    ) {
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
