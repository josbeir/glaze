<?php
declare(strict_types=1);

namespace Glaze\Build\Event;

use Djot\DjotConverter;
use Glaze\Config\BuildConfig;
use Glaze\Content\ContentPage;

/**
 * Payload for the BuildEvent::DjotConverterCreated event.
 *
 * Fired after a Djot converter is assembled for a page and before it converts
 * the source content to HTML. Listeners can register additional Djot extensions
 * on `$converter` to customize parsing or rendering behaviour.
 *
 * Example:
 *
 * ```php
 * #[ListensTo(BuildEvent::DjotConverterCreated)]
 * public function registerCallouts(DjotConverterCreatedEvent $event): void
 * {
 *     $event->converter->addExtension(new CalloutExtension());
 * }
 * ```
 */
final readonly class DjotConverterCreatedEvent
{
    /**
     * Constructor.
     *
     * @param \Djot\DjotConverter $converter Djot converter instance for the current page.
     * @param \Glaze\Content\ContentPage $page Page being rendered.
     * @param \Glaze\Config\BuildConfig $config Active build configuration.
     */
    public function __construct(
        public DjotConverter $converter,
        public ContentPage $page,
        public BuildConfig $config,
    ) {
    }
}
