<?php
declare(strict_types=1);

namespace Glaze\Build\Event;

use Glaze\Config\BuildConfig;
use Glaze\Content\ContentPage;

/**
 * Payload for the BuildEvent::PageWritten event.
 *
 * Fired after each page's HTML file has been written to the output directory.
 * Useful for accumulating per-page data (sitemap entries, search-index records,
 * per-section page stats, etc.) that will only be finalised in BuildCompleted.
 *
 * Example — record URL for a sitemap:
 *
 * ```php
 * #[ListensTo(BuildEvent::PageWritten)]
 * public function record(PageWrittenEvent $event): void
 * {
 *     $this->urls[] = $event->page->urlPath;
 * }
 * ```
 */
final readonly class PageWrittenEvent
{
    /**
     * Constructor.
     *
     * @param \Glaze\Content\ContentPage $page The page that was written.
     * @param string $destination Absolute path to the written HTML file.
     * @param \Glaze\Config\BuildConfig $config Active build configuration.
     */
    public function __construct(
        public ContentPage $page,
        public string $destination,
        public BuildConfig $config,
    ) {
    }
}
