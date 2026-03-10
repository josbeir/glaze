<?php
declare(strict_types=1);

namespace Glaze\Content;

use Glaze\Config\BuildConfig;
use Glaze\Config\LanguageConfig;
use Glaze\Utility\Normalization;
use Glaze\Utility\Path;

/**
 * Orchestrates multi-language content discovery for i18n-enabled sites.
 *
 * When i18n is disabled (no `defaultLanguage` in config) this class passes
 * through to the underlying {@see ContentDiscoveryService} unchanged, adding
 * zero overhead to single-language builds.
 *
 * When enabled, each configured language is discovered from its own content
 * directory. Pages are enriched with a `language` code, `translationKey`,
 * prefixed `slug`, `urlPath`, and `outputRelativePath` via
 * {@see ContentPage::withLanguage()}.
 *
 * Translation linking is performed automatically: pages sharing the same
 * `relativePath` across language trees are linked as translations of each
 * other. An explicit `translationKey` frontmatter key overrides the default
 * path-based key, enabling translations whose file paths differ across
 * language directories.
 *
 * Example:
 *
 * ```php
 * $discovery = new LocalizedContentDiscovery(new ContentDiscoveryService(new FrontMatterParser()));
 * $pages = $discovery->discover($buildConfig);
 * ```
 */
final class LocalizedContentDiscovery
{
    /**
     * Constructor.
     *
     * @param \Glaze\Content\ContentDiscoveryService $discoveryService Underlying single-directory discovery service.
     */
    public function __construct(protected ContentDiscoveryService $discoveryService)
    {
    }

    /**
     * Discover all content pages, applying language prefixes when i18n is enabled.
     *
     * Returns a flat list of `ContentPage` objects. In single-language mode the
     * pages are returned as-is from `ContentDiscoveryService`. In multi-language
     * mode each language tree is discovered separately and the resulting pages
     * are enriched with language metadata before being merged into a single
     * sorted list.
     *
     * @param \Glaze\Config\BuildConfig $config Build configuration.
     * @return array<\Glaze\Content\ContentPage>
     */
    public function discover(BuildConfig $config): array
    {
        if (!$config->i18n->isEnabled()) {
            return $this->discoveryService->discover(
                $config->contentPath(),
                $config->taxonomies,
                $config->contentTypes,
            );
        }

        $allPages = [];

        foreach ($config->i18n->languages as $langCode => $langConfig) {
            $contentPath = $this->resolveContentPath($config, $langConfig);
            if ($contentPath === null) {
                continue;
            }

            $pages = $this->discoveryService->discover(
                $contentPath,
                $config->taxonomies,
                $config->contentTypes,
            );

            foreach ($pages as $page) {
                $translationKey = $this->resolveTranslationKey($page);
                $allPages[] = $page->withLanguage($langCode, $langConfig->urlPrefix, $translationKey);
            }
        }

        usort(
            $allPages,
            static fn(ContentPage $left, ContentPage $right): int => strcmp(
                $left->language . '/' . $left->relativePath,
                $right->language . '/' . $right->relativePath,
            ),
        );

        return $allPages;
    }

    /**
     * Resolve the absolute content directory for a given language configuration.
     *
     * Returns null when the language has no `contentDir` configured and the
     * language code does not match the default language. The default language
     * always falls back to the project-level content directory.
     *
     * @param \Glaze\Config\BuildConfig $config Build configuration.
     * @param \Glaze\Config\LanguageConfig $langConfig Language-specific configuration.
     */
    protected function resolveContentPath(BuildConfig $config, LanguageConfig $langConfig): ?string
    {
        if ($langConfig->contentDir !== null) {
            return Path::resolve($config->projectRoot, $langConfig->contentDir);
        }

        // Default language falls back to the project-level content path.
        if ($langConfig->code === $config->i18n->defaultLanguage) {
            return $config->contentPath();
        }

        return null;
    }

    /**
     * Derive the translation key for a discovered page.
     *
     * Uses an explicit frontmatter `translationKey` when present. Falls back to
     * the page's `relativePath` (the source file path relative to its content
     * directory), which naturally links files that share the same path across
     * different language content directories.
     *
     * @param \Glaze\Content\ContentPage $page Discovered page.
     */
    protected function resolveTranslationKey(ContentPage $page): string
    {
        $explicit = Normalization::optionalString($page->meta['translationkey'] ?? null);

        return $explicit ?? $page->relativePath;
    }
}
