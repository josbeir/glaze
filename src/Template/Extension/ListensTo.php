<?php
declare(strict_types=1);

namespace Glaze\Template\Extension;

use Attribute;
use Glaze\Build\Event\BuildEvent;

/**
 * Marks a public method as a build-event listener.
 *
 * Apply this attribute to any public method on a `#[GlazeExtension]`-decorated class
 * to subscribe it to the specified build pipeline event. The method is called with
 * the corresponding typed event payload object when the event is dispatched during
 * `glaze build`.
 *
 * The method signature must accept the payload type for the subscribed event:
 *
 * | `BuildEvent` case     | Expected parameter type    |
 * |-----------------------|----------------------------|
 * | `BuildStarted`        | `BuildStartedEvent`        |
 * | `ContentDiscovered`   | `ContentDiscoveredEvent`   |
 * | `DjotConverterCreated`| `DjotConverterCreatedEvent`|
 * | `SugarRendererCreated`| `SugarRendererCreatedEvent`|
 * | `PageRendered`        | `PageRenderedEvent`        |
 * | `PageWritten`         | `PageWrittenEvent`         |
 * | `BuildCompleted`      | `BuildCompletedEvent`      |
 *
 * Example:
 *
 * ```php
 * #[GlazeExtension]
 * class SitemapGenerator
 * {
 *     private array $urls = [];
 *
 *     #[ListensTo(BuildEvent::PageWritten)]
 *     public function collect(PageWrittenEvent $event): void
 *     {
 *         $this->urls[] = $event->page->urlPath;
 *     }
 *
 *     #[ListensTo(BuildEvent::BuildCompleted)]
 *     public function write(BuildCompletedEvent $event): void
 *     {
 *         file_put_contents($event->config->outputPath() . '/sitemap.xml', $this->buildXml());
 *     }
 * }
 * ```
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class ListensTo
{
    /**
     * Constructor.
     *
     * @param \Glaze\Build\Event\BuildEvent $event Build event case to subscribe to.
     */
    public function __construct(
        public readonly BuildEvent $event,
    ) {
    }
}
