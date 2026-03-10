<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Config;

use Glaze\Config\I18nConfig;
use Glaze\Config\LanguageConfig;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the I18nConfig value object.
 */
final class I18nConfigTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Constructor defaults
    // -------------------------------------------------------------------------

    /**
     * Ensure null defaultLanguage creates a disabled i18n config.
     */
    public function testNullDefaultLanguageIsDisabled(): void
    {
        $config = new I18nConfig(null, []);

        $this->assertFalse($config->isEnabled());
        $this->assertNull($config->defaultLanguage);
        $this->assertSame([], $config->languages);
        $this->assertSame('i18n', $config->translationsDir);
    }

    /**
     * Ensure non-null defaultLanguage creates an enabled i18n config.
     */
    public function testNonNullDefaultLanguageIsEnabled(): void
    {
        $config = new I18nConfig('en', ['en' => new LanguageConfig('en')]);

        $this->assertTrue($config->isEnabled());
        $this->assertSame('en', $config->defaultLanguage);
    }

    /**
     * Ensure translationsDir defaults to 'i18n'.
     */
    public function testTranslationsDirDefaultsToI18n(): void
    {
        $config = new I18nConfig('en', []);

        $this->assertSame('i18n', $config->translationsDir);
    }

    // -------------------------------------------------------------------------
    // fromProjectConfig()
    // -------------------------------------------------------------------------

    /**
     * Ensure fromProjectConfig() returns a disabled instance when value is null.
     */
    public function testFromProjectConfigWithNullReturnsDisabledInstance(): void
    {
        $config = I18nConfig::fromProjectConfig(null);

        $this->assertFalse($config->isEnabled());
    }

    /**
     * Ensure fromProjectConfig() returns a disabled instance when value is not an array.
     */
    public function testFromProjectConfigWithNonArrayReturnsDisabledInstance(): void
    {
        $config = I18nConfig::fromProjectConfig('en');

        $this->assertFalse($config->isEnabled());
    }

    /**
     * Ensure fromProjectConfig() returns a disabled instance when defaultLanguage is missing.
     */
    public function testFromProjectConfigWithMissingDefaultLanguageReturnsDisabled(): void
    {
        $config = I18nConfig::fromProjectConfig(['languages' => []]);

        $this->assertFalse($config->isEnabled());
    }

    /**
     * Ensure fromProjectConfig() returns a disabled instance when defaultLanguage is null.
     */
    public function testFromProjectConfigWithNullDefaultLanguageReturnsDisabled(): void
    {
        $config = I18nConfig::fromProjectConfig(['defaultLanguage' => null]);

        $this->assertFalse($config->isEnabled());
    }

    /**
     * Ensure fromProjectConfig() parses a minimal enabled config.
     */
    public function testFromProjectConfigParsesMinimalEnabledConfig(): void
    {
        $config = I18nConfig::fromProjectConfig(['defaultLanguage' => 'en']);

        $this->assertTrue($config->isEnabled());
        $this->assertSame('en', $config->defaultLanguage);
        $this->assertArrayHasKey('en', $config->languages);
    }

    /**
     * Ensure fromProjectConfig() parses a full language map.
     */
    public function testFromProjectConfigParsesFullLanguageMap(): void
    {
        $config = I18nConfig::fromProjectConfig([
            'defaultLanguage' => 'en',
            'translationsDir' => 'locales',
            'languages' => [
                'en' => ['label' => 'English', 'urlPrefix' => ''],
                'nl' => ['label' => 'Nederlands', 'urlPrefix' => 'nl', 'contentDir' => 'content/nl'],
            ],
        ]);

        $this->assertTrue($config->isEnabled());
        $this->assertSame('en', $config->defaultLanguage);
        $this->assertSame('locales', $config->translationsDir);
        $this->assertArrayHasKey('en', $config->languages);
        $this->assertArrayHasKey('nl', $config->languages);
        $this->assertSame('English', $config->languages['en']->label);
        $this->assertSame('nl', $config->languages['nl']->urlPrefix);
        $this->assertSame('content/nl', $config->languages['nl']->contentDir);
    }

    /**
     * Ensure fromProjectConfig() auto-creates a default language entry when not in languages map.
     */
    public function testFromProjectConfigAutoCreatesDefaultLanguageEntry(): void
    {
        $config = I18nConfig::fromProjectConfig(['defaultLanguage' => 'en', 'languages' => []]);

        $this->assertArrayHasKey('en', $config->languages);
        $this->assertSame('en', $config->languages['en']->code);
    }

    /**
     * Ensure fromProjectConfig() skips language entries with empty or non-string codes.
     */
    public function testFromProjectConfigSkipsInvalidLanguageCodes(): void
    {
        $config = I18nConfig::fromProjectConfig([
            'defaultLanguage' => 'en',
            'languages' => [
                '' => ['label' => 'Empty'],
                42 => ['label' => 'Numeric'],
                'en' => ['label' => 'English'],
            ],
        ]);

        $this->assertCount(1, $config->languages);
        $this->assertArrayHasKey('en', $config->languages);
        $this->assertArrayNotHasKey('', $config->languages);
    }

    /**
     * Ensure fromProjectConfig() falls back to default translationsDir when none given.
     */
    public function testFromProjectConfigUsesDefaultTranslationsDir(): void
    {
        $config = I18nConfig::fromProjectConfig(['defaultLanguage' => 'en']);

        $this->assertSame('i18n', $config->translationsDir);
    }

    // -------------------------------------------------------------------------
    // language() and defaultLanguageConfig()
    // -------------------------------------------------------------------------

    /**
     * Ensure language() returns the correct LanguageConfig for a known code.
     */
    public function testLanguageReturnsConfigForKnownCode(): void
    {
        $lang = new LanguageConfig('nl', 'Nederlands', 'nl');
        $config = new I18nConfig('en', ['en' => new LanguageConfig('en'), 'nl' => $lang]);

        $this->assertSame($lang, $config->language('nl'));
    }

    /**
     * Ensure language() returns null for an unknown code.
     */
    public function testLanguageReturnsNullForUnknownCode(): void
    {
        $config = new I18nConfig('en', ['en' => new LanguageConfig('en')]);

        $this->assertNotInstanceOf(LanguageConfig::class, $config->language('de'));
    }

    /**
     * Ensure defaultLanguageConfig() returns the default language entry when enabled.
     */
    public function testDefaultLanguageConfigReturnsDefaultEntry(): void
    {
        $en = new LanguageConfig('en');
        $config = new I18nConfig('en', ['en' => $en]);

        $this->assertSame($en, $config->defaultLanguageConfig());
    }

    /**
     * Ensure defaultLanguageConfig() returns null when i18n is disabled.
     */
    public function testDefaultLanguageConfigReturnsNullWhenDisabled(): void
    {
        $config = new I18nConfig(null, []);

        $this->assertNotInstanceOf(LanguageConfig::class, $config->defaultLanguageConfig());
    }
}
