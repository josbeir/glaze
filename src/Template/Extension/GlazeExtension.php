<?php
declare(strict_types=1);

namespace Glaze\Template\Extension;

use Attribute;

/**
 * Marks a class as a Glaze extension: an event subscriber, a template helper, or both.
 *
 * Place this attribute on any class in the project's `extensions/` directory to have it
 * auto-discovered by the extension loader at build and serve time.
 *
 * **Named event subscriber** — provide a `name` so the extension is addressable from
 * `glaze.neon`, but omit `helper: true`:
 *
 * ```php
 * #[GlazeExtension('sitemap')]
 * class SitemapExtension
 * {
 *     #[ListensTo(BuildEvent::BuildCompleted)]
 *     public function write(BuildCompletedEvent $event): void { ... }
 * }
 * ```
 *
 * **Template helper** — provide a `name`, set `helper: true`, and implement `__invoke()`:
 *
 * ```php
 * #[GlazeExtension('version', helper: true)]
 * class VersionExtension
 * {
 *     public function __invoke(): string
 *     {
 *         return trim(file_get_contents(__DIR__ . '/../VERSION'));
 *     }
 * }
 * ```
 *
 * **Anonymous event subscriber** — omit `name` entirely; the class is auto-discovered
 * but cannot be addressed from configuration:
 *
 * ```php
 * #[GlazeExtension]
 * class BuildHooks
 * {
 *     #[ListensTo(BuildEvent::SugarRendererCreated)]
 *     public function registerSugar(SugarRendererCreatedEvent $event): void
 *     {
 *         $event->renderer->addExtension(new MySugarExtension());
 *     }
 * }
 * ```
 *
 * **Both** — supply a `name`, set `helper: true`, implement `__invoke()`, and add `#[ListensTo]` methods.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class GlazeExtension
{
    /**
     * Constructor.
     *
     * @param string|null $name Extension identity used as config key and template lookup name,
     *                          or `null` for an anonymous event-subscriber extension.
     * @param bool $helper When `true` the extension is registered as a named template helper.
     *                     Requires a non-empty `name` and an `__invoke()` method.
     */
    public function __construct(
        public readonly ?string $name = null,
        public readonly bool $helper = false,
    ) {
    }
}
