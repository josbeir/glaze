<?php
declare(strict_types=1);

namespace Glaze\Build\Event;

use Glaze\Config\BuildConfig;

/**
 * Payload for the BuildEvent::BuildStarted event.
 *
 * Fired once at the very beginning of a static build, before content discovery.
 * Listeners can use this event to open output file handles, record a start time,
 * validate external prerequisites, etc.
 *
 * Example:
 *
 * ```php
 * #[ListensTo(BuildEvent::BuildStarted)]
 * public function onBuildStarted(BuildStartedEvent $event): void
 * {
 *     $this->startTime = hrtime(true);
 * }
 * ```
 */
final readonly class BuildStartedEvent
{
    /**
     * Constructor.
     *
     * @param \Glaze\Config\BuildConfig $config Active build configuration.
     */
    public function __construct(
        public BuildConfig $config,
    ) {
    }
}
