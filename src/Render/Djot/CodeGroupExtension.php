<?php
declare(strict_types=1);

namespace Glaze\Render\Djot;

use Djot\DjotConverter;
use Djot\Event\RenderEvent;
use Djot\Extension\ExtensionInterface;
use Djot\Node\Block\CodeBlock;
use Djot\Node\Block\Div;
use Stringable;
use function htmlspecialchars;

/**
 * Djot extension that renders `::: code-group` blocks as tabbed code panes.
 */
final class CodeGroupExtension implements ExtensionInterface
{
    /**
     * Monotonic index used to generate unique radio-group names.
     */
    protected int $groupIndex = 0;

    /**
     * Constructor.
     *
     * @param \Glaze\Render\Djot\PhikiCodeBlockRenderer|null $codeBlockRenderer Optional highlighter used for code panes.
     */
    public function __construct(protected ?PhikiCodeBlockRenderer $codeBlockRenderer = null)
    {
    }

    /**
     * Register render hook for Djot `div` blocks.
     *
     * @param \Djot\DjotConverter $converter Djot converter instance.
     */
    public function register(DjotConverter $converter): void
    {
        $converter->on('render.div', function (RenderEvent $event): void {
            $node = $event->getNode();
            if (!$node instanceof Div) {
                return;
            }

            if (!$this->isCodeGroup($node)) {
                return;
            }

            $codeBlocks = $this->extractCodeBlocks($node);
            if ($codeBlocks === []) {
                return;
            }

            $event->setHtml($this->renderCodeGroup($codeBlocks));
        });
    }

    /**
     * Determine whether a div node represents a code-group container.
     *
     * @param \Djot\Node\Block\Div $node Div node.
     */
    protected function isCodeGroup(Div $node): bool
    {
        $classAttribute = trim((string)$node->getAttribute('class'));
        if ($classAttribute === '') {
            return false;
        }

        $classes = preg_split('/\s+/', $classAttribute) ?: [];

        return in_array('code-group', $classes, true);
    }

    /**
     * Extract direct child code blocks from a code-group container.
     *
     * @param \Djot\Node\Block\Div $node Div node.
     * @return list<\Djot\Node\Block\CodeBlock>
     */
    protected function extractCodeBlocks(Div $node): array
    {
        $items = [];
        foreach ($node->getChildren() as $child) {
            if (!$child instanceof CodeBlock) {
                continue;
            }

            $items[] = $child;
        }

        return $items;
    }

    /**
     * Render grouped code blocks as a DaisyUI tabs layout.
     *
     * @param list<\Djot\Node\Block\CodeBlock> $blocks Code blocks.
     */
    protected function renderCodeGroup(array $blocks): string
    {
        $this->groupIndex++;
        $groupName = 'glaze-code-group-' . $this->groupIndex;

        $html = '<div class="glaze-code-group" role="tablist">';
        foreach ($blocks as $index => $block) {
            $metadata = $this->parseLanguageMetadata($block->getLanguage(), $index + 1);
            $label = $this->escapeAttribute($metadata['label']);
            $checked = $index === 0 ? ' checked="checked"' : '';

            $html .= sprintf(
                '<input type="radio" name="%s" role="tab" class="glaze-code-group-tab" aria-label="%s"%s>',
                $this->escapeAttribute($groupName),
                $label,
                $checked,
            );
            $html .= '<div role="tabpanel" class="glaze-code-group-panel">';
            $html .= $this->renderCodePane($block, $metadata['language']);
            $html .= '</div>';
        }

        return $html . '</div>';
    }

    /**
     * Parse a Djot code fence language hint with optional `[label]` suffix.
     *
     * @param string|null $language Raw language hint from Djot code fence.
     * @param int $position One-based position in the group.
     * @return array{language: string|null, label: string}
     */
    protected function parseLanguageMetadata(?string $language, int $position): array
    {
        $raw = trim((string)$language);
        if ($raw === '') {
            return ['language' => null, 'label' => 'Code ' . $position];
        }

        $matches = [];
        preg_match('/^(?<lang>[A-Za-z0-9_-]+)?(?:\s*\[(?<label>[^\]]+)\])?.*$/', $raw, $matches);

        $resolvedLanguage = trim($matches['lang'] ?? '');
        if ($resolvedLanguage === '') {
            $resolvedLanguage = null;
        }

        $resolvedLabel = trim($matches['label'] ?? '');
        if ($resolvedLabel === '') {
            $resolvedLabel = $resolvedLanguage ?? 'Code ' . $position;
        }

        return ['language' => $resolvedLanguage, 'label' => $resolvedLabel];
    }

    /**
     * Render one code pane using Phiki when configured, otherwise plain pre/code.
     *
     * @param \Djot\Node\Block\CodeBlock $block Djot code block.
     * @param string|null $language Parsed language identifier.
     */
    protected function renderCodePane(CodeBlock $block, ?string $language): string
    {
        $effectiveBlock = new CodeBlock($block->getContent(), $language);
        if ($this->codeBlockRenderer instanceof PhikiCodeBlockRenderer) {
            return $this->codeBlockRenderer->render($effectiveBlock);
        }

        $class = $language !== null ? ' class="language-' . $this->escapeAttribute($language) . '"' : '';

        return '<pre><code' . $class . '>' . $this->escapeText(rtrim($block->getContent(), "\n")) . '</code></pre>';
    }

    /**
     * Escape text for safe HTML element content.
     */
    protected function escapeText(Stringable|string $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Escape text for safe HTML attribute values.
     */
    protected function escapeAttribute(Stringable|string $value): string
    {
        return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
