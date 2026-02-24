<?php
declare(strict_types=1);

namespace Glaze\Scaffold;

/**
 * Immutable options for new page scaffolding.
 */
final class PageScaffoldOptions
{
    /**
     * Constructor.
     *
     * @param string $title Page title.
     * @param string $date ISO-8601 page date.
     * @param string|null $type Optional content type.
     * @param bool $draft Whether the page is a draft.
     * @param string $slug Page slug.
     * @param string $titleSlug Title-derived slug used for index bundles.
     * @param array{match: string, createPattern: string|null}|null $pathRule Optional resolved content type path rule.
     * @param string|null $pathPrefix Optional explicit path prefix.
     * @param bool $asIndex Whether to create page as a folder with index.dj.
     * @param bool $force Whether overwrite is allowed.
     */
    public function __construct(
        public readonly string $title,
        public readonly string $date,
        public readonly ?string $type,
        public readonly bool $draft,
        public readonly string $slug,
        public readonly string $titleSlug,
        public readonly ?array $pathRule,
        public readonly ?string $pathPrefix,
        public readonly bool $asIndex,
        public readonly bool $force,
    ) {
    }
}
