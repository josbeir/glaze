<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Content;

use Glaze\Config\BuildConfig;
use Glaze\Config\I18nConfig;
use Glaze\Content\ContentDiscoveryService;
use Glaze\Content\LocalizedContentDiscovery;
use Glaze\Tests\Helper\ContainerTestTrait;
use Glaze\Tests\Helper\FilesystemTestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Tests for LocalizedContentDiscovery multi-language content orchestration.
 */
final class LocalizedContentDiscoveryTest extends TestCase
{
    use ContainerTestTrait;
    use FilesystemTestTrait;

    // -------------------------------------------------------------------------
    // Passthrough (i18n disabled)
    // -------------------------------------------------------------------------

    /**
     * Ensure discover() passes through to ContentDiscoveryService when i18n is disabled.
     */
    public function testDiscoverPassesThroughWhenI18nDisabled(): void
    {
        $projectRoot = $this->createTempDirectory();
        $contentPath = $projectRoot . '/content';
        mkdir($contentPath, 0755, true);
        file_put_contents($contentPath . '/index.dj', "# Home\n");
        file_put_contents($contentPath . '/about.dj', "# About\n");

        $config = new BuildConfig(projectRoot: $projectRoot);
        $discovery = new LocalizedContentDiscovery($this->createService());

        $pages = $discovery->discover($config);

        $this->assertCount(2, $pages);
        // Pages should have empty language (passthrough)
        foreach ($pages as $page) {
            $this->assertSame('', $page->language);
        }
    }

    /**
     * Ensure discover() returns an empty array when content directory is missing and i18n is disabled.
     */
    public function testDiscoverReturnsEmptyArrayForMissingDirectoryWhenDisabled(): void
    {
        $config = new BuildConfig(projectRoot: $this->createTempDirectory() . '/missing');
        $discovery = new LocalizedContentDiscovery($this->createService());

        $pages = $discovery->discover($config);

        $this->assertSame([], $pages);
    }

    // -------------------------------------------------------------------------
    // Multi-language discovery
    // -------------------------------------------------------------------------

    /**
     * Ensure discover() applies language tags and prefixes when i18n is enabled.
     */
    public function testDiscoverAppliesLanguageTagsWhenEnabled(): void
    {
        $projectRoot = $this->createTempDirectory();

        // Default language content at project root content path
        $enContent = $projectRoot . '/content';
        mkdir($enContent, 0755, true);
        file_put_contents($enContent . '/about.dj', "# About\n");

        // Dutch content in its own directory
        $nlContent = $projectRoot . '/content/nl';
        mkdir($nlContent, 0755, true);
        file_put_contents($nlContent . '/about.dj', "# Over ons\n");

        $i18n = I18nConfig::fromProjectConfig([
            'defaultLanguage' => 'en',
            'languages' => [
                'en' => ['label' => 'English', 'urlPrefix' => ''],
                'nl' => ['label' => 'Nederlands', 'urlPrefix' => 'nl', 'contentDir' => 'content/nl'],
            ],
        ]);

        $config = new BuildConfig(projectRoot: $projectRoot, i18n: $i18n);
        $discovery = new LocalizedContentDiscovery($this->createService());

        $pages = $discovery->discover($config);

        $languages = array_map(static fn($page): string => $page->language, $pages);
        $this->assertContains('en', $languages);
        $this->assertContains('nl', $languages);
    }

    /**
     * Ensure discover() prefixes urlPath for languages with a urlPrefix.
     */
    public function testDiscoverPrefixesUrlPathForNonDefaultLanguage(): void
    {
        $projectRoot = $this->createTempDirectory();

        $enContent = $projectRoot . '/content';
        mkdir($enContent, 0755, true);
        file_put_contents($enContent . '/about.dj', "# About\n");

        $nlContent = $projectRoot . '/content/nl';
        mkdir($nlContent, 0755, true);
        file_put_contents($nlContent . '/about.dj', "# Over ons\n");

        $i18n = I18nConfig::fromProjectConfig([
            'defaultLanguage' => 'en',
            'languages' => [
                'en' => ['label' => 'English', 'urlPrefix' => ''],
                'nl' => ['label' => 'Nederlands', 'urlPrefix' => 'nl', 'contentDir' => 'content/nl'],
            ],
        ]);

        $config = new BuildConfig(projectRoot: $projectRoot, i18n: $i18n);
        $discovery = new LocalizedContentDiscovery($this->createService());

        $pages = $discovery->discover($config);

        $nlPages = array_filter($pages, static fn($page): bool => $page->language === 'nl');
        foreach ($nlPages as $page) {
            $this->assertStringStartsWith('/nl/', $page->urlPath);
        }
    }

    /**
     * Ensure discover() skips languages without contentDir that are not the default language.
     */
    public function testDiscoverSkipsNonDefaultLanguageWithoutContentDir(): void
    {
        $projectRoot = $this->createTempDirectory();
        $enContent = $projectRoot . '/content';
        mkdir($enContent, 0755, true);
        file_put_contents($enContent . '/index.dj', "# Home\n");

        $i18n = I18nConfig::fromProjectConfig([
            'defaultLanguage' => 'en',
            'languages' => [
                'en' => ['label' => 'English', 'urlPrefix' => ''],
                // fr has no contentDir and is not the default
                'fr' => ['label' => 'Français', 'urlPrefix' => 'fr'],
            ],
        ]);

        $config = new BuildConfig(projectRoot: $projectRoot, i18n: $i18n);
        $discovery = new LocalizedContentDiscovery($this->createService());

        $pages = $discovery->discover($config);

        $frPages = array_filter($pages, static fn($page): bool => $page->language === 'fr');
        $this->assertCount(0, $frPages);

        $enPages = array_filter($pages, static fn($page): bool => $page->language === 'en');
        $this->assertCount(1, $enPages);
    }

    /**
     * Ensure discover() derives translationKey from relativePath when not set in frontmatter.
     */
    public function testDiscoverDerivesTranslationKeyFromRelativePath(): void
    {
        $projectRoot = $this->createTempDirectory();
        $enContent = $projectRoot . '/content';
        mkdir($enContent, 0755, true);
        file_put_contents($enContent . '/about.dj', "# About\n");

        $i18n = I18nConfig::fromProjectConfig([
            'defaultLanguage' => 'en',
            'languages' => [
                'en' => ['label' => 'English', 'urlPrefix' => ''],
            ],
        ]);

        $config = new BuildConfig(projectRoot: $projectRoot, i18n: $i18n);
        $discovery = new LocalizedContentDiscovery($this->createService());

        $pages = $discovery->discover($config);

        $this->assertCount(1, $pages);
        $this->assertSame('about.dj', $pages[0]->translationKey);
    }

    /**
     * Ensure discover() reads an explicit translationKey from frontmatter.
     */
    public function testDiscoverReadsFrontmatterTranslationKey(): void
    {
        $projectRoot = $this->createTempDirectory();
        $enContent = $projectRoot . '/content';
        mkdir($enContent, 0755, true);
        file_put_contents($enContent . '/about.dj', "+++\ntranslationKey: about-page\n+++\n# About\n");

        $i18n = I18nConfig::fromProjectConfig([
            'defaultLanguage' => 'en',
            'languages' => [
                'en' => ['urlPrefix' => ''],
            ],
        ]);

        $config = new BuildConfig(projectRoot: $projectRoot, i18n: $i18n);
        $discovery = new LocalizedContentDiscovery($this->createService());

        $pages = $discovery->discover($config);

        $this->assertCount(1, $pages);
        $this->assertSame('about-page', $pages[0]->translationKey);
    }

    /**
     * Ensure discover() sorts pages stably by language and relativePath.
     */
    public function testDiscoverSortsPagesStably(): void
    {
        $projectRoot = $this->createTempDirectory();

        $enContent = $projectRoot . '/content';
        mkdir($enContent . '/blog', 0755, true);
        file_put_contents($enContent . '/about.dj', "# About\n");
        file_put_contents($enContent . '/blog/post.dj', "# Post\n");

        $nlContent = $projectRoot . '/content/nl';
        mkdir($nlContent . '/blog', 0755, true);
        file_put_contents($nlContent . '/about.dj', "# Over ons\n");
        file_put_contents($nlContent . '/blog/post.dj', "# Artikel\n");

        $i18n = I18nConfig::fromProjectConfig([
            'defaultLanguage' => 'en',
            'languages' => [
                'en' => ['urlPrefix' => ''],
                'nl' => ['urlPrefix' => 'nl', 'contentDir' => 'content/nl'],
            ],
        ]);

        $config = new BuildConfig(projectRoot: $projectRoot, i18n: $i18n);
        $discovery = new LocalizedContentDiscovery($this->createService());

        $pages = $discovery->discover($config);

        // All en pages should come before nl pages (alphabetically by language+relativePath)
        $languages = array_map(static fn($page): string => $page->language, $pages);
        $enIndex = array_search('en', $languages, true);
        $nlIndex = array_search('nl', $languages, true);

        $this->assertIsInt($enIndex);
        $this->assertIsInt($nlIndex);
        $this->assertLessThan($nlIndex, $enIndex);
    }

    /**
     * Ensure discover() uses default language fallback for content path when contentDir is null.
     */
    public function testDiscoverUsesProjectContentPathForDefaultLanguage(): void
    {
        $projectRoot = $this->createTempDirectory();
        $enContent = $projectRoot . '/content';
        mkdir($enContent, 0755, true);
        file_put_contents($enContent . '/index.dj', "# Home\n");

        // Default language has no contentDir — should fall back to project content path
        $i18n = I18nConfig::fromProjectConfig([
            'defaultLanguage' => 'en',
            'languages' => [
                'en' => ['urlPrefix' => ''],
            ],
        ]);

        $config = new BuildConfig(projectRoot: $projectRoot, i18n: $i18n);
        $discovery = new LocalizedContentDiscovery($this->createService());

        $pages = $discovery->discover($config);

        $this->assertCount(1, $pages);
        $this->assertSame('en', $pages[0]->language);
        $this->assertSame('index', $pages[0]->slug);
    }

    /**
     * Create a ContentDiscoveryService via the DI container.
     */
    protected function createService(): ContentDiscoveryService
    {
        /** @var ContentDiscoveryService */
        return $this->service(ContentDiscoveryService::class);
    }
}
