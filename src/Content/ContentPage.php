<?php
declare(strict_types=1);

namespace Glaze\Content;

use Glaze\Support\HasDottedMetadataAccessTrait;

/**
 * Value object representing a discoverable content page.
 */
final class ContentPage
{
    use HasDottedMetadataAccessTrait;

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
     * @param list<\Glaze\Render\Djot\TocEntry> $toc Table-of-contents entries collected during the render pass.
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
        public readonly array $toc = [],
    ) {
    }

    /**
     * Return a copy of this page with the given table-of-contents entries attached.
     *
     * Used by `SiteBuilder` after the Djot render pass to attach the collected
     * `TocEntry[]` list to the page value object before it is passed to templates.
     *
     * @param list<\Glaze\Render\Djot\TocEntry> $toc TOC entries in document order.
     */
    public function withToc(array $toc): self
    {
        return new self(
            sourcePath: $this->sourcePath,
            relativePath: $this->relativePath,
            slug: $this->slug,
            urlPath: $this->urlPath,
            outputRelativePath: $this->outputRelativePath,
            title: $this->title,
            source: $this->source,
            draft: $this->draft,
            meta: $this->meta,
            taxonomies: $this->taxonomies,
            type: $this->type,
            toc: $toc,
        );
    }

    /**
     * Return metadata map consumed by dotted access helpers.
     *
     * @return array<string, mixed>
     */
    protected function metadataMap(): array
    {
        return $this->meta;
    }
}
