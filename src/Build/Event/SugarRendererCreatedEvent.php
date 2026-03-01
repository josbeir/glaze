<?php
declare(strict_types=1);

namespace Glaze\Build\Event;

use Glaze\Config\BuildConfig;
use Glaze\Render\SugarPageRenderer;

/**
 * Payload for the BuildEvent::SugarRendererCreated event.
 *
 * Fired when a Sugar page renderer instance is created and before it is first
 * used to render page output. Listeners can register additional Sugar engine
 * extensions on `$renderer`.
 *
 * Example:
 *
 * ```php
 * #[ListensTo(BuildEvent::SugarRendererCreated)]
 * public function registerSugarExtensions(SugarRendererCreatedEvent $event): void
 * {
 *     $event->renderer->addExtension(new CustomSugarExtension());
 * }
 * ```
 */
final readonly class SugarRendererCreatedEvent
{
    /**
     * Constructor.
     *
     * @param \Glaze\Render\SugarPageRenderer $renderer Sugar page renderer instance.
     * @param string $template Template name used by the renderer.
     * @param \Glaze\Config\BuildConfig $config Active build configuration.
     */
    public function __construct(
        public SugarPageRenderer $renderer,
        public string $template,
        public BuildConfig $config,
    ) {
    }
}
