<?php
declare(strict_types=1);

namespace Glaze\Render;

/**
 * Value object returned by `DjotRenderer::renderWithToc()`.
 *
 * Bundles together the final rendered HTML and the list of table-of-contents
 * entries collected during the same render pass so callers never need to
 * parse the document twice.
 *
 * Example:
 *   $result = $renderer->renderWithToc($source, $djot);
 *   echo $result->html;
 *   foreach ($result->toc as $entry) { ... }
 */
final readonly class RenderResult
{
    /**
     * Constructor.
     *
     * @param string $html Fully rendered HTML, including any injected TOC markup.
     * @param list<\Glaze\Render\Djot\TocEntry> $toc TOC entries in document order.
     */
    public function __construct(
        public string $html,
        public array $toc,
    ) {
    }
}
