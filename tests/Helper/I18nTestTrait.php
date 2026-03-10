<?php
declare(strict_types=1);

namespace Glaze\Tests\Helper;

use Glaze\Content\ContentPage;

/**
 * Provides localized ContentPage factory helpers for i18n test scenarios.
 *
 * Use this trait in any test class that needs to build ContentPage instances
 * with language and translationKey metadata, avoiding boilerplate duplication
 * across test files.
 *
 * Example:
 *
 * ```php
 * $enPage = $this->makeLocalizedPage('about', '/about/', 'about.dj', 'en', 'about.dj', 'About');
 * $nlPage = $this->makeLocalizedPage('nl/about', '/nl/about/', 'about.dj', 'nl', 'about.dj');
 * ```
 */
trait I18nTestTrait
{
    /**
     * Create a localized ContentPage for i18n test scenarios.
     *
     * @param string $slug Page slug (e.g. `'nl/about'`).
     * @param string $urlPath Routed URL path (e.g. `'/nl/about/'`).
     * @param string $relativePath Source path relative to the content directory (e.g. `'about.dj'`).
     * @param string $language BCP-47 language code (e.g. `'en'`, `'nl'`).
     * @param string $translationKey Key that links translated pages across language trees.
     * @param string $title Human-readable page title; derived from `$slug` when empty.
     */
    protected function makeLocalizedPage(
        string $slug,
        string $urlPath,
        string $relativePath,
        string $language,
        string $translationKey,
        string $title = '',
    ): ContentPage {
        return new ContentPage(
            sourcePath: '/tmp/' . str_replace('/', '-', $slug) . '.dj',
            relativePath: $relativePath,
            slug: $slug,
            urlPath: $urlPath,
            outputRelativePath: trim($slug, '/') . '/index.html',
            title: $title !== '' ? $title : ucfirst(basename($slug)),
            source: '# ' . $slug,
            draft: false,
            meta: [],
            language: $language,
            translationKey: $translationKey,
        );
    }
}
