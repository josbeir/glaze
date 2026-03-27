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
 *
 * Supports language hints with optional `[label]` suffix:
 * - `php` -> language: php, label: php
 * - `php [Installation]` -> language: php, label: Installation
 * - `[Custom Label]` -> language: null, label: Custom Label
 * - `c++`, `c#`, `text/html` -> works with special characters
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

            $event->setHtml($this->renderCodeGroup($node, $codeBlocks));
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
     * @param \Djot\Node\Block\Div $wrapper Original div node for attribute preservation.
     * @param list<\Djot\Node\Block\CodeBlock> $blocks Code blocks.
     */
    protected function renderCodeGroup(Div $wrapper, array $blocks): string
    {
        $this->groupIndex++;
        $groupName = 'glaze-code-group-' . $this->groupIndex;

        $attrs = $this->buildWrapperAttributes($wrapper);
        $html = '<div' . $attrs . ' role="tablist">';

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
     * Build wrapper div attributes, preserving custom attributes from the original div.
     *
     * @param \Djot\Node\Block\Div $wrapper Original div node.
     */
    protected function buildWrapperAttributes(Div $wrapper): string
    {
        $classes = ['glaze-code-group'];

        // Add any additional classes from the original div (except 'code-group')
        $existingClasses = (string)$wrapper->getAttribute('class');
        foreach (preg_split('/\s+/', $existingClasses) ?: [] as $class) {
            $class = trim($class);
            if ($class !== '' && $class !== 'code-group' && !in_array($class, $classes, true)) {
                $classes[] = $class;
            }
        }

        $attrs = ' class="' . $this->escapeAttribute(implode(' ', $classes)) . '"';

        // Copy other attributes (except class)
        foreach ($wrapper->getAttributes() as $name => $value) {
            if ($name === 'class') {
                continue;
            }
            $attrs .= ' ' . $this->escapeAttribute($name) . '="' . $this->escapeAttribute((string)$value) . '"';
        }

        return $attrs;
    }

    /**
     * Parse a Djot code fence language hint with optional `[label]` suffix.
     *
     * Supports any non-whitespace characters in language names (c++, c#, text/html, etc.)
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

        // Match: optional language (any non-whitespace, non-[ chars), optional [label]
        if (preg_match('/^(?:(?<lang>[^\s\[]+)\s*)?(?:\[(?<label>[^\]]+)])?$/', $raw, $matches) !== 1) {
            return ['language' => $raw, 'label' => $raw];
        }

        $matchedLanguage = $matches['lang'] ?? null;
        $matchedLabel = $matches['label'] ?? null;

        $resolvedLanguage = $matchedLanguage !== '' ? $matchedLanguage : null;
        $resolvedLabel = $matchedLabel !== null ? trim($matchedLabel) : null;

        // Fallback label to language name or position
        if ($resolvedLabel === null) {
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
