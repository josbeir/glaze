<?php
declare(strict_types=1);

namespace Glaze\Scaffold;

/**
 * Immutable options for project scaffolding.
 */
final class ScaffoldOptions
{
    /**
     * Constructor.
     *
     * @param string $targetDirectory Target project directory.
     * @param string $siteName Site machine/readable name.
     * @param string $siteTitle Site title.
     * @param string $pageTemplate Default page template name.
     * @param string $description Default site description.
     * @param string|null $baseUrl Optional canonical site base URL.
     * @param string|null $basePath Optional site base path for subfolder deployments.
     * @param array<string> $taxonomies Taxonomy keys.
     * @param bool $force Whether existing files can be overwritten.
     */
    public function __construct(
        public readonly string $targetDirectory,
        public readonly string $siteName,
        public readonly string $siteTitle,
        public readonly string $pageTemplate,
        public readonly string $description,
        public readonly ?string $baseUrl,
        public readonly ?string $basePath,
        public readonly array $taxonomies,
        public readonly bool $force,
    ) {
    }
}
