<?php
declare(strict_types=1);

namespace Glaze\Build\Event;

use Glaze\Config\BuildConfig;
use Glaze\Content\ContentPage;

/**
 * Payload for the BuildEvent::PageRendered event.
 *
 * Fired after each page is rendered to HTML, before it is written to disk.
 * The `$html` property is intentionally mutable so listeners can post-process
 * the rendered output.
 *
 * Example — minify output HTML:
 *
 * ```php
 * #[ListensTo(BuildEvent::PageRendered)]
 * public function minify(PageRenderedEvent $event): void
 * {
 *     $event->html = $this->minifier->minify($event->html);
 * }
 * ```
 *
 * Example — extract headings for a search index:
 *
 * ```php
 * #[ListensTo(BuildEvent::PageRendered)]
 * public function index(PageRenderedEvent $event): void
 * {
 *     $this->index[] = [
 *         'url'     => $event->page->urlPath,
 *         'title'   => $event->page->title,
 *         'content' => strip_tags($event->html),
 *     ];
 * }
 * ```
 */
final class PageRenderedEvent
{
    /**
     * Constructor.
     *
     * @param \Glaze\Content\ContentPage $page The page that was rendered.
     * @param string $html Rendered HTML output. Mutate to transform output.
     * @param \Glaze\Config\BuildConfig $config Active build configuration.
     */
    public function __construct(
        public readonly ContentPage $page,
        public string $html,
        public readonly BuildConfig $config,
    ) {
    }
}
