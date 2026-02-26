<?php
declare(strict_types=1);

namespace Glaze\Render\Djot;

use Djot\DjotConverter;
use Djot\Event\RenderEvent;
use Djot\Extension\TableOfContentsExtension;
use Djot\Node\Block\Heading;
use Djot\Node\Block\Paragraph;
use Djot\Node\Inline\Text;
use Djot\Node\Node;

/**
 * Djot extension that extends the vendor TableOfContentsExtension with:
 *
 * - Automatic heading `id` attribute assignment so TOC anchor links resolve
 *   even when `HeadingPermalinksExtension` is not in use.
 * - An inline `[[toc]]` directive: a paragraph containing only `[[toc]]` is
 *   replaced with the rendered TOC HTML exactly where it appears in the
 *   document (versus the vendor's top/bottom auto-position mode).
 * - A typed `getEntries(): TocEntry[]` accessor that wraps the vendor's raw
 *   `getToc()` arrays into proper value objects for template consumption.
 *
 * All heading collection, nested list HTML generation, and `minLevel`/`maxLevel`
 * filtering are delegated to the parent `TableOfContentsExtension`.
 *
 * Example Djot usage:
 * ```
 * [[toc]]
 *
 * ## Introduction
 * ## Installation
 * ```
 *
 * Example template usage:
 * ```php
 * foreach ($this->toc() as $entry) { ... }
 * ```
 */
final class TocExtension extends TableOfContentsExtension
{
    /**
     * Sentinel string injected in place of `[[toc]]` during the render pass.
     *
     * An HTML comment is used because Djot passes raw HTML comments through
     * verbatim, so the sentinel survives the render without modification and
     * can safely be replaced with real TOC HTML once all entries are collected.
     */
    public const TOC_SENTINEL = '<!-- __GLAZE_TOC__ -->';

    /**
     * Magic directive text that triggers inline TOC rendering.
     */
    protected const TOC_DIRECTIVE = '[[toc]]';

    /**
     * Register heading id-injection and [[toc]] directive hooks, then delegate
     * heading collection to the parent extension.
     *
     * @param \Djot\DjotConverter $converter Djot converter instance.
     */
    public function register(DjotConverter $converter): void
    {
        // Parent registers the render.heading listener that collects entries.
        parent::register($converter);

        $tracker = $converter->getHeadingIdTracker();

        // Inject id attributes on heading elements that fall within the
        // configured level range so TOC anchor links always resolve, even
        // when HeadingPermalinksExtension is not active.
        $converter->on('render.heading', function (RenderEvent $event) use ($tracker): void {
            $node = $event->getNode();
            if (!$node instanceof Heading) {
                return;
            }

            if ($node->getLevel() < $this->minLevel || $node->getLevel() > $this->maxLevel) {
                return;
            }

            $id = $tracker->getIdForHeading($node);
            if ($id !== '' && !$node->hasAttribute('id')) {
                $node->setAttribute('id', $id);
            }
        });

        // Detect a paragraph whose sole content is [[toc]] and replace it
        // with the sentinel. The sentinel is swapped for real TOC HTML by
        // injectToc() after the full render pass completes.
        $converter->on('render.paragraph', function (RenderEvent $event): void {
            $node = $event->getNode();
            if (!$node instanceof Paragraph) {
                return;
            }

            if ($this->extractNodeText($node) !== self::TOC_DIRECTIVE) {
                return;
            }

            $event->setHtml(self::TOC_SENTINEL . "\n");
        });
    }

    /**
     * Return the collected TOC entries as typed value objects, in document order.
     *
     * @return list<\Glaze\Render\Djot\TocEntry>
     */
    public function getEntries(): array
    {
        return array_map(
            static fn(array $entry): TocEntry => new TocEntry(
                level: $entry['level'],
                id: $entry['id'],
                text: $entry['text'],
            ),
            $this->getToc(),
        );
    }

    /**
     * Replace the sentinel placeholder in the rendered HTML with the TOC markup.
     *
     * Must be called after `DjotConverter::convert()` returns. Returns the HTML
     * unchanged when no `[[toc]]` directive was found in the source.
     *
     * @param string $html Rendered HTML from `DjotConverter::convert()`.
     * @return string HTML with the sentinel replaced by the inline TOC, or the
     *   original HTML unchanged if no sentinel was emitted.
     */
    public function injectToc(string $html): string
    {
        if (!str_contains($html, self::TOC_SENTINEL)) {
            return $html;
        }

        return str_replace(self::TOC_SENTINEL, $this->getTocHtml(), $html);
    }

    /**
     * Concatenate the plain-text content from all direct `Text` children of a node.
     *
     * This is intentionally shallow -- paragraph nodes contain flat inline children --
     * and is used solely to detect the `[[toc]]` directive text.
     *
     * @param \Djot\Node\Node $node Node whose children are inspected.
     */
    protected function extractNodeText(Node $node): string
    {
        $text = '';
        foreach ($node->getChildren() as $child) {
            if ($child instanceof Text) {
                $text .= $child->getContent();
            }
        }

        return $text;
    }
}
