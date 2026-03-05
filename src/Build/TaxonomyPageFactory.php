<?php
declare(strict_types=1);

namespace Glaze\Build;

use Cake\Utility\Text;
use Glaze\Config\TaxonomyConfig;
use Glaze\Content\ContentPage;

/**
 * Generates taxonomy list and term pages from discovered content pages.
 *
 * When a taxonomy is configured with `generatePages: true`, this factory creates:
 *
 * - One **list page** per taxonomy at `{basePath}/` — lists all distinct terms.
 * - One **term page** per distinct term at `{basePath}/{term}/` — lists pages tagged with that term.
 *
 * Generated pages are `unlisted` (excluded from `regularPages()`) and go through
 * the normal Sugar/Djot render pipeline. Their `meta` carries pre-resolved
 * `taxonomy`, `term`, and `template` values so that existing template machinery
 * picks them up without any additional routing logic.
 *
 * Example generated meta for a term page (`tags/php`):
 *
 * ```php
 * [
 *     'taxonomy' => 'tags',
 *     'term'     => 'php',
 *     'template' => 'taxonomy/tags',
 * ]
 * ```
 *
 * Example generated meta for a list page (`tags`):
 *
 * ```php
 * [
 *     'taxonomy' => 'tags',
 *     'template' => 'taxonomy/list',
 * ]
 * ```
 */
final class TaxonomyPageFactory
{
    /**
     * Generate taxonomy list and term pages from discovered content pages.
     *
     * Only processes taxonomies where `generatePages` is `true`. Skips any
     * taxonomy that yields no terms. Term slugs are derived from the raw term
     * string using the same `Text::slug` lowercasing applied to content slugs.
     *
     * @param array<\Glaze\Content\ContentPage> $pages Real content pages to extract terms from.
     * @param array<string, \Glaze\Config\TaxonomyConfig> $taxonomies Taxonomy configurations keyed by taxonomy name.
     * @return array<\Glaze\Content\ContentPage> Generated taxonomy pages (list + term pages per active taxonomy).
     */
    public function generate(array $pages, array $taxonomies): array
    {
        $generated = [];

        foreach ($taxonomies as $config) {
            if (!$config->generatePages) {
                continue;
            }

            $terms = $this->collectTerms($pages, $config->name);
            $basePath = $config->resolvedBasePath();
            $baseSlug = trim(str_replace('\\', '/', $basePath), '/');

            $generated[] = $this->makeListPage($config, $baseSlug, $basePath);

            foreach ($terms as $term) {
                $generated[] = $this->makeTermPage($config, $baseSlug, $basePath, $term);
            }
        }

        return $generated;
    }

    /**
     * Collect all distinct term values from a set of pages for a given taxonomy.
     *
     * Returns terms in ascending alphabetical order.
     *
     * @param array<\Glaze\Content\ContentPage> $pages Content pages.
     * @param string $taxonomyName Taxonomy key to collect terms for.
     * @return array<string>
     */
    protected function collectTerms(array $pages, string $taxonomyName): array
    {
        $terms = [];

        foreach ($pages as $page) {
            foreach ($page->taxonomies[$taxonomyName] ?? [] as $term) {
                if (is_string($term) && $term !== '') {
                    $terms[$term] = true;
                }
            }
        }

        ksort($terms);

        return array_keys($terms);
    }

    /**
     * Slugify a single taxonomy term value.
     *
     * Uses the same `Text::slug` + `strtolower` pipeline applied to content slugs.
     *
     * @param string $term Raw term string.
     */
    protected function slugifyTerm(string $term): string
    {
        $slugged = strtolower(Text::slug($term));

        return $slugged !== '' ? $slugged : 'term';
    }

    /**
     * Create the taxonomy list page that enumerates all terms in a taxonomy.
     *
     * @param \Glaze\Config\TaxonomyConfig $config Taxonomy configuration.
     * @param string $baseSlug Normalised base slug (e.g. `tags`).
     * @param string $basePath URL base path (e.g. `/tags`).
     */
    protected function makeListPage(TaxonomyConfig $config, string $baseSlug, string $basePath): ContentPage
    {
        $urlPath = '/' . trim($basePath, '/') . '/';
        $outputRelativePath = $baseSlug . '/index.html';

        return new ContentPage(
            sourcePath: '',
            relativePath: $baseSlug . '/.taxonomy-list',
            slug: $baseSlug,
            urlPath: $urlPath,
            outputRelativePath: $outputRelativePath,
            title: ucfirst($config->name),
            source: '',
            draft: false,
            meta: [
                'taxonomy' => $config->name,
                'template' => $config->resolvedListTemplate(),
            ],
            unlisted: true,
        );
    }

    /**
     * Create a term page listing all pages tagged with the given term.
     *
     * @param \Glaze\Config\TaxonomyConfig $config Taxonomy configuration.
     * @param string $baseSlug Normalised base slug (e.g. `tags`).
     * @param string $basePath URL base path (e.g. `/tags`).
     * @param string $term Raw term string (e.g. `php`).
     */
    protected function makeTermPage(
        TaxonomyConfig $config,
        string $baseSlug,
        string $basePath,
        string $term,
    ): ContentPage {
        $termSlug = $this->slugifyTerm($term);
        $slug = $baseSlug . '/' . $termSlug;
        $urlPath = '/' . trim($basePath, '/') . '/' . $termSlug . '/';
        $outputRelativePath = $slug . '/index.html';

        return new ContentPage(
            sourcePath: '',
            relativePath: $slug . '/.taxonomy-term',
            slug: $slug,
            urlPath: $urlPath,
            outputRelativePath: $outputRelativePath,
            title: $term,
            source: '',
            draft: false,
            meta: [
                'taxonomy' => $config->name,
                'term' => $term,
                'template' => $config->resolvedTermTemplate(),
            ],
            unlisted: true,
        );
    }
}
