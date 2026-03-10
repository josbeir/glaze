<?php
declare(strict_types=1);

namespace Glaze\Build;

use Cake\Utility\Text;
use Glaze\Config\I18nConfig;
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
     * Only processes taxonomies where `generatePages` is `true`. When i18n is
     * enabled, generates a separate set of taxonomy pages per language using the
     * language's URL prefix. Single-language builds produce an unscoped page set
     * as before (no prefix applied).
     *
     * @param array<\Glaze\Content\ContentPage> $pages Real content pages to extract terms from.
     * @param array<string, \Glaze\Config\TaxonomyConfig> $taxonomies Taxonomy configurations keyed by taxonomy name.
     * @param \Glaze\Config\I18nConfig $i18nConfig I18n configuration used to group pages by language.
     *   Pass a disabled config (the default) for single-language builds.
     * @return array<\Glaze\Content\ContentPage> Generated taxonomy pages (list + term pages per active taxonomy per language).
     */
    public function generate(array $pages, array $taxonomies, I18nConfig $i18nConfig = new I18nConfig(null, [])): array
    {
        if (!$i18nConfig->isEnabled()) {
            return $this->generateForLanguage($pages, $taxonomies, '', '');
        }

        $generated = [];
        $pagesByLanguage = [];
        foreach ($pages as $page) {
            $pagesByLanguage[$page->language][] = $page;
        }

        foreach ($i18nConfig->languages as $langCode => $langConfig) {
            $langPages = $pagesByLanguage[$langCode] ?? [];
            if ($langPages === []) {
                continue;
            }

            foreach ($this->generateForLanguage($langPages, $taxonomies, $langCode, $langConfig->urlPrefix) as $page) {
                $generated[] = $page;
            }
        }

        return $generated;
    }

    /**
     * Generate taxonomy list and term pages for a single language partition.
     *
     * Skips any taxonomy that has `generatePages` set to `false`. Terms are
     * collected from `$pages` only (already scoped to the given language).
     *
     * @param array<\Glaze\Content\ContentPage> $pages Pages within this language group.
     * @param array<string, \Glaze\Config\TaxonomyConfig> $taxonomies Taxonomy configurations.
     * @param string $language Language code to assign to generated pages (empty for single-language builds).
     * @param string $urlPrefix URL prefix for this language (e.g. `nl`). Empty for the default language.
     * @return array<\Glaze\Content\ContentPage>
     */
    protected function generateForLanguage(array $pages, array $taxonomies, string $language, string $urlPrefix): array
    {
        $generated = [];

        foreach ($taxonomies as $config) {
            if (!$config->generatePages) {
                continue;
            }

            $terms = $this->collectTerms($pages, $config->name);
            $basePath = $config->resolvedBasePath();
            $baseSlug = trim(str_replace('\\', '/', $basePath), '/');

            $generated[] = $this->makeListPage($config, $baseSlug, $basePath, $language, $urlPrefix);

            foreach ($terms as $term) {
                $generated[] = $this->makeTermPage($config, $baseSlug, $basePath, $term, $language, $urlPrefix);
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
     * When a non-empty `$urlPrefix` is provided (i18n build), the slug, URL path,
     * and output path are prefixed accordingly so the list page sits under the
     * language subtree (e.g. `/nl/tags/`).
     *
     * @param \Glaze\Config\TaxonomyConfig $config Taxonomy configuration.
     * @param string $baseSlug Normalised base slug (e.g. `tags`).
     * @param string $basePath URL base path (e.g. `/tags`).
     * @param string $language Language code for the generated page (empty for single-language builds).
     * @param string $urlPrefix URL prefix for this language (e.g. `nl`). Empty for the default language.
     */
    protected function makeListPage(
        TaxonomyConfig $config,
        string $baseSlug,
        string $basePath,
        string $language = '',
        string $urlPrefix = '',
    ): ContentPage {
        $prefix = trim($urlPrefix, '/');

        if ($prefix !== '') {
            $slug = $prefix . '/' . $baseSlug;
            $urlPath = '/' . $prefix . '/' . trim($basePath, '/') . '/';
            $outputRelativePath = $prefix . '/' . $baseSlug . '/index.html';
        } else {
            $slug = $baseSlug;
            $urlPath = '/' . trim($basePath, '/') . '/';
            $outputRelativePath = $baseSlug . '/index.html';
        }

        return new ContentPage(
            sourcePath: '',
            relativePath: $slug . '/.taxonomy-list',
            slug: $slug,
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
            language: $language,
        );
    }

    /**
     * Create a term page listing all pages tagged with the given term.
     *
     * When a non-empty `$urlPrefix` is provided (i18n build), the slug, URL path,
     * and output path are prefixed accordingly so the term page sits under the
     * language subtree (e.g. `/nl/tags/php/`).
     *
     * @param \Glaze\Config\TaxonomyConfig $config Taxonomy configuration.
     * @param string $baseSlug Normalised base slug (e.g. `tags`).
     * @param string $basePath URL base path (e.g. `/tags`).
     * @param string $term Raw term string (e.g. `php`).
     * @param string $language Language code for the generated page (empty for single-language builds).
     * @param string $urlPrefix URL prefix for this language (e.g. `nl`). Empty for the default language.
     */
    protected function makeTermPage(
        TaxonomyConfig $config,
        string $baseSlug,
        string $basePath,
        string $term,
        string $language = '',
        string $urlPrefix = '',
    ): ContentPage {
        $termSlug = $this->slugifyTerm($term);
        $prefix = trim($urlPrefix, '/');

        if ($prefix !== '') {
            $slug = $prefix . '/' . $baseSlug . '/' . $termSlug;
            $urlPath = '/' . $prefix . '/' . trim($basePath, '/') . '/' . $termSlug . '/';
        } else {
            $slug = $baseSlug . '/' . $termSlug;
            $urlPath = '/' . trim($basePath, '/') . '/' . $termSlug . '/';
        }

        return new ContentPage(
            sourcePath: '',
            relativePath: $slug . '/.taxonomy-term',
            slug: $slug,
            urlPath: $urlPath,
            outputRelativePath: $slug . '/index.html',
            title: $term,
            source: '',
            draft: false,
            meta: [
                'taxonomy' => $config->name,
                'term' => $term,
                'template' => $config->resolvedTermTemplate(),
            ],
            unlisted: true,
            language: $language,
        );
    }
}
