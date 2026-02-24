<?php
declare(strict_types=1);

namespace Glaze\Content;

/**
 * Value object representing a discoverable content page.
 */
final class ContentPage
{
    /**
     * Constructor.
     *
     * @param string $sourcePath Absolute source file path.
     * @param string $relativePath Source-relative path with extension.
     * @param string $slug Normalized slug without extension.
     * @param string $urlPath Public URL path.
     * @param string $outputRelativePath Relative output path under public directory.
     * @param string $title Human readable page title.
     * @param string $source Djot source content.
     * @param bool $draft Whether this page is marked as draft.
     * @param array<string, mixed> $meta Parsed frontmatter metadata.
     * @param array<string, array<string>> $taxonomies Parsed taxonomy terms by taxonomy key.
     */
    public function __construct(
        public readonly string $sourcePath,
        public readonly string $relativePath,
        public readonly string $slug,
        public readonly string $urlPath,
        public readonly string $outputRelativePath,
        public readonly string $title,
        public readonly string $source,
        public readonly bool $draft,
        public readonly array $meta,
        public readonly array $taxonomies = [],
    ) {
    }
}
