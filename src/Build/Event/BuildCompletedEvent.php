<?php
declare(strict_types=1);

namespace Glaze\Build\Event;

use Glaze\Config\BuildConfig;

/**
 * Payload for the BuildEvent::BuildCompleted event.
 *
 * Fired once after all pages and static assets have been written to disk.
 * Intended for writing derived output files (sitemap.xml, search-index.json,
 * RSS/Atom feeds, etc.), triggering post-build hooks (CDN purge, deploy notify),
 * and printing build summary statistics.
 *
 * Example — write a sitemap:
 *
 * ```php
 * #[ListensTo(BuildEvent::BuildCompleted)]
 * public function writeSitemap(BuildCompletedEvent $event): void
 * {
 *     $xml = $this->buildXml($this->urls, $event->config->site->baseUrl);
 *     file_put_contents($event->config->outputPath() . '/sitemap.xml', $xml);
 * }
 * ```
 */
final readonly class BuildCompletedEvent
{
    /**
     * Constructor.
     *
     * @param array<string> $writtenFiles Absolute paths of all files written during the build.
     * @param \Glaze\Config\BuildConfig $config Active build configuration.
     * @param float $duration Total build duration in seconds.
     */
    public function __construct(
        public array $writtenFiles,
        public BuildConfig $config,
        public float $duration,
    ) {
    }
}
