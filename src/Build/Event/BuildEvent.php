<?php
declare(strict_types=1);

namespace Glaze\Build\Event;

/**
 * Enumeration of all build pipeline event points.
 *
 * Each case corresponds to a discrete moment in `SiteBuilder::build()`. Extension
 * methods decorated with `#[ListensTo(BuildEvent::X)]` are called when that moment
 * is reached. The associated payload type for each case is documented below.
 *
 * | Case                | Payload class              | Mutable fields      |
 * |---------------------|----------------------------|---------------------|
 * | BuildStarted        | BuildStartedEvent          | —                   |
 * | ContentDiscovered   | ContentDiscoveredEvent     | `$pages`            |
 * | PageRendered        | PageRenderedEvent          | `$html`             |
 * | PageWritten         | PageWrittenEvent           | —                   |
 * | BuildCompleted      | BuildCompletedEvent        | —                   |
 */
enum BuildEvent
{
    /**
     * Fired once at the very start of a build, before content discovery.
     * Useful for initialising state (opening file handles, recording a start time, etc.).
     */
    case BuildStarted;

    /**
     * Fired after pages are discovered and draft-filtered, before rendering begins.
     * Listeners may mutate `ContentDiscoveredEvent::$pages` to inject virtual pages,
     * augment metadata, or filter the page list.
     */
    case ContentDiscovered;

    /**
     * Fired after each page is rendered to HTML, before it is written to disk.
     * Listeners may mutate `PageRenderedEvent::$html` to post-process output
     * (minification, analytics injection, search index extraction, etc.).
     */
    case PageRendered;

    /**
     * Fired after each page's HTML file has been written to the output directory.
     * Useful for accumulating sitemap entries, search-index records, or per-page stats.
     */
    case PageWritten;

    /**
     * Fired once after all pages and static assets have been written.
     * Useful for writing derived output files (sitemap.xml, search-index.json,
     * RSS feed, etc.) and triggering post-build hooks.
     */
    case BuildCompleted;
}
