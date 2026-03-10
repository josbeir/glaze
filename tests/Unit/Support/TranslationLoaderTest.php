<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Support;

use Glaze\Support\TranslationLoader;
use Glaze\Tests\Helper\FilesystemTestTrait;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for the TranslationLoader NEON-based translation system.
 */
final class TranslationLoaderTest extends TestCase
{
    use FilesystemTestTrait;

    // -------------------------------------------------------------------------
    // Basic translation
    // -------------------------------------------------------------------------

    /**
     * Ensure translate() returns a simple string for a known key.
     */
    public function testTranslateReturnsSimpleString(): void
    {
        $dir = $this->createTempDirectory();
        file_put_contents($dir . '/nl.neon', "read_more: Lees meer\n");

        $loader = new TranslationLoader($dir, 'en');

        $this->assertSame('Lees meer', $loader->translate('nl', 'read_more'));
    }

    /**
     * Ensure translate() resolves dotted nested keys.
     */
    public function testTranslateResolvesDottedKeys(): void
    {
        $dir = $this->createTempDirectory();
        file_put_contents($dir . '/en.neon', "nav:\n  home: Home\n  about: About\n");

        $loader = new TranslationLoader($dir, 'en');

        $this->assertSame('Home', $loader->translate('en', 'nav.home'));
        $this->assertSame('About', $loader->translate('en', 'nav.about'));
    }

    /**
     * Ensure translate() substitutes {key} placeholders with params.
     */
    public function testTranslateSubstitutesPlaceholders(): void
    {
        $dir = $this->createTempDirectory();
        file_put_contents($dir . '/nl.neon', '"posted_on: \\"Geplaatst op {date}\\"\\n"');
        file_put_contents($dir . '/nl.neon', "posted_on: \"Geplaatst op {date}\"\n");

        $loader = new TranslationLoader($dir, 'en');

        $result = $loader->translate('nl', 'posted_on', ['date' => '2024-01']);
        $this->assertSame('Geplaatst op 2024-01', $result);
    }

    /**
     * Ensure translate() substitutes multiple placeholders.
     */
    public function testTranslateSubstitutesMultiplePlaceholders(): void
    {
        $dir = $this->createTempDirectory();
        file_put_contents($dir . '/en.neon', "greeting: \"Hello {first} {last}!\"\n");

        $loader = new TranslationLoader($dir, 'en');

        $result = $loader->translate('en', 'greeting', ['first' => 'Jane', 'last' => 'Doe']);
        $this->assertSame('Hello Jane Doe!', $result);
    }

    // -------------------------------------------------------------------------
    // Fallback chain
    // -------------------------------------------------------------------------

    /**
     * Ensure translate() falls back to the default language when key is not in requested language.
     */
    public function testTranslateFallsBackToDefaultLanguage(): void
    {
        $dir = $this->createTempDirectory();
        file_put_contents($dir . '/en.neon', "read_more: Read more\n");
        file_put_contents($dir . '/nl.neon', "other_key: Andere sleutel\n");

        $loader = new TranslationLoader($dir, 'en');

        $this->assertSame('Read more', $loader->translate('nl', 'read_more'));
    }

    /**
     * Ensure translate() does not double-query the default language when it is the requested language.
     */
    public function testTranslateDoesNotFallBackWhenRequestedIsDefault(): void
    {
        $dir = $this->createTempDirectory();
        file_put_contents($dir . '/en.neon', "existing_key: Existing\n");

        $loader = new TranslationLoader($dir, 'en');

        // Missing key in default language → returns the key itself
        $result = $loader->translate('en', 'missing_key');
        $this->assertSame('missing_key', $result);
    }

    /**
     * Ensure translate() returns the key when not found in any language file.
     */
    public function testTranslateReturnsKeyWhenNotFoundAnywhere(): void
    {
        $dir = $this->createTempDirectory();
        file_put_contents($dir . '/en.neon', "other: Other\n");
        file_put_contents($dir . '/nl.neon', "other: Ander\n");

        $loader = new TranslationLoader($dir, 'en');

        $this->assertSame('missing.key', $loader->translate('nl', 'missing.key'));
    }

    /**
     * Ensure translate() returns the explicit $fallback string when provided and key is missing.
     */
    public function testTranslateReturnsExplicitFallbackString(): void
    {
        $dir = $this->createTempDirectory();
        file_put_contents($dir . '/en.neon', "other: Other\n");

        $loader = new TranslationLoader($dir, 'en');

        $result = $loader->translate('en', 'missing_key', [], 'Custom fallback');
        $this->assertSame('Custom fallback', $result);
    }

    // -------------------------------------------------------------------------
    // has()
    // -------------------------------------------------------------------------

    /**
     * Ensure has() returns true for an existing key.
     */
    public function testHasReturnsTrueForExistingKey(): void
    {
        $dir = $this->createTempDirectory();
        file_put_contents($dir . '/en.neon', "read_more: Read more\n");

        $loader = new TranslationLoader($dir, 'en');

        $this->assertTrue($loader->has('en', 'read_more'));
    }

    /**
     * Ensure has() returns false for a missing key.
     */
    public function testHasReturnsFalseForMissingKey(): void
    {
        $dir = $this->createTempDirectory();
        file_put_contents($dir . '/en.neon', "read_more: Read more\n");

        $loader = new TranslationLoader($dir, 'en');

        $this->assertFalse($loader->has('en', 'missing_key'));
    }

    /**
     * Ensure has() returns false for a missing language file.
     */
    public function testHasReturnsFalseForMissingLanguageFile(): void
    {
        $loader = new TranslationLoader($this->createTempDirectory(), 'en');

        $this->assertFalse($loader->has('de', 'some.key'));
    }

    // -------------------------------------------------------------------------
    // Missing/unreadable file handling
    // -------------------------------------------------------------------------

    /**
     * Ensure translate() returns the key when the translation file does not exist.
     */
    public function testTranslateReturnsKeyForMissingFile(): void
    {
        $loader = new TranslationLoader($this->createTempDirectory(), 'de');

        $this->assertSame('some.key', $loader->translate('fr', 'some.key'));
    }

    /**
     * Ensure translate() throws RuntimeException for invalid NEON syntax.
     */
    public function testTranslateThrowsForInvalidNeon(): void
    {
        $dir = $this->createTempDirectory();
        file_put_contents($dir . '/bad.neon', "invalid: neon: syntax: [[\n");

        $loader = new TranslationLoader($dir, 'en');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Failed to parse translation file');

        $loader->translate('bad', 'any.key');
    }

    // -------------------------------------------------------------------------
    // Memoization
    // -------------------------------------------------------------------------

    /**
     * Ensure the translation file is loaded only once (memoized).
     */
    public function testTranslationsAreMemoized(): void
    {
        $dir = $this->createTempDirectory();
        file_put_contents($dir . '/en.neon', "key: Value\n");

        $loader = new TranslationLoader($dir, 'en');

        // First call loads the file
        $first = $loader->translate('en', 'key');

        // Overwrite the file — a second call should still return the memoized value
        file_put_contents($dir . '/en.neon', "key: Changed\n");
        $second = $loader->translate('en', 'key');

        $this->assertSame('Value', $first);
        $this->assertSame('Value', $second);
    }

    // -------------------------------------------------------------------------
    // Empty defaultLanguage
    // -------------------------------------------------------------------------

    /**
     * Ensure translate() works correctly when no default language is configured.
     */
    public function testTranslateWorksWithoutDefaultLanguage(): void
    {
        $dir = $this->createTempDirectory();
        file_put_contents($dir . '/nl.neon', "read_more: Lees meer\n");

        $loader = new TranslationLoader($dir);

        $this->assertSame('Lees meer', $loader->translate('nl', 'read_more'));
        $this->assertSame('missing', $loader->translate('nl', 'missing'));
    }
}
