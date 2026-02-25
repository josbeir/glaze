<?php
declare(strict_types=1);

namespace Glaze\Content;

use Cake\Utility\Hash;

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
     * @param string|null $type Resolved content type name.
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
        public readonly ?string $type = null,
    ) {
    }

    /**
     * Read metadata using dotted path access.
     *
     * @param string $path Dotted metadata path.
     * @param mixed $default Default value when path does not exist.
     */
    public function meta(string $path, mixed $default = null): mixed
    {
        if (trim($path) === '') {
            return $this->meta;
        }

        return Hash::get($this->meta, $path, $default);
    }

    /**
     * Check whether metadata exists at a dotted path.
     *
     * @param string $path Dotted metadata path.
     */
    public function hasMeta(string $path): bool
    {
        if (trim($path) === '') {
            return $this->meta !== [];
        }

        return Hash::check($this->meta, $path);
    }
}
