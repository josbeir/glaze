<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Config;

use Glaze\Config\DjotOptions;
use PHPUnit\Framework\TestCase;

/**
 * Tests for DjotOptions construction and defaults.
 */
final class DjotOptionsTest extends TestCase
{
    /**
     * Ensure a default-constructed DjotOptions carries all expected defaults.
     */
    public function testDefaultConstructorCarriesExpectedDefaults(): void
    {
        $options = new DjotOptions();

        $this->assertTrue($options->codeHighlightingEnabled);
        $this->assertSame('nord', $options->codeHighlightingTheme);
        $this->assertSame([], $options->codeHighlightingThemes);
        $this->assertFalse($options->codeHighlightingWithGutter);
        $this->assertFalse($options->headerAnchorsEnabled);
        $this->assertSame('#', $options->headerAnchorsSymbol);
        $this->assertSame('after', $options->headerAnchorsPosition);
        $this->assertSame('permalink-wrapper', $options->headerAnchorsCssClass);
        $this->assertSame('Anchor link', $options->headerAnchorsAriaLabel);
        $this->assertSame([1, 2, 3, 4, 5, 6], $options->headerAnchorsLevels);
        $this->assertFalse($options->autolinkEnabled);
        $this->assertSame(['https', 'http', 'mailto'], $options->autolinkAllowedSchemes);
        $this->assertFalse($options->externalLinksEnabled);
        $this->assertSame([], $options->externalLinksInternalHosts);
        $this->assertSame('_blank', $options->externalLinksTarget);
        $this->assertSame('noopener noreferrer', $options->externalLinksRel);
        $this->assertFalse($options->externalLinksNofollow);
        $this->assertFalse($options->smartQuotesEnabled);
        $this->assertNull($options->smartQuotesLocale);
        $this->assertNull($options->smartQuotesOpenDouble);
        $this->assertNull($options->smartQuotesCloseDouble);
        $this->assertNull($options->smartQuotesOpenSingle);
        $this->assertNull($options->smartQuotesCloseSingle);
        $this->assertFalse($options->mentionsEnabled);
        $this->assertSame('/users/view/{username}', $options->mentionsUrlTemplate);
        $this->assertSame('mention', $options->mentionsCssClass);
        $this->assertFalse($options->semanticSpanEnabled);
        $this->assertFalse($options->defaultAttributesEnabled);
        $this->assertSame([], $options->defaultAttributesDefaults);
    }

    /**
     * Ensure an empty config map resolves to all defaults.
     */
    public function testFromProjectConfigWithEmptyMapUsesDefaults(): void
    {
        $options = DjotOptions::fromProjectConfig([]);

        $this->assertTrue($options->codeHighlightingEnabled);
        $this->assertSame('nord', $options->codeHighlightingTheme);
        $this->assertSame([], $options->codeHighlightingThemes);
        $this->assertFalse($options->headerAnchorsEnabled);
        $this->assertFalse($options->autolinkEnabled);
        $this->assertFalse($options->externalLinksEnabled);
        $this->assertFalse($options->smartQuotesEnabled);
        $this->assertFalse($options->mentionsEnabled);
        $this->assertFalse($options->semanticSpanEnabled);
        $this->assertFalse($options->defaultAttributesEnabled);
    }

    /**
     * Ensure code highlighting settings are parsed from config.
     */
    public function testCodeHighlightingIsConfigured(): void
    {
        $options = DjotOptions::fromProjectConfig([
            'codeHighlighting' => [
                'enabled' => false,
                'theme' => 'GITHUB-DARK',
                'themes' => [
                    'Dark' => 'GITHUB-DARK',
                    'Light' => 'GITHUB-LIGHT',
                ],
                'withGutter' => true,
            ],
        ]);

        $this->assertFalse($options->codeHighlightingEnabled);
        $this->assertSame('github-dark', $options->codeHighlightingTheme);
        $this->assertSame(['dark' => 'github-dark', 'light' => 'github-light'], $options->codeHighlightingThemes);
        $this->assertTrue($options->codeHighlightingWithGutter);
    }

    /**
     * Ensure invalid multi-theme map entries are filtered safely.
     */
    public function testInvalidCodeHighlightingThemesAreIgnored(): void
    {
        $options = DjotOptions::fromProjectConfig([
            'codeHighlighting' => [
                'themes' => [
                    'dark' => 'github-dark',
                    '' => 'github-light',
                    'light' => '',
                    1 => 'nord',
                    'custom' => 123,
                ],
            ],
        ]);

        $this->assertSame(['dark' => 'github-dark'], $options->codeHighlightingThemes);
    }

    /**
     * Ensure non-bool enabled values fall back to defaults.
     */
    public function testNonBoolEnabledFallsBackToDefault(): void
    {
        $options = DjotOptions::fromProjectConfig([
            'codeHighlighting' => ['enabled' => 'yes'],
        ]);

        $this->assertTrue($options->codeHighlightingEnabled);
    }

    /**
     * Ensure empty theme string falls back to the default theme.
     */
    public function testEmptyThemeFallsBackToDefault(): void
    {
        $options = DjotOptions::fromProjectConfig([
            'codeHighlighting' => ['theme' => ''],
        ]);

        $this->assertSame('nord', $options->codeHighlightingTheme);
    }

    /**
     * Ensure header anchor settings are parsed including semantic position validation.
     */
    public function testHeaderAnchorsAreConfigured(): void
    {
        $options = DjotOptions::fromProjectConfig([
            'headerAnchors' => [
                'enabled' => true,
                'symbol' => '¶',
                'position' => 'before',
                'cssClass' => 'docs-anchor',
                'ariaLabel' => 'Link',
                'levels' => [2, 3, 4],
            ],
        ]);

        $this->assertTrue($options->headerAnchorsEnabled);
        $this->assertSame('¶', $options->headerAnchorsSymbol);
        $this->assertSame('before', $options->headerAnchorsPosition);
        $this->assertSame('docs-anchor', $options->headerAnchorsCssClass);
        $this->assertSame('Link', $options->headerAnchorsAriaLabel);
        $this->assertSame([2, 3, 4], $options->headerAnchorsLevels);
    }

    /**
     * Ensure invalid position value falls back to the default.
     */
    public function testInvalidHeaderAnchorPositionFallsBackToDefault(): void
    {
        $options = DjotOptions::fromProjectConfig([
            'headerAnchors' => ['position' => 'center'],
        ]);

        $this->assertSame('after', $options->headerAnchorsPosition);
    }

    /**
     * Ensure out-of-range heading levels are filtered (valid range is 1-6).
     */
    public function testHeaderAnchorLevelsOutOfRangeAreFiltered(): void
    {
        $options = DjotOptions::fromProjectConfig([
            'headerAnchors' => ['levels' => [0, 2, 7, 3, 6]],
        ]);

        $this->assertSame([2, 3, 6], $options->headerAnchorsLevels);
    }

    /**
     * Ensure an empty levels array falls back to all heading levels.
     */
    public function testEmptyHeaderAnchorLevelsFallBackToAllLevels(): void
    {
        $options = DjotOptions::fromProjectConfig([
            'headerAnchors' => ['levels' => []],
        ]);

        $this->assertSame([1, 2, 3, 4, 5, 6], $options->headerAnchorsLevels);
    }

    /**
     * Ensure autolink settings are parsed with lowercase scheme normalisation.
     */
    public function testAutolinkIsConfigured(): void
    {
        $options = DjotOptions::fromProjectConfig([
            'autolink' => [
                'enabled' => true,
                'allowedSchemes' => ['HTTPS', 'FTP'],
            ],
        ]);

        $this->assertTrue($options->autolinkEnabled);
        $this->assertSame(['https', 'ftp'], $options->autolinkAllowedSchemes);
    }

    /**
     * Ensure empty scheme list falls back to the default allowed schemes.
     */
    public function testEmptySchemeListFallsBackToDefault(): void
    {
        $options = DjotOptions::fromProjectConfig([
            'autolink' => ['allowedSchemes' => []],
        ]);

        $this->assertSame(['https', 'http', 'mailto'], $options->autolinkAllowedSchemes);
    }

    /**
     * Ensure external link settings are parsed correctly.
     */
    public function testExternalLinksAreConfigured(): void
    {
        $options = DjotOptions::fromProjectConfig([
            'externalLinks' => [
                'enabled' => true,
                'internalHosts' => ['EXAMPLE.COM', 'cdn.example.com'],
                'target' => '_self',
                'rel' => 'noopener',
                'nofollow' => true,
            ],
        ]);

        $this->assertTrue($options->externalLinksEnabled);
        $this->assertSame(['example.com', 'cdn.example.com'], $options->externalLinksInternalHosts);
        $this->assertSame('_self', $options->externalLinksTarget);
        $this->assertSame('noopener', $options->externalLinksRel);
        $this->assertTrue($options->externalLinksNofollow);
    }

    /**
     * Ensure smart quotes settings are parsed, preserving null for absent quote values.
     */
    public function testSmartQuotesAreConfigured(): void
    {
        $options = DjotOptions::fromProjectConfig([
            'smartQuotes' => [
                'enabled' => true,
                'locale' => 'de',
                'openDouble' => "\u{201E}",
                'closeDouble' => "\u{201C}",
            ],
        ]);

        $this->assertTrue($options->smartQuotesEnabled);
        $this->assertSame('de', $options->smartQuotesLocale);
        $this->assertSame("\u{201E}", $options->smartQuotesOpenDouble);
        $this->assertSame("\u{201C}", $options->smartQuotesCloseDouble);
        $this->assertNull($options->smartQuotesOpenSingle);
        $this->assertNull($options->smartQuotesCloseSingle);
    }

    /**
     * Ensure non-array smart quotes section falls back to all defaults.
     */
    public function testNonArraySmartQuotesSectionFallsBackToDefaults(): void
    {
        $options = DjotOptions::fromProjectConfig([
            'smartQuotes' => true,
        ]);

        $this->assertFalse($options->smartQuotesEnabled);
        $this->assertNull($options->smartQuotesLocale);
    }

    /**
     * Ensure mentions settings are parsed from config.
     */
    public function testMentionsAreConfigured(): void
    {
        $options = DjotOptions::fromProjectConfig([
            'mentions' => [
                'enabled' => true,
                'urlTemplate' => '/profiles/{username}',
                'cssClass' => 'user-mention',
            ],
        ]);

        $this->assertTrue($options->mentionsEnabled);
        $this->assertSame('/profiles/{username}', $options->mentionsUrlTemplate);
        $this->assertSame('user-mention', $options->mentionsCssClass);
    }

    /**
     * Ensure semantic span enabled flag is parsed from config.
     */
    public function testSemanticSpanIsConfigured(): void
    {
        $options = DjotOptions::fromProjectConfig([
            'semanticSpan' => ['enabled' => true],
        ]);

        $this->assertTrue($options->semanticSpanEnabled);
    }

    /**
     * Ensure default attributes are parsed with lower-cased element type keys.
     */
    public function testDefaultAttributesAreConfigured(): void
    {
        $options = DjotOptions::fromProjectConfig([
            'defaultAttributes' => [
                'enabled' => true,
                'defaults' => [
                    'Heading' => ['class' => 'heading'],
                    'p' => ['data-prose' => 'true'],
                ],
            ],
        ]);

        $this->assertTrue($options->defaultAttributesEnabled);
        $this->assertSame([
            'heading' => ['class' => 'heading'],
            'p' => ['data-prose' => 'true'],
        ], $options->defaultAttributesDefaults);
    }

    /**
     * Ensure empty element type keys and empty attribute maps are filtered from defaults.
     */
    public function testDefaultAttributesFiltersEmptyTypesAndMaps(): void
    {
        $options = DjotOptions::fromProjectConfig([
            'defaultAttributes' => [
                'enabled' => true,
                'defaults' => [
                    '' => ['class' => 'ignored'],
                    'p' => [],
                    'div' => ['class' => 'wrapper'],
                ],
            ],
        ]);

        $this->assertSame([
            'div' => ['class' => 'wrapper'],
        ], $options->defaultAttributesDefaults);
    }
}
