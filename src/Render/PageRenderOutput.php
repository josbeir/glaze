<?php
declare(strict_types=1);

namespace Glaze\Render;

use Glaze\Content\ContentPage;

/**
 * Value object returned by `PageRenderPipeline::render()`.
 *
 * Bundles together the final rendered HTML string and the toc-enriched
 * `ContentPage` produced during the same render pass. Callers can use
 * the enriched page (which carries the collected TOC entries) when
 * dispatching downstream events.
 *
 * Example:
 *   $output = $pipeline->render($config, $page, ...);
 *   echo $output->html;
 *   foreach ($output->page->toc as $entry) { ... }
 */
final readonly class PageRenderOutput
{
    /**
     * Constructor.
     *
     * @param string $html Fully rendered page HTML output.
     * @param \Glaze\Content\ContentPage $page Page instance enriched with collected TOC entries.
     */
    public function __construct(
        public string $html,
        public ContentPage $page,
    ) {
    }
}
