<?php
declare(strict_types=1);

namespace Glaze\Render\Djot;

/**
 * Represents a single entry in a page table of contents.
 *
 * Entries are collected during the Djot rendering pass and expose the
 * heading level, anchor id and plain-text label needed to build navigation.
 *
 * Example:
 *   new TocEntry(level: 2, id: 'installation', text: 'Installation')
 */
final readonly class TocEntry
{
    /**
     * Constructor.
     *
     * @param int $level Heading level (1–6).
     * @param string $id Anchor id matching the rendered heading element.
     * @param string $text Plain-text heading label.
     */
    public function __construct(
        public int $level,
        public string $id,
        public string $text,
    ) {
    }
}
