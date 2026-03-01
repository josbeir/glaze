<?php
declare(strict_types=1);

namespace Glaze\Template\Extension;

use Attribute;

/**
 * Marks an invokable class as a named Glaze template extension, or an event subscriber, or both.
 *
 * Place this attribute on any class in the project's `extensions/` directory to have it
 * auto-discovered by the extension loader at build and serve time.
 *
 * **Template helper** — provide a `name` and implement `__invoke()`:
 *
 * ```php
 * #[GlazeExtension('version')]
 * class VersionExtension
 * {
 *     public function __invoke(): string
 *     {
 *         return trim(file_get_contents(__DIR__ . '/../VERSION'));
 *     }
 * }
 * ```
 *
 * **Event subscriber** — omit `name` and decorate methods with `#[ListensTo]`:
 *
 * ```php
 * #[GlazeExtension]
 * class BuildHooks
 * {
 *     #[ListensTo(BuildEvent::DjotConverterCreated)]
 *     public function registerDjot(DjotConverterCreatedEvent $event): void
 *     {
 *         $event->converter->addExtension(new MyDjotExtension());
 *     }
 *
 *     #[ListensTo(BuildEvent::SugarRendererCreated)]
 *     public function registerSugar(SugarRendererCreatedEvent $event): void
 *     {
 *         $event->renderer->addExtension(new MySugarExtension());
 *     }
 * }
 * ```
 *
 * **Both** — supply a `name`, implement `__invoke()`, and add `#[ListensTo]` methods.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class GlazeExtension
{
    /**
     * Constructor.
     *
     * @param string|null $name Extension name used as lookup key in template calls,
     *                          or `null` for a pure event-subscriber extension.
     */
    public function __construct(
        public readonly ?string $name = null,
    ) {
    }
}
