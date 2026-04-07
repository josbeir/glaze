<?php
declare(strict_types=1);

namespace Glaze\Template;

use Glaze\Config\I18nConfig;
use Glaze\Config\LanguageConfig;
use Glaze\Config\SiteConfig;
use Glaze\Content\ContentPage;
use Glaze\Support\ResourcePathRewriter;
use Glaze\Support\TranslationLoader;
use Glaze\Template\Collection\ContentAssetCollection;
use Glaze\Template\Collection\PageCollection;
use Glaze\Template\Extension\ExtensionRegistry;

/**
 * Template context facade exposed to Sugar templates as `$this`.
 *
 * When i18n is enabled, two separate indices are maintained:
 *
 * - `$siteIndex` — a language-scoped index containing only the pages that belong
 *   to the current page's language. All navigation helpers (`regularPages()`,
 *   `sections()`, `taxonomy()`, `previous()`, `next()`, etc.) operate on this
 *   index so that templates never accidentally expose pages from another language.
 *
 * - `$globalIndex` — the full site-wide index containing pages from all languages.
 *   Used exclusively for cross-language lookups (`translations()`, `translation()`,
 *   `languageUrl()`). When `$globalIndex` is `null` (single-language builds or
 *   direct construction without a global index) `$siteIndex` is used as-is.
 */
final class SiteContext
{
    /**
     * Memoized TranslationLoader instance.
     */
    protected ?TranslationLoader $translationLoader = null;

    /**
     * Memoized URL-resolution SiteConfig with the current language's urlPrefix composed into basePath.
     */
    protected ?SiteConfig $resolvedUrlSiteConfig = null;

    /**
     * Constructor.
     *
     * @param \Glaze\Template\SiteIndex $siteIndex Language-scoped page index used for navigation.
     *   On i18n builds this should contain only pages for the current page's language.
     *   On single-language builds pass the full site index.
     * @param \Glaze\Content\ContentPage $currentPage Current page being rendered.
     * @param \Glaze\Template\Extension\ExtensionRegistry $extensions Registered project extensions.
     * @param \Glaze\Template\ContentAssetResolver|null $assetResolver Optional content asset resolver.
     * @param \Glaze\Config\SiteConfig $siteConfig Effective site configuration for the current page (may include language overrides).
     * @param \Glaze\Support\ResourcePathRewriter $pathRewriter Path rewriter used by URL helpers.
     * @param \Glaze\Config\I18nConfig $i18nConfig I18n configuration for language helpers and string translation.
     * @param string $translationsPath Absolute path to the i18n translations directory.
     * @param \Glaze\Template\SiteIndex|null $globalIndex Full site-wide index for cross-language lookups.
     *   When null, `$siteIndex` is used for all lookups — correct for single-language builds.
     * @param \Glaze\Config\SiteConfig $baseSiteConfig Unresolved base site configuration used as the
     *   starting point for per-language overrides in cross-language URL helpers.
     */
    public function __construct(
        protected SiteIndex $siteIndex,
        protected ContentPage $currentPage,
        protected ExtensionRegistry $extensions = new ExtensionRegistry(),
        protected ?ContentAssetResolver $assetResolver = null,
        protected SiteConfig $siteConfig = new SiteConfig(),
        protected ResourcePathRewriter $pathRewriter = new ResourcePathRewriter(),
        protected I18nConfig $i18nConfig = new I18nConfig(null, []),
        protected string $translationsPath = '',
        protected ?SiteIndex $globalIndex = null,
        protected SiteConfig $baseSiteConfig = new SiteConfig(),
    ) {
    }

    /**
     * Return current page.
     */
    public function page(): ContentPage
    {
        return $this->currentPage;
    }

    /**
     * Return this site context for chainable template ergonomics.
     */
    public function site(): self
    {
        return $this;
    }

    /**
     * Return default-sorted regular page collection, excluding unlisted pages.
     *
     * On single-language builds this is identical to the full site index. On i18n builds the
     * result is already scoped to the current language because `$siteIndex` is a
     * language-specific index built by `SiteBuilder`.
     *
     * When `$withUntranslated` is `true`, pages from the default language that have no
     * counterpart in the current language (matched by `translationKey`) are appended as
     * untranslated fallbacks. Pass an ordered list of language codes to specify fallback
     * languages explicitly instead of using the configured default.
     *
     * @param list<string>|bool $withUntranslated When `true` the configured default language
     *   is used as the fallback source. Pass a list of language codes to specify one or more
     *   fallback languages in priority order.
     */
    public function regularPages(bool|array $withUntranslated = false): PageCollection
    {
        return $this->withUntranslatedFallback($this->siteIndex->regularPages(), $withUntranslated);
    }

    /**
     * Return all pages for the current language including unlisted ones.
     *
     * Unlike `regularPages()`, this collection retains pages marked as unlisted
     * (e.g. `_index.dj` section overview pages). On i18n builds only pages for
     * the current language are included.
     *
     * Accepts the same `$withUntranslated` parameter as `regularPages()` to append
     * untranslated fallback pages from the default or specified language(s).
     *
     * @param list<string>|bool $withUntranslated Fallback language source — see `regularPages()`.
     */
    public function allPages(bool|array $withUntranslated = false): PageCollection
    {
        return $this->withUntranslatedFallback($this->siteIndex->allPages(), $withUntranslated);
    }

    /**
     * Alias for regular pages.
     *
     * @param list<string>|bool $withUntranslated Fallback language source — see `regularPages()`.
     */
    public function pages(bool|array $withUntranslated = false): PageCollection
    {
        return $this->regularPages($withUntranslated);
    }

    /**
     * Return pages matching a resolved content type.
     *
     * Accepts the same `$withUntranslated` parameter as `regularPages()` to append
     * untranslated fallback pages from the default or specified language(s) before
     * filtering by type.
     *
     * @param string $type Content type name.
     * @param list<string>|bool $withUntranslated Fallback language source — see `regularPages()`.
     */
    public function type(string $type, bool|array $withUntranslated = false): PageCollection
    {
        return $this->regularPages($withUntranslated)->whereType($type);
    }

    /**
     * Return section node by path.
     *
     * @param string $name Section path.
     */
    public function section(string $name): ?Section
    {
        return $this->siteIndex->sectionNode($name);
    }

    /**
     * Return root section tree.
     */
    public function tree(): Section
    {
        return $this->siteIndex->tree();
    }

    /**
     * Return top-level sections as an ordered map of section key to section node.
     *
     * @return array<string, \Glaze\Template\Section>
     */
    public function sections(): array
    {
        return $this->siteIndex->sections();
    }

    /**
     * Return root-level pages that do not belong to any section.
     */
    public function rootPages(): PageCollection
    {
        return $this->siteIndex->rootPages();
    }

    /**
     * Find a page by slug.
     *
     * @param string $slug Page slug.
     */
    public function bySlug(string $slug): ?ContentPage
    {
        return $this->siteIndex->findBySlug($slug);
    }

    /**
     * Find a page by URL path.
     *
     * @param string $urlPath URL path.
     */
    public function byUrl(string $urlPath): ?ContentPage
    {
        return $this->siteIndex->findByUrlPath($urlPath);
    }

    /**
     * Return assets from content root or an optional content-relative subdirectory.
     *
     * @param string|null $subdirectory Optional content-relative subdirectory.
     */
    public function assets(?string $subdirectory = null): ContentAssetCollection
    {
        if (!$this->assetResolver instanceof ContentAssetResolver) {
            return new ContentAssetCollection([]);
        }

        return $this->assetResolver->forDirectory($subdirectory);
    }

    /**
     * Return assets for a specific content page.
     *
     * @param \Glaze\Content\ContentPage $page Page whose directory should be scanned.
     * @param string|null $subdirectory Optional child path relative to page directory.
     */
    public function assetsFor(ContentPage $page, ?string $subdirectory = null): ContentAssetCollection
    {
        if (!$this->assetResolver instanceof ContentAssetResolver) {
            return new ContentAssetCollection([]);
        }

        return $this->assetResolver->forPage($page, $subdirectory);
    }

    /**
     * Return assets for the current page.
     *
     * @param string|null $subdirectory Optional child path relative to current page directory.
     */
    public function pageAssets(?string $subdirectory = null): ContentAssetCollection
    {
        return $this->assetsFor($this->currentPage, $subdirectory);
    }

    /**
     * Filter a collection with where semantics.
     *
     * @param \Glaze\Template\Collection\PageCollection|array<\Glaze\Content\ContentPage> $collection Collection to filter.
     * @param string $key Field key.
     * @param mixed $operatorOrValue Operator or expected value.
     * @param mixed $value Optional expected value when using operator.
     */
    public function where(
        PageCollection|array $collection,
        string $key,
        mixed $operatorOrValue,
        mixed $value = null,
    ): PageCollection {
        $pages = $this->toCollection($collection);

        if (func_num_args() >= 4) {
            return $pages->where($key, $operatorOrValue, $value);
        }

        return $pages->where($key, $operatorOrValue);
    }

    /**
     * Return taxonomy data by name.
     *
     * @param string $name Taxonomy field name.
     */
    public function taxonomy(string $name): TaxonomyCollection
    {
        return $this->siteIndex->taxonomy($name);
    }

    /**
     * Return pages for a specific taxonomy term.
     *
     * @param string $name Taxonomy field name.
     * @param string $term Term name.
     */
    public function taxonomyTerm(string $name, string $term): PageCollection
    {
        return $this->taxonomy($name)->term($term);
    }

    /**
     * Paginate a page collection.
     *
     * @param \Glaze\Template\Section|\Glaze\Template\Collection\PageCollection|array<\Glaze\Content\ContentPage> $collection Source collection.
     * @param int $pageSize Page size.
     * @param int $currentPage Current page number.
     * @param string|null $basePath Base pager path.
     * @param string $pathSegment Pagination path segment.
     */
    public function paginate(
        Section|PageCollection|array $collection,
        int $pageSize = 10,
        int $currentPage = 1,
        ?string $basePath = null,
        string $pathSegment = 'page',
    ): Pager {
        $pages = $this->toCollection($collection);
        $basePath ??= $this->currentPage->urlPath;

        return new Pager(
            source: $pages,
            pageSize: $pageSize,
            pageNumber: $currentPage,
            basePath: $basePath,
            pathSegment: $pathSegment,
        );
    }

    /**
     * Return previous page in the current page section.
     *
     * @param callable(\Glaze\Content\ContentPage): bool|null $predicate Optional matcher for candidate pages.
     */
    public function previousInSection(?callable $predicate = null): ?ContentPage
    {
        return $this->siteIndex->previousInSection($this->currentPage, $predicate);
    }

    /**
     * Return next page in the current page section.
     *
     * @param callable(\Glaze\Content\ContentPage): bool|null $predicate Optional matcher for candidate pages.
     */
    public function nextInSection(?callable $predicate = null): ?ContentPage
    {
        return $this->siteIndex->nextInSection($this->currentPage, $predicate);
    }

    /**
     * Return the previous page in global display order, crossing section boundaries.
     *
     * @param callable(\Glaze\Content\ContentPage): bool|null $predicate Optional matcher for candidate pages.
     */
    public function previous(?callable $predicate = null): ?ContentPage
    {
        return $this->siteIndex->previous($this->currentPage, $predicate);
    }

    /**
     * Return the next page in global display order, crossing section boundaries.
     *
     * @param callable(\Glaze\Content\ContentPage): bool|null $predicate Optional matcher for candidate pages.
     */
    public function next(?callable $predicate = null): ?ContentPage
    {
        return $this->siteIndex->next($this->currentPage, $predicate);
    }

    /**
     * Return a site-root URL path with basePath and language prefix applied.
     *
     * When a `ContentPage` is passed the URL is built using that page's own language
     * configuration, ensuring cross-language links (e.g. EN posts rendered via
     * `withUntranslated` on an NL page) resolve to the correct prefix instead of
     * inheriting the current page's prefix.
     *
     * When `$absolute` is true, the configured `baseUrl` is prepended to produce
     * a fully-qualified URL. Falls back to a relative path when `baseUrl` is not
     * configured.
     *
     * For static assets that must not receive any language prefix, use `rawUrl()`.
     *
     * Examples:
     * ```php
     * $this->url('/about/')           // '/nl/about/' (on NL page, urlPrefix='nl')
     * $this->url('/about/', true)     // 'https://example.com/nl/about/'
     * $this->url($enPost)             // '/post-1/' (EN post on NL page, no double-prefix)
     * ```
     *
     * @param \Glaze\Content\ContentPage|string $path Site-root path or a content page.
     * @param bool $absolute Whether to prepend the configured baseUrl.
     */
    public function url(string|ContentPage $path, bool $absolute = false): string
    {
        if ($path instanceof ContentPage) {
            $urlSiteConfig = $this->urlSiteConfigFor($path->language);
            $withBase = $this->pathRewriter->applyBasePathToPath($path->urlPath, $urlSiteConfig);
        } else {
            $urlSiteConfig = $this->urlSiteConfig();
            $withBase = $this->pathRewriter->applyBasePathToPath($path, $urlSiteConfig);
        }

        if (!$absolute) {
            return $withBase;
        }

        $baseUrl = rtrim($urlSiteConfig->baseUrl ?? '', '/');
        if ($baseUrl === '') {
            return $withBase;
        }

        return $baseUrl . $withBase;
    }

    /**
     * Return a site-root URL path with basePath applied but WITHOUT any language prefix.
     *
     * Use this for static assets served from the `/static` folder (images, SVGs, CSS,
     * JSON files, favicons, etc.) that are not language-specific and must not be
     * prefixed with a language segment like `/nl/`.
     *
     * Examples:
     * ```php
     * $this->rawUrl('/favicon.svg')         // '/favicon.svg'
     * $this->rawUrl('/assets/style.css')    // '/docs/assets/style.css' (with basePath=/docs)
     * $this->rawUrl('/favicon.svg', true)   // 'https://example.com/favicon.svg'
     * ```
     *
     * @param string $path Site-root path, for example '/favicon.svg'.
     * @param bool $absolute Whether to prepend the configured baseUrl.
     */
    public function rawUrl(string $path, bool $absolute = false): string
    {
        $withBase = $this->pathRewriter->applyBasePathToPath($path, $this->baseSiteConfig);

        if (!$absolute) {
            return $withBase;
        }

        $baseUrl = rtrim($this->baseSiteConfig->baseUrl ?? '', '/');
        if ($baseUrl === '') {
            return $withBase;
        }

        return $baseUrl . $withBase;
    }

    /**
     * Return the canonical fully-qualified URL for the current page.
     *
     * Shorthand for `url($this->currentPage->urlPath, true)`. Returns a relative URL when
     * `baseUrl` is not configured.
     */
    public function canonicalUrl(): string
    {
        return $this->url($this->currentPage->urlPath, true);
    }

    /**
     * Check if the current page URL matches a path.
     *
     * The argument is resolved through the same language-aware URL config as `url()`,
     * so templates can pass an un-prefixed site-root path (e.g. `'/about/'`) and the
     * comparison still works correctly on non-default-language pages.
     *
     * @param string $urlPath Site-root path to compare, e.g. `'/about/'`.
     */
    public function isCurrent(string $urlPath): bool
    {
        $resolved = $this->pathRewriter->applyBasePathToPath($urlPath, $this->urlSiteConfig());

        return $this->normalizeUrlPath($this->currentPage->urlPath) === $this->normalizeUrlPath($resolved);
    }

    /**
     * Invoke a named project extension and return its result.
     *
     * Extension results are memoized for the lifetime of the current build or request,
     * so expensive operations (HTTP fetches, file reads, etc.) run at most once
     * regardless of how many templates call the same extension.
     *
     * @param string $name Extension name as registered in `glaze.php`.
     * @param mixed ...$args Arguments forwarded to the extension on first invocation.
     * @throws \RuntimeException When the named extension is not registered.
     */
    public function extension(string $name, mixed ...$args): mixed
    {
        return $this->extensions->call($name, ...$args);
    }

    /**
     * Normalize collections to `PageCollection`.
     *
     * @param \Glaze\Template\Section|\Glaze\Template\Collection\PageCollection|array<\Glaze\Content\ContentPage> $collection Input collection.
     */
    protected function toCollection(Section|PageCollection|array $collection): PageCollection
    {
        if ($collection instanceof Section) {
            return $collection->pages();
        }

        if ($collection instanceof PageCollection) {
            return $collection;
        }

        return new PageCollection($collection);
    }

    /**
     * Return the language code of the current page.
     *
     * Returns an empty string when i18n is disabled (single-language site).
     */
    public function language(): string
    {
        return $this->currentPage->language;
    }

    /**
     * Return all configured languages, keyed by language code.
     *
     * Returns an empty array when i18n is disabled.
     *
     * @return array<string, \Glaze\Config\LanguageConfig>
     */
    public function languages(): array
    {
        return $this->i18nConfig->languages;
    }

    /**
     * Return all translations of the current page, keyed by language code.
     *
     * Uses the global site-wide index so that translations across all languages
     * are found even when the primary `$siteIndex` is language-scoped.
     * Returns an empty array when i18n is disabled or no translations exist.
     *
     * @return array<string, \Glaze\Content\ContentPage>
     */
    public function translations(): array
    {
        return $this->resolvedGlobalIndex()->translations($this->currentPage);
    }

    /**
     * Return the translation of the current page for a specific language code.
     *
     * Uses the global site-wide index for the same reason as `translations()`.
     * Returns null when no translation exists for the given language.
     *
     * @param string $language Language code.
     */
    public function translation(string $language): ?ContentPage
    {
        return $this->resolvedGlobalIndex()->translation($this->currentPage, $language);
    }

    /**
     * Return pages for the current language, excluding unlisted pages.
     *
     * An alias for `regularPages()` retained for template backward compatibility.
     * On single-language sites this is equivalent to `regularPages()`. On i18n
     * builds both methods already return only the current language's pages because
     * `$siteIndex` is language-scoped.
     */
    public function localizedPages(): PageCollection
    {
        return $this->regularPages();
    }

    /**
     * Translate a string key for the current page's language.
     *
     * Uses the configured i18n translations directory. Supports `{key}` placeholder
     * substitution via `$params`. Falls back to the default language when a key is
     * not found in the current language, and ultimately returns `$key` when no
     * translation exists anywhere.
     *
     * Example:
     * ```php
     * $this->t('read_more')                              // "Lees meer"
     * $this->t('posted_on', ['date' => '2024-01'])       // "Geplaatst op 2024-01"
     * $this->t('nav.home')                               // "Start"
     * ```
     *
     * @param string $key Dotted translation key.
     * @param array<string, string|int|float> $params Substitution parameters.
     * @param string $fallback Explicit fallback string returned when the key is not found in any language file.
     *   When omitted, the key itself is returned as the fallback.
     */
    public function t(string $key, array $params = [], string $fallback = ''): string
    {
        return $this->translationLoader()->translate(
            $this->currentPage->language,
            $key,
            $params,
            $fallback,
        );
    }

    /**
     * Return the URL for a specific language version of the current page.
     *
     * Uses the global site-wide index to find translations across all languages.
     * Returns null when no translation exists for the requested language or when
     * i18n is disabled. The returned URL has the target language's basePath applied,
     * which may differ from the current page's basePath when per-language site overrides
     * are configured.
     *
     * @param string $language Language code.
     */
    public function languageUrl(string $language): ?string
    {
        $translated = $this->resolvedGlobalIndex()->translation($this->currentPage, $language);
        if (!$translated instanceof ContentPage) {
            return null;
        }

        return $this->pathRewriter->applyBasePathToPath($translated->urlPath, $this->siteConfigFor($language));
    }

    /**
     * Normalize URL path for equality checks.
     *
     * @param string $urlPath URL path.
     */
    protected function normalizeUrlPath(string $urlPath): string
    {
        $trimmed = trim($urlPath);
        if ($trimmed === '') {
            return '/';
        }

        $normalized = '/' . trim($trimmed, '/');

        return $normalized === '/index' ? '/' : $normalized;
    }

    /**
     * Append untranslated fallback pages to a collection.
     *
     * For each fallback language (derived from `$withUntranslated`) every page in the global
     * index that belongs to that language and whose `translationKey` is not already represented
     * in `$pages` is appended. Pages are processed in fallback-language priority order so the
     * first language to cover a key wins; subsequent languages cannot overwrite it. The result
     * is a new `PageCollection` containing the original pages followed by all fallbacks.
     *
     * Returns `$pages` unchanged when `$withUntranslated` is `false`, i18n is disabled, or no
     * fallback candidates are found.
     *
     * @param list<string>|bool $withUntranslated `true` to use the configured default language,
     *   or a list of language codes to specify fallback languages in priority order.
     */
    protected function withUntranslatedFallback(PageCollection $pages, bool|array $withUntranslated): PageCollection
    {
        if ($withUntranslated === false) {
            return $pages;
        }

        /** @var list<string> $fallbackLanguages */
        $fallbackLanguages = $withUntranslated === true
            ? ($this->i18nConfig->defaultLanguage !== null ? [$this->i18nConfig->defaultLanguage] : [])
            : array_values($withUntranslated);

        if ($fallbackLanguages === []) {
            return $pages;
        }

        // Index translationKeys already present in the current-language collection.
        $coveredKeys = [];
        foreach ($pages->all() as $page) {
            if ($page->translationKey !== '') {
                $coveredKeys[$page->translationKey] = true;
            }
        }

        $fallbackPages = [];
        foreach ($fallbackLanguages as $fallbackLanguage) {
            foreach ($this->resolvedGlobalIndex()->all() as $page) {
                if ($page->language !== $fallbackLanguage) {
                    continue;
                }

                if ($page->translationKey !== '' && isset($coveredKeys[$page->translationKey])) {
                    continue;
                }

                $fallbackPages[] = $page;
                if ($page->translationKey !== '') {
                    $coveredKeys[$page->translationKey] = true;
                }
            }
        }

        if ($fallbackPages === []) {
            return $pages;
        }

        return new PageCollection(array_merge($pages->all(), $fallbackPages));
    }

    /**
     * Return the effective SiteConfig for URL resolution for the current page.
     *
     * Composes the current language's `urlPrefix` into `$siteConfig->basePath` so that
     * `url()` automatically prepends the language prefix without requiring every template
     * call to be aware of i18n. The result is memoized for the lifetime of the context.
     *
     * Examples (urlPrefix='nl', basePath=null):
     * - `url('/')` → `/nl/`
     * - `url('/about/')` → `/nl/about/`
     * - `url('/nl/about/')` → `/nl/about/` (idempotent via applyBasePathToPath)
     *
     * Examples (urlPrefix='nl', basePath='/docs'):
     * - `url('/about/')` → `/docs/nl/about/`
     */
    protected function urlSiteConfig(): SiteConfig
    {
        if ($this->resolvedUrlSiteConfig instanceof SiteConfig) {
            return $this->resolvedUrlSiteConfig;
        }

        $langConfig = $this->i18nConfig->language($this->currentPage->language);
        $urlPrefix = $langConfig instanceof LanguageConfig ? trim($langConfig->urlPrefix, '/') : '';

        if ($urlPrefix === '') {
            return $this->resolvedUrlSiteConfig = $this->siteConfig;
        }

        $prefix = '/' . $urlPrefix;
        $basePath = $this->siteConfig->basePath;
        $composedBasePath = $basePath !== null && $basePath !== ''
            ? rtrim($basePath, '/') . $prefix
            : $prefix;

        return $this->resolvedUrlSiteConfig = new SiteConfig(
            title: $this->siteConfig->title,
            description: $this->siteConfig->description,
            baseUrl: $this->siteConfig->baseUrl,
            basePath: $composedBasePath,
            meta: $this->siteConfig->meta,
        );
    }

    /**
     * Return the effective SiteConfig for URL resolution for a specific language.
     *
     * Composes the given language's `urlPrefix` into `$baseSiteConfig->basePath`. This
     * lets `url(ContentPage)` build links to pages in any language — including EN fallback
     * posts rendered on an NL page via `withUntranslated` — using the correct prefix.
     *
     * Examples (urlPrefix='nl', basePath=null):
     * - called for 'nl': basePath becomes `/nl`
     * - called for 'en' (no prefix): basePath unchanged
     *
     * @param string $language Language code.
     */
    protected function urlSiteConfigFor(string $language): SiteConfig
    {
        $langConfig = $this->i18nConfig->language($language);
        $urlPrefix = $langConfig instanceof LanguageConfig ? trim($langConfig->urlPrefix, '/') : '';

        if ($urlPrefix === '') {
            return $this->baseSiteConfig;
        }

        $prefix = '/' . $urlPrefix;
        $basePath = $this->baseSiteConfig->basePath;
        $composedBasePath = $basePath !== null && $basePath !== ''
            ? rtrim($basePath, '/') . $prefix
            : $prefix;

        return new SiteConfig(
            title: $this->baseSiteConfig->title,
            description: $this->baseSiteConfig->description,
            baseUrl: $this->baseSiteConfig->baseUrl,
            basePath: $composedBasePath,
            meta: $this->baseSiteConfig->meta,
        );
    }

    /**
     * Return the effective SiteConfig for a given language.
     *
     * Merges the base site configuration with any per-language `site` overrides
     * defined in the matching LanguageConfig. Returns the base config unchanged
     * when the language has no overrides or is not found.
     *
     * @param string $language Language code to resolve the config for.
     */
    protected function siteConfigFor(string $language): SiteConfig
    {
        $langConfig = $this->i18nConfig->language($language);
        if (!$langConfig instanceof LanguageConfig || $langConfig->siteOverrides === []) {
            return $this->baseSiteConfig;
        }

        return $this->baseSiteConfig->withLanguageOverrides($langConfig->siteOverrides);
    }

    /**
     * Return the global site-wide index for cross-language lookups.
     *
     * Falls back to `$siteIndex` when no explicit global index was provided,
     * which is correct for single-language builds where the two are identical.
     */
    protected function resolvedGlobalIndex(): SiteIndex
    {
        return $this->globalIndex ?? $this->siteIndex;
    }

    /**
     * Return the memoized TranslationLoader, lazily constructed from the translations path.
     */
    protected function translationLoader(): TranslationLoader
    {
        if ($this->translationLoader instanceof TranslationLoader) {
            return $this->translationLoader;
        }

        $this->translationLoader = new TranslationLoader(
            $this->translationsPath,
            $this->i18nConfig->defaultLanguage ?? '',
        );

        return $this->translationLoader;
    }
}
