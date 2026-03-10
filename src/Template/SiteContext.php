<?php
declare(strict_types=1);

namespace Glaze\Template;

use Glaze\Config\I18nConfig;
use Glaze\Config\SiteConfig;
use Glaze\Content\ContentPage;
use Glaze\Support\ResourcePathRewriter;
use Glaze\Support\TranslationLoader;
use Glaze\Template\Collection\ContentAssetCollection;
use Glaze\Template\Collection\PageCollection;
use Glaze\Template\Extension\ExtensionRegistry;

/**
 * Template context facade exposed to Sugar templates as `$this`.
 */
final class SiteContext
{
    /**
     * Memoized TranslationLoader instance.
     */
    protected ?TranslationLoader $translationLoader = null;

    /**
     * Constructor.
     *
     * @param \Glaze\Template\SiteIndex $siteIndex Site-wide page index.
     * @param \Glaze\Content\ContentPage $currentPage Current page being rendered.
     * @param \Glaze\Template\Extension\ExtensionRegistry $extensions Registered project extensions.
     * @param \Glaze\Template\ContentAssetResolver|null $assetResolver Optional content asset resolver.
     * @param \Glaze\Config\SiteConfig $siteConfig Site configuration used for URL helpers.
     * @param \Glaze\Support\ResourcePathRewriter $pathRewriter Path rewriter used by URL helpers.
     * @param \Glaze\Config\I18nConfig $i18nConfig I18n configuration for language helpers and string translation.
     * @param string $translationsPath Absolute path to the i18n translations directory.
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
     * Return default-sorted regular pages, excluding unlisted pages.
     */
    public function regularPages(): PageCollection
    {
        return $this->siteIndex->regularPages();
    }

    /**
     * Return all pages including unlisted ones.
     *
     * Unlike `regularPages()`, this collection retains pages marked as unlisted
     * (e.g. `_index.dj` section overview pages).
     */
    public function allPages(): PageCollection
    {
        return $this->siteIndex->allPages();
    }

    /**
     * Alias for regular pages.
     */
    public function pages(): PageCollection
    {
        return $this->regularPages();
    }

    /**
     * Return pages matching a resolved content type.
     *
     * @param string $type Content type name.
     */
    public function type(string $type): PageCollection
    {
        return $this->regularPages()->whereType($type);
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
     * Return a site-root URL path with basePath applied.
     *
     * When `$absolute` is true, the configured `baseUrl` is prepended to produce
     * a fully-qualified URL. Falls back to a relative path when `baseUrl` is not
     * configured.
     *
     * Example:
     * ```php
     * $this->url('/about/')           // '/docs/about/' (with basePath=/docs)
     * $this->url('/about/', true)     // 'https://example.com/docs/about/'
     * ```
     *
     * @param string $path Site-root path, for example '/about/'.
     * @param bool $absolute Whether to prepend the configured baseUrl.
     */
    public function url(string $path, bool $absolute = false): string
    {
        $withBase = $this->pathRewriter->applyBasePathToPath($path, $this->siteConfig);

        if (!$absolute) {
            return $withBase;
        }

        $baseUrl = rtrim($this->siteConfig->baseUrl ?? '', '/');
        if ($baseUrl === '') {
            return $withBase;
        }

        return $baseUrl . $withBase;
    }

    /**
     * Return the canonical fully-qualified URL for the current page.
     *
     * Shorthand for `url(page()->urlPath, true)`. Returns a relative URL when
     * `baseUrl` is not configured.
     */
    public function canonicalUrl(): string
    {
        return $this->url($this->currentPage->urlPath, true);
    }

    /**
     * Check if the current page URL matches a path.
     *
     * @param string $urlPath Path to compare.
     */
    public function isCurrent(string $urlPath): bool
    {
        return $this->normalizeUrlPath($this->currentPage->urlPath) === $this->normalizeUrlPath($urlPath);
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
     * Returns an empty array when i18n is disabled or no translations exist.
     *
     * @return array<string, \Glaze\Content\ContentPage>
     */
    public function translations(): array
    {
        return $this->siteIndex->translations($this->currentPage);
    }

    /**
     * Return the translation of the current page for a specific language code.
     *
     * Returns null when no translation exists for the given language.
     *
     * @param string $language Language code.
     */
    public function translation(string $language): ?ContentPage
    {
        return $this->siteIndex->translation($this->currentPage, $language);
    }

    /**
     * Return pages from the site index filtered to the current page's language.
     *
     * Equivalent to `regularPages()` on single-language sites, or to
     * `forLanguage($this->language())` when i18n is enabled.
     */
    public function localizedPages(): PageCollection
    {
        $language = $this->currentPage->language;
        if ($language === '') {
            return $this->siteIndex->regularPages();
        }

        return $this->siteIndex->forLanguage($language);
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
     */
    public function t(string $key, array $params = []): string
    {
        return $this->translationLoader()->translate(
            $this->currentPage->language,
            $key,
            $params,
        );
    }

    /**
     * Return the URL for a specific language version of the current page.
     *
     * Returns null when no translation exists for the requested language or when
     * i18n is disabled. The returned URL has the site basePath applied.
     *
     * @param string $language Language code.
     */
    public function languageUrl(string $language): ?string
    {
        $translated = $this->siteIndex->translation($this->currentPage, $language);
        if (!$translated instanceof ContentPage) {
            return null;
        }

        return $this->url($translated->urlPath);
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
