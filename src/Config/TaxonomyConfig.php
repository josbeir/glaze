<?php
declare(strict_types=1);

namespace Glaze\Config;

/**
 * Immutable configuration for a single taxonomy dimension.
 *
 * Taxonomies classify pages by arbitrary term sets (e.g. tags, categories).
 * When `generatePages` is enabled, Glaze automatically creates a list page
 * and one term page per distinct term value found across all content pages.
 *
 * Example NEON configuration:
 *
 * ```neon
 * taxonomies:
 *   tags:
 *     generatePages: true
 *     basePath: /tags
 *     termTemplate: taxonomy/tags
 *     listTemplate: taxonomy/tags-list
 *   categories: {}
 * ```
 *
 * Simple list syntax (no page generation) is also supported:
 *
 * ```neon
 * taxonomies:
 *   - tags
 *   - categories
 * ```
 */
final class TaxonomyConfig
{
    /**
     * Constructor.
     *
     * @param string $name Normalised taxonomy key (e.g. `tags`).
     * @param bool $generatePages Whether to auto-generate term and list pages.
     * @param string $basePath URL prefix for generated pages. Defaults to `/{name}` when empty.
     * @param string $termTemplate Sugar template for individual term pages. Falls back to `taxonomy/term`.
     * @param string $listTemplate Sugar template for the taxonomy list page. Falls back to `taxonomy/list`.
     */
    public function __construct(
        public readonly string $name,
        public readonly bool $generatePages = false,
        public readonly string $basePath = '',
        public readonly string $termTemplate = '',
        public readonly string $listTemplate = '',
    ) {
    }

    /**
     * Return the resolved URL base path for generated taxonomy pages.
     *
     * Falls back to `/{name}` when no explicit `basePath` was configured.
     */
    public function resolvedBasePath(): string
    {
        $path = trim($this->basePath, '/');

        return $path !== '' ? '/' . $path : '/' . $this->name;
    }

    /**
     * Return the effective Sugar template name for a term page.
     *
     * Checks the explicit `termTemplate` first, then falls back to the generic
     * `taxonomy/term` convention.
     */
    public function resolvedTermTemplate(): string
    {
        $template = trim($this->termTemplate);

        return $template !== '' ? $template : 'taxonomy/term';
    }

    /**
     * Return the effective Sugar template name for the taxonomy list page.
     *
     * Checks the explicit `listTemplate` first, then falls back to the generic
     * `taxonomy/list` convention.
     */
    public function resolvedListTemplate(): string
    {
        $template = trim($this->listTemplate);

        return $template !== '' ? $template : 'taxonomy/list';
    }
}
