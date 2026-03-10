<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Template;

use Glaze\Config\I18nConfig;
use Glaze\Config\LanguageConfig;
use Glaze\Config\SiteConfig;
use Glaze\Content\ContentAsset;
use Glaze\Content\ContentPage;
use Glaze\Template\ContentAssetResolver;
use Glaze\Template\Extension\ExtensionRegistry;
use Glaze\Template\SiteContext;
use Glaze\Template\SiteIndex;
use Glaze\Tests\Helper\FilesystemTestTrait;
use Glaze\Tests\Helper\I18nTestTrait;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for template context facade behavior.
 */
final class SiteContextTest extends TestCase
{
    use FilesystemTestTrait;
    use I18nTestTrait;

    /**
     * Validate context query and pagination helpers.
     */
    public function testContextExposesQueryAndPaginationHelpers(): void
    {
        $index = new SiteIndex([
            $this->makePage('index', '/', 'index.dj', ['type' => 'page'], ['tags' => ['home']]),
            $this->makePage('blog/a', '/blog/a/', 'blog/a.dj', ['date' => '2026-01-01', 'type' => 'blog'], ['tags' => ['php']]),
            $this->makePage('blog/b', '/blog/b/', 'blog/b.dj', ['date' => '2026-01-02', 'type' => 'blog'], ['tags' => ['php']]),
        ]);

        $context = new SiteContext($index, $index->findBySlug('blog/a') ?? $this->fail('Missing current page'));
        $blogSection = $context->section('blog') ?? $this->fail('Missing blog section');

        $this->assertSame('blog/a', $context->page()->slug);
        $this->assertCount(3, $context->regularPages());
        $this->assertCount(2, $blogSection);
        $this->assertCount(2, $context->type('blog'));
        $this->assertCount(2, $context->taxonomy('tags')->term('php'));
        $this->assertCount(2, $context->taxonomyTerm('tags', 'php'));
        $this->assertSame('blog/b', $context->previousInSection()?->slug);

        $pager = $context->paginate($blogSection, 1, 2, '/blog/');
        $this->assertSame(2, $pager->totalPages());
        $this->assertSame('/blog/page/2/', $pager->url());
        $this->assertSame('/blog/', $pager->prevUrl());
        $this->assertNull($pager->nextUrl());
    }

    /**
     * Validate current URL matching and where helper.
     */
    public function testContextCurrentAndWhereHelpers(): void
    {
        $index = new SiteIndex([
            $this->makePage('index', '/', 'index.dj', [], ['tags' => ['home']]),
            $this->makePage('docs/intro', '/docs/intro/', 'docs/intro.dj', [], ['tags' => ['docs']]),
        ]);

        $context = new SiteContext($index, $index->findBySlug('docs/intro') ?? $this->fail('Missing page'));

        $filtered = $context->where($context->regularPages(), 'slug', 'docs/intro');

        $this->assertTrue($context->isCurrent('/docs/intro/'));
        $this->assertFalse($context->isCurrent('/'));
        $this->assertCount(1, $filtered);
        $this->assertSame('docs/intro', $filtered->first()?->slug);

        $this->assertCount(2, $context->pages());
        $this->assertSame('docs/intro', $context->bySlug('docs/intro')?->slug);
        $this->assertSame('docs/intro', $context->byUrl('/docs/intro/')?->slug);
        $this->assertNotInstanceOf(ContentPage::class, $context->bySlug('missing'));
        $this->assertNotInstanceOf(ContentPage::class, $context->byUrl('/missing/'));

        $arrayFiltered = $context->where($context->regularPages()->all(), 'slug', 'docs/intro');
        $this->assertCount(1, $arrayFiltered);

        $operatorFiltered = $context->where($context->regularPages(), 'slug', 'eq', 'docs/intro');
        $this->assertCount(1, $operatorFiltered);

        $docsSection = $context->section('docs') ?? $this->fail('Missing docs section');
        $defaultPager = $context->paginate($docsSection, 1, 1);
        $this->assertSame('/docs/intro/', $defaultPager->url());
        $this->assertSame($context, $context->site());

        $homeContext = new SiteContext($index, $index->findBySlug('index') ?? $this->fail('Missing home page'));
        $this->assertTrue($homeContext->isCurrent('/index'));
        $this->assertTrue($homeContext->isCurrent('   '));
    }

    /**
     * Validate that the extension() method delegates to the injected ExtensionRegistry.
     */
    public function testExtensionMethodDelegatesToRegistry(): void
    {
        $registry = new ExtensionRegistry();
        $registry->register('greet', fn(string $who) => sprintf('Hello, %s!', $who));

        $index = new SiteIndex([
            $this->makePage('index', '/', 'index.dj', []),
        ]);

        $context = new SiteContext(
            $index,
            $index->findBySlug('index') ?? $this->fail('Missing index page'),
            $registry,
        );

        $this->assertSame('Hello, World!', $context->extension('greet', 'World'));
    }

    /**
     * Validate that extension() throws RuntimeException for unknown names.
     */
    public function testExtensionMethodThrowsForUnknownName(): void
    {
        $index = new SiteIndex([
            $this->makePage('index', '/', 'index.dj', []),
        ]);

        $context = new SiteContext(
            $index,
            $index->findBySlug('index') ?? $this->fail('Missing index page'),
        );

        $this->expectException(RuntimeException::class);

        $context->extension('nonexistent');
    }

    /**
     * Validate sections(), rootPages(), and tree/section access delegation.
     */
    public function testContextExposesSectionsAndRootPages(): void
    {
        $index = new SiteIndex([
            $this->makePage('index', '/', 'index.dj', ['weight' => 1]),
            $this->makePage('docs/intro', '/docs/intro/', 'docs/intro.dj', ['weight' => 10]),
            $this->makePage('docs/setup', '/docs/setup/', 'docs/setup.dj', ['weight' => 20]),
            $this->makePage('blog/post', '/blog/post/', 'blog/post.dj', ['weight' => 30]),
        ]);

        $context = new SiteContext($index, $index->findBySlug('index') ?? $this->fail('Missing page'));

        $sections = $context->sections();
        $this->assertCount(2, $sections);
        $this->assertArrayHasKey('docs', $sections);
        $this->assertArrayHasKey('blog', $sections);
        $this->assertCount(2, $sections['docs']);
        $this->assertSame('Docs', $sections['docs']->label());
        $this->assertSame('docs', $sections['docs']->path());
        $this->assertSame($context->tree(), $context->section(''));

        $root = $context->rootPages();
        $this->assertCount(1, $root);
    }

    /**
     * Validate previous()/next() delegation through SiteContext with weight interleaving.
     */
    public function testContextExposesPreviousAndNext(): void
    {
        $index = new SiteIndex([
            $this->makePage('intro', '/intro/', 'intro.dj', ['weight' => 0]),
            $this->makePage('docs/a', '/docs/a/', 'docs/a.dj', ['weight' => 10]),
            $this->makePage('docs/b', '/docs/b/', 'docs/b.dj', ['weight' => 20]),
        ]);

        // Root page with weight 0 comes first
        $introContext = new SiteContext($index, $index->findBySlug('intro') ?? $this->fail('Missing page'));
        $this->assertNotInstanceOf(ContentPage::class, $introContext->previous());
        $this->assertSame('docs/a', $introContext->next()?->slug);

        $aContext = new SiteContext($index, $index->findBySlug('docs/a') ?? $this->fail('Missing page'));
        $this->assertSame('intro', $aContext->previous()?->slug);
        $this->assertSame('docs/b', $aContext->next()?->slug);

        $bContext = new SiteContext($index, $index->findBySlug('docs/b') ?? $this->fail('Missing page'));
        $this->assertSame('docs/a', $bContext->previous()?->slug);
        $this->assertNotInstanceOf(ContentPage::class, $bContext->next());
    }

    /**
     * Validate previous()/next() support skipping pages via predicate callbacks.
     */
    public function testContextPreviousAndNextSupportPredicate(): void
    {
        $index = new SiteIndex([
            $this->makePage('intro', '/intro/', 'intro.dj', ['weight' => 0]),
            $this->makePage('docs/hidden', '/docs/hidden/', 'docs/hidden.dj', ['weight' => 10, 'navigation' => false]),
            $this->makePage('docs/visible', '/docs/visible/', 'docs/visible.dj', ['weight' => 20]),
        ]);

        $introContext = new SiteContext($index, $index->findBySlug('intro') ?? $this->fail('Missing page'));
        $visibleContext = new SiteContext($index, $index->findBySlug('docs/visible') ?? $this->fail('Missing page'));

        $isNavigable = static fn(ContentPage $page): bool => (bool)($page->meta('navigation') ?? true);

        $this->assertSame('docs/visible', $introContext->next($isNavigable)?->slug);
        $this->assertSame('intro', $visibleContext->previous($isNavigable)?->slug);
    }

    /**
     * Validate content asset helpers for root, page, and section-level access.
     */
    public function testContextExposesContentAssetHelpers(): void
    {
        $contentPath = $this->createTempDirectory();
        mkdir($contentPath . '/blog/gallery', 0755, true);
        mkdir($contentPath . '/blog/posts/first', 0755, true);
        mkdir($contentPath . '/shared', 0755, true);

        file_put_contents($contentPath . '/blog/index.dj', '# Blog');
        file_put_contents($contentPath . '/blog/cover.png', 'cover');
        file_put_contents($contentPath . '/blog/gallery/a.png', 'gallery');
        file_put_contents($contentPath . '/blog/posts/first/index.dj', '# First');
        file_put_contents($contentPath . '/blog/posts/first/hero.jpg', 'hero');
        file_put_contents($contentPath . '/shared/logo.svg', '<svg></svg>');

        $blogPage = new ContentPage(
            sourcePath: $contentPath . '/blog/index.dj',
            relativePath: 'blog/index.dj',
            slug: 'blog',
            urlPath: '/blog/',
            outputRelativePath: 'blog/index.html',
            title: 'Blog',
            source: '# Blog',
            draft: false,
            meta: [],
            taxonomies: [],
        );
        $firstPostPage = new ContentPage(
            sourcePath: $contentPath . '/blog/posts/first/index.dj',
            relativePath: 'blog/posts/first/index.dj',
            slug: 'blog/posts/first',
            urlPath: '/blog/posts/first/',
            outputRelativePath: 'blog/posts/first/index.html',
            title: 'First',
            source: '# First',
            draft: false,
            meta: [],
            taxonomies: [],
        );

        $assetResolver = new ContentAssetResolver($contentPath, '/docs');
        $index = new SiteIndex([$blogPage, $firstPostPage], $assetResolver);
        $context = new SiteContext($index, $blogPage, new ExtensionRegistry(), $assetResolver);

        $this->assertCount(1, $context->assets('shared'));
        $this->assertSame('/docs/shared/logo.svg', $context->assets('shared')->first()?->urlPath);

        $this->assertCount(1, $context->pageAssets());
        $this->assertSame('blog/cover.png', $context->pageAssets()->first()?->relativePath);
        $this->assertCount(1, $context->pageAssets('gallery'));
        $this->assertSame('blog/gallery/a.png', $context->pageAssets('gallery')->first()?->relativePath);

        $this->assertCount(1, $context->assetsFor($firstPostPage));
        $this->assertSame('blog/posts/first/hero.jpg', $context->assetsFor($firstPostPage)->first()?->relativePath);

        $blogSection = $context->section('blog') ?? $this->fail('Missing blog section');
        $this->assertCount(1, $blogSection->assets());
        $this->assertSame('blog/cover.png', $blogSection->assets()->first()?->relativePath);

        $this->assertCount(3, $blogSection->allAssets());
        $allAssetPaths = array_map(
            static fn(ContentAsset $asset): string => $asset->relativePath,
            $blogSection->allAssets()->sortByName()->all(),
        );
        $this->assertSame([
            'blog/gallery/a.png',
            'blog/cover.png',
            'blog/posts/first/hero.jpg',
        ], $allAssetPaths);

        $noResolverContext = new SiteContext($index, $blogPage);
        $this->assertCount(0, $noResolverContext->assets());
        $this->assertCount(0, $noResolverContext->pageAssets());
    }

    /**
     * Validate url() applies basePath and optionally prepends baseUrl.
     */
    public function testUrlAppliesBasePathAndOptionalBaseUrl(): void
    {
        $index = new SiteIndex([
            $this->makePage('docs/intro', '/docs/intro/', 'docs/intro.dj', []),
        ]);
        $page = $index->findBySlug('docs/intro') ?? $this->fail('Missing page');

        $noConfig = new SiteContext($index, $page);
        $this->assertSame('/about/', $noConfig->url('/about/'));
        $this->assertSame('/about/', $noConfig->url('/about/', true));

        $withBasePath = new SiteContext(
            siteIndex: $index,
            currentPage: $page,
            siteConfig: new SiteConfig(basePath: '/glaze'),
        );
        $this->assertSame('/glaze/about/', $withBasePath->url('/about/'));
        $this->assertSame('/glaze/about/', $withBasePath->url('/about/', true));

        $withBoth = new SiteContext(
            siteIndex: $index,
            currentPage: $page,
            siteConfig: new SiteConfig(baseUrl: 'https://example.com', basePath: '/glaze'),
        );
        $this->assertSame('/glaze/about/', $withBoth->url('/about/'));
        $this->assertSame('https://example.com/glaze/about/', $withBoth->url('/about/', true));
    }

    /**
     * Validate canonicalUrl() returns the fully-qualified URL for the current page.
     */
    public function testCanonicalUrlReturnsFullyQualifiedPageUrl(): void
    {
        $index = new SiteIndex([
            $this->makePage('docs/intro', '/docs/intro/', 'docs/intro.dj', []),
        ]);
        $page = $index->findBySlug('docs/intro') ?? $this->fail('Missing page');

        $noConfig = new SiteContext($index, $page);
        $this->assertSame('/docs/intro/', $noConfig->canonicalUrl());

        $withBoth = new SiteContext(
            siteIndex: $index,
            currentPage: $page,
            siteConfig: new SiteConfig(baseUrl: 'https://example.com', basePath: '/glaze'),
        );
        $this->assertSame('https://example.com/glaze/docs/intro/', $withBoth->canonicalUrl());
    }

    /**
     * Create a content page object for test scenarios.
     *
     * @param string $slug Page slug.
     * @param string $urlPath Page URL path.
     * @param string $relativePath Relative source path.
     * @param array<string, mixed> $meta Page metadata.
     * @param array<string, array<string>> $taxonomies Page taxonomies.
     */
    protected function makePage(
        string $slug,
        string $urlPath,
        string $relativePath,
        array $meta,
        array $taxonomies = [],
    ): ContentPage {
        return new ContentPage(
            sourcePath: '/tmp/' . str_replace('/', '-', $slug) . '.dj',
            relativePath: $relativePath,
            slug: $slug,
            urlPath: $urlPath,
            outputRelativePath: $slug === 'index' ? 'index.html' : trim($slug, '/') . '/index.html',
            title: ucfirst(basename($slug)),
            source: '# ' . $slug,
            draft: false,
            meta: $meta,
            taxonomies: $taxonomies,
        );
    }

    // -------------------------------------------------------------------------
    // i18n: language() and languages()
    // -------------------------------------------------------------------------

    /**
     * Validate language() returns the current page language code.
     */
    public function testLanguageReturnsCurrentPageLanguage(): void
    {
        $enLang = new LanguageConfig('en', 'English');
        $nlLang = new LanguageConfig('nl', 'Nederlands', 'nl');
        $i18n = new I18nConfig('en', ['en' => $enLang, 'nl' => $nlLang]);

        $nlPage = $this->makeLocalizedPage('nl/about', '/nl/about/', 'about.dj', 'nl', 'about.dj', 'About');
        $index = new SiteIndex([$nlPage]);

        $context = new SiteContext($index, $nlPage, i18nConfig: $i18n);

        $this->assertSame('nl', $context->language());
    }

    /**
     * Validate language() returns an empty string when i18n is disabled.
     */
    public function testLanguageReturnsEmptyStringWhenI18nDisabled(): void
    {
        $index = new SiteIndex([$this->makePage('index', '/', 'index.dj', [])]);
        $page = $index->findBySlug('index') ?? $this->fail('missing');
        $context = new SiteContext($index, $page);

        $this->assertSame('', $context->language());
    }

    /**
     * Validate languages() returns the configured language map.
     */
    public function testLanguagesReturnsAllConfiguredLanguages(): void
    {
        $enLang = new LanguageConfig('en', 'English');
        $nlLang = new LanguageConfig('nl', 'Nederlands', 'nl');
        $i18n = new I18nConfig('en', ['en' => $enLang, 'nl' => $nlLang]);

        $page = $this->makeLocalizedPage('index', '/', 'index.dj', 'en', 'index.dj');
        $index = new SiteIndex([$page]);
        $context = new SiteContext($index, $page, i18nConfig: $i18n);

        $languages = $context->languages();
        $this->assertArrayHasKey('en', $languages);
        $this->assertArrayHasKey('nl', $languages);
        $this->assertSame($enLang, $languages['en']);
    }

    /**
     * Validate languages() returns an empty array when i18n is disabled.
     */
    public function testLanguagesReturnsEmptyArrayWhenI18nDisabled(): void
    {
        $index = new SiteIndex([$this->makePage('index', '/', 'index.dj', [])]);
        $page = $index->findBySlug('index') ?? $this->fail('missing');
        $context = new SiteContext($index, $page);

        $this->assertSame([], $context->languages());
    }

    // -------------------------------------------------------------------------
    // i18n: translations() and translation()
    // -------------------------------------------------------------------------

    /**
     * Validate translations() returns all translations of the current page.
     */
    public function testTranslationsReturnsAllPageTranslations(): void
    {
        $i18n = new I18nConfig('en', [
            'en' => new LanguageConfig('en'),
            'nl' => new LanguageConfig('nl', '', 'nl'),
        ]);

        $enAbout = $this->makeLocalizedPage('about', '/about/', 'about.dj', 'en', 'about.dj', 'About');
        $nlAbout = $this->makeLocalizedPage('nl/about', '/nl/about/', 'about.dj', 'nl', 'about.dj', 'Over ons');
        $index = new SiteIndex([$enAbout, $nlAbout]);

        $context = new SiteContext($index, $enAbout, i18nConfig: $i18n);

        $translations = $context->translations();
        $this->assertArrayHasKey('en', $translations);
        $this->assertArrayHasKey('nl', $translations);
    }

    /**
     * Validate translation() returns the page for the requested language.
     */
    public function testTranslationReturnsPageForRequestedLanguage(): void
    {
        $enAbout = $this->makeLocalizedPage('about', '/about/', 'about.dj', 'en', 'about.dj', 'About');
        $nlAbout = $this->makeLocalizedPage('nl/about', '/nl/about/', 'about.dj', 'nl', 'about.dj', 'Over ons');
        $index = new SiteIndex([$enAbout, $nlAbout]);

        $context = new SiteContext($index, $enAbout);

        $this->assertSame($nlAbout, $context->translation('nl'));
        $this->assertNotInstanceOf(ContentPage::class, $context->translation('fr'));
    }

    // -------------------------------------------------------------------------
    // i18n: localizedPages()
    // -------------------------------------------------------------------------

    /**
     * Validate localizedPages() returns all pages when i18n is disabled (no language tag).
     */
    public function testLocalizedPagesReturnsAllPagesWhenI18nDisabled(): void
    {
        $index = new SiteIndex([
            $this->makePage('index', '/', 'index.dj', []),
            $this->makePage('about', '/about/', 'about.dj', []),
        ]);
        $page = $index->findBySlug('index') ?? $this->fail('missing');
        $context = new SiteContext($index, $page);

        $this->assertCount(2, $context->localizedPages());
    }

    /**
     * Validate localizedPages() returns only pages for the current page's language
     * when a language-scoped siteIndex is provided (i18n build pattern).
     */
    public function testLocalizedPagesFiltersToCurrentLanguage(): void
    {
        $enAbout = $this->makeLocalizedPage('about', '/about/', 'about.dj', 'en', 'about.dj', 'About');
        $enHome = $this->makeLocalizedPage('index', '/', 'index.dj', 'en', 'index.dj', 'Home');
        $nlAbout = $this->makeLocalizedPage('nl/about', '/nl/about/', 'about.dj', 'nl', 'about.dj', 'Over ons');

        // Simulate SiteBuilder: siteIndex scoped to EN, globalIndex has all pages
        $enIndex = new SiteIndex([$enAbout, $enHome]);
        $globalIndex = new SiteIndex([$enAbout, $enHome, $nlAbout]);

        $context = new SiteContext(siteIndex: $enIndex, currentPage: $enAbout, globalIndex: $globalIndex);

        $pages = $context->localizedPages();
        $this->assertCount(2, $pages);
        foreach ($pages->all() as $page) {
            $this->assertSame('en', $page->language);
        }
    }

    // -------------------------------------------------------------------------
    // i18n: t()
    // -------------------------------------------------------------------------

    /**
     * Validate t() translates a string for the current page language.
     */
    public function testTTranslatesStringForCurrentLanguage(): void
    {
        $dir = $this->createTempDirectory();
        file_put_contents($dir . '/nl.neon', "read_more: Lees meer\n");

        $i18n = new I18nConfig('en', [
            'en' => new LanguageConfig('en'),
            'nl' => new LanguageConfig('nl', '', 'nl'),
        ]);

        $nlPage = $this->makeLocalizedPage('nl/about', '/nl/about/', 'about.dj', 'nl', 'about.dj');
        $index = new SiteIndex([$nlPage]);

        $context = new SiteContext($index, $nlPage, i18nConfig: $i18n, translationsPath: $dir);

        $this->assertSame('Lees meer', $context->t('read_more'));
    }

    /**
     * Validate t() substitutes parameters in the translated string.
     */
    public function testTSubstitutesParameters(): void
    {
        $dir = $this->createTempDirectory();
        file_put_contents($dir . '/en.neon', "greeting: \"Hello {name}!\"\n");

        $i18n = new I18nConfig('en', ['en' => new LanguageConfig('en')]);
        $enPage = $this->makeLocalizedPage('index', '/', 'index.dj', 'en', 'index.dj');
        $index = new SiteIndex([$enPage]);

        $context = new SiteContext($index, $enPage, i18nConfig: $i18n, translationsPath: $dir);

        $this->assertSame('Hello World!', $context->t('greeting', ['name' => 'World']));
    }

    /**
     * Validate t() returns the key when no translation is found.
     */
    public function testTReturnsKeyWhenNoTranslationFound(): void
    {
        $dir = $this->createTempDirectory();
        $i18n = new I18nConfig('en', ['en' => new LanguageConfig('en')]);
        $enPage = $this->makeLocalizedPage('index', '/', 'index.dj', 'en', 'index.dj');
        $index = new SiteIndex([$enPage]);

        $context = new SiteContext($index, $enPage, i18nConfig: $i18n, translationsPath: $dir);

        $this->assertSame('missing.key', $context->t('missing.key'));
    }

    // -------------------------------------------------------------------------
    // i18n: languageUrl()
    // -------------------------------------------------------------------------

    /**
     * Validate languageUrl() returns the URL of the translated page for a given language.
     */
    public function testLanguageUrlReturnsTranslatedPageUrl(): void
    {
        $enAbout = $this->makeLocalizedPage('about', '/about/', 'about.dj', 'en', 'about.dj', 'About');
        $nlAbout = $this->makeLocalizedPage('nl/about', '/nl/about/', 'about.dj', 'nl', 'about.dj', 'Over ons');
        $index = new SiteIndex([$enAbout, $nlAbout]);

        $context = new SiteContext($index, $enAbout);

        $this->assertSame('/nl/about/', $context->languageUrl('nl'));
    }

    /**
     * Validate languageUrl() returns null when no translation exists for the requested language.
     */
    public function testLanguageUrlReturnsNullWhenNoTranslation(): void
    {
        $enAbout = $this->makeLocalizedPage('about', '/about/', 'about.dj', 'en', 'about.dj', 'About');
        $index = new SiteIndex([$enAbout]);

        $context = new SiteContext($index, $enAbout);

        $this->assertNull($context->languageUrl('fr'));
    }

    /**
     * Validate t() uses a memoized TranslationLoader instance on repeated calls.
     */
    public function testTranslationLoaderIsMemoized(): void
    {
        $dir = $this->createTempDirectory();
        file_put_contents($dir . '/en.neon', "key: Value\n");

        $i18n = new I18nConfig('en', ['en' => new LanguageConfig('en')]);
        $enPage = $this->makeLocalizedPage('index', '/', 'index.dj', 'en', 'index.dj');
        $index = new SiteIndex([$enPage]);

        $context = new SiteContext($index, $enPage, i18nConfig: $i18n, translationsPath: $dir);

        // Both calls must return the same value (memoized loader)
        $this->assertSame('Value', $context->t('key'));
        $this->assertSame('Value', $context->t('key'));
    }

    // -------------------------------------------------------------------------
    // Dual-index: language-scoped navigation + cross-language translation lookups
    // -------------------------------------------------------------------------

    /**
     * Validate regularPages() and allPages() are scoped to the language-specific siteIndex
     * and do not return pages from other languages when a globalIndex is provided.
     */
    public function testNavigationMethodsAreScopedToLanguageSiteIndex(): void
    {
        $enPage = $this->makeLocalizedPage('about', '/about/', 'about.dj', 'en', 'about.dj', 'About');
        $enHome = $this->makeLocalizedPage('index', '/', 'index.dj', 'en', 'index.dj', 'Home');
        $nlPage = $this->makeLocalizedPage('nl/about', '/nl/about/', 'about.dj', 'nl', 'about.dj', 'Over ons');

        $enIndex = new SiteIndex([$enPage, $enHome]);
        $globalIndex = new SiteIndex([$enPage, $enHome, $nlPage]);

        $context = new SiteContext(
            siteIndex: $enIndex,
            currentPage: $enHome,
            globalIndex: $globalIndex,
        );

        // Navigation is scoped to the EN siteIndex only
        $this->assertCount(2, $context->regularPages());
        $this->assertCount(2, $context->allPages());

        $slugs = array_map(static fn(ContentPage $p): string => $p->slug, $context->regularPages()->all());
        $this->assertNotContains('nl/about', $slugs);
    }

    /**
     * Validate translations() and translation() use the globalIndex for cross-language lookups
     * even when siteIndex is language-scoped.
     */
    public function testTranslationMethodsUseGlobalIndexForCrossLanguageLookup(): void
    {
        $enPage = $this->makeLocalizedPage('about', '/about/', 'about.dj', 'en', 'about.dj', 'About');
        $nlPage = $this->makeLocalizedPage('nl/about', '/nl/about/', 'about.dj', 'nl', 'about.dj', 'Over ons');

        // siteIndex scoped to EN only
        $enIndex = new SiteIndex([$enPage]);
        // globalIndex has all languages
        $globalIndex = new SiteIndex([$enPage, $nlPage]);

        $context = new SiteContext(
            siteIndex: $enIndex,
            currentPage: $enPage,
            globalIndex: $globalIndex,
        );

        // translation() must resolve the NL page from globalIndex even though siteIndex only has EN
        $this->assertSame($nlPage, $context->translation('nl'));
        $this->assertSame('/nl/about/', $context->languageUrl('nl'));

        $translations = $context->translations();
        $this->assertArrayHasKey('nl', $translations);
    }

    /**
     * Validate localizedPages() is an alias for regularPages() and returns the same language-scoped collection.
     */
    public function testLocalizedPagesIsAliasForRegularPages(): void
    {
        $enPage = $this->makeLocalizedPage('about', '/about/', 'about.dj', 'en', 'about.dj', 'About');
        $enHome = $this->makeLocalizedPage('index', '/', 'index.dj', 'en', 'index.dj', 'Home');
        $nlPage = $this->makeLocalizedPage('nl/about', '/nl/about/', 'about.dj', 'nl', 'about.dj', 'Over ons');

        $enIndex = new SiteIndex([$enPage, $enHome]);
        $globalIndex = new SiteIndex([$enPage, $enHome, $nlPage]);

        $context = new SiteContext(
            siteIndex: $enIndex,
            currentPage: $enHome,
            globalIndex: $globalIndex,
        );

        $this->assertSame($context->regularPages()->all(), $context->localizedPages()->all());
    }

    /**
     * Validate that when globalIndex is null (single-language build), all methods
     * fall back to siteIndex and behave correctly.
     */
    public function testSingleLanguageBuildWithNullGlobalIndexFallsBackToSiteIndex(): void
    {
        $pageA = $this->makePage('blog/a', '/blog/a/', 'blog/a.dj', ['translationKey' => 'a']);
        $pageB = $this->makePage('blog/b', '/blog/b/', 'blog/b.dj', []);
        $index = new SiteIndex([$pageA, $pageB]);

        // $globalIndex omitted → null by default
        $context = new SiteContext(siteIndex: $index, currentPage: $pageA);

        // Navigation works normally
        $this->assertCount(2, $context->regularPages());

        // translation() with no globalIndex falls back to siteIndex → no cross-language page found
        $this->assertNotInstanceOf(ContentPage::class, $context->translation('nl'));

        // languageUrl() returns null when no translation exists
        $this->assertNull($context->languageUrl('nl'));
    }
}
