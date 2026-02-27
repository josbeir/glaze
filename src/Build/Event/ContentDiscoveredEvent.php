<?php
declare(strict_types=1);

namespace Glaze\Build\Event;

use Glaze\Config\BuildConfig;

/**
 * Payload for the BuildEvent::ContentDiscovered event.
 *
 * Fired after content discovery and draft filtering, before rendering begins.
 * The `$pages` property is intentionally mutable so listeners can inject virtual
 * pages, reorder the list, augment metadata, or remove entries.
 *
 * Example — inject a synthetic RSS feed page:
 *
 * ```php
 * #[ListensTo(BuildEvent::ContentDiscovered)]
 * public function injectFeedPage(ContentDiscoveredEvent $event): void
 * {
 *     $event->pages[] = ContentPage::virtual('/feed.xml', ...);
 * }
 * ```
 */
final class ContentDiscoveredEvent
{
    /**
     * Constructor.
     *
     * @param array<\Glaze\Content\ContentPage> $pages Discovered pages. Mutate to inject or filter.
     * @param \Glaze\Config\BuildConfig $config Active build configuration.
     */
    public function __construct(
        public array $pages,
        public readonly BuildConfig $config,
    ) {
    }
}
