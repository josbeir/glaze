<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Config;

use Glaze\Config\LanguageConfig;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the LanguageConfig value object.
 */
final class LanguageConfigTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Constructor defaults
    // -------------------------------------------------------------------------

    /**
     * Ensure default constructor values are correct.
     */
    public function testDefaultConstructorValues(): void
    {
        $config = new LanguageConfig('en');

        $this->assertSame('en', $config->code);
        $this->assertSame('', $config->label);
        $this->assertSame('', $config->urlPrefix);
        $this->assertNull($config->contentDir);
    }

    /**
     * Ensure all constructor properties can be set.
     */
    public function testConstructorSetsAllProperties(): void
    {
        $config = new LanguageConfig('nl', 'Nederlands', 'nl', 'content/nl');

        $this->assertSame('nl', $config->code);
        $this->assertSame('Nederlands', $config->label);
        $this->assertSame('nl', $config->urlPrefix);
        $this->assertSame('content/nl', $config->contentDir);
    }

    // -------------------------------------------------------------------------
    // fromConfig()
    // -------------------------------------------------------------------------

    /**
     * Ensure fromConfig() with a non-array value returns a minimal instance with defaults.
     */
    public function testFromConfigWithNonArrayReturnsDefault(): void
    {
        $config = LanguageConfig::fromConfig('en', null);

        $this->assertSame('en', $config->code);
        $this->assertSame('', $config->label);
        $this->assertSame('', $config->urlPrefix);
        $this->assertNull($config->contentDir);
    }

    /**
     * Ensure fromConfig() with an empty array returns a minimal instance with defaults.
     */
    public function testFromConfigWithEmptyArrayReturnsDefault(): void
    {
        $config = LanguageConfig::fromConfig('fr', []);

        $this->assertSame('fr', $config->code);
        $this->assertSame('', $config->label);
        $this->assertSame('', $config->urlPrefix);
        $this->assertNull($config->contentDir);
    }

    /**
     * Ensure fromConfig() parses a full config array correctly.
     */
    public function testFromConfigParsesFullConfig(): void
    {
        $config = LanguageConfig::fromConfig('nl', [
            'label' => 'Nederlands',
            'urlPrefix' => 'nl',
            'contentDir' => 'content/nl',
        ]);

        $this->assertSame('nl', $config->code);
        $this->assertSame('Nederlands', $config->label);
        $this->assertSame('nl', $config->urlPrefix);
        $this->assertSame('content/nl', $config->contentDir);
    }

    /**
     * Ensure fromConfig() strips leading and trailing slashes from urlPrefix.
     */
    public function testFromConfigStripsSlashesFromUrlPrefix(): void
    {
        $config = LanguageConfig::fromConfig('nl', ['urlPrefix' => '/nl/']);

        $this->assertSame('nl', $config->urlPrefix);
    }

    /**
     * Ensure fromConfig() treats a non-string urlPrefix as empty.
     */
    public function testFromConfigTreatsNonStringUrlPrefixAsEmpty(): void
    {
        $config = LanguageConfig::fromConfig('nl', ['urlPrefix' => 42]);

        $this->assertSame('', $config->urlPrefix);
    }

    /**
     * Ensure fromConfig() treats a non-string label as empty.
     */
    public function testFromConfigTreatsNonStringLabelAsEmpty(): void
    {
        $config = LanguageConfig::fromConfig('de', ['label' => false]);

        $this->assertSame('', $config->label);
    }

    /**
     * Ensure fromConfig() treats a non-string contentDir as null.
     */
    public function testFromConfigTreatsNonStringContentDirAsNull(): void
    {
        $config = LanguageConfig::fromConfig('de', ['contentDir' => 99]);

        $this->assertNull($config->contentDir);
    }

    /**
     * Ensure fromConfig() allows an empty urlPrefix for the default/root language.
     */
    public function testFromConfigAllowsEmptyUrlPrefixForRootLanguage(): void
    {
        $config = LanguageConfig::fromConfig('en', ['urlPrefix' => '']);

        $this->assertSame('', $config->urlPrefix);
        $this->assertFalse($config->hasUrlPrefix());
    }

    // -------------------------------------------------------------------------
    // hasUrlPrefix()
    // -------------------------------------------------------------------------

    /**
     * Ensure hasUrlPrefix() returns false for an empty prefix.
     */
    public function testHasUrlPrefixReturnsFalseForEmptyPrefix(): void
    {
        $config = new LanguageConfig('en', 'English', '');

        $this->assertFalse($config->hasUrlPrefix());
    }

    /**
     * Ensure hasUrlPrefix() returns true for a non-empty prefix.
     */
    public function testHasUrlPrefixReturnsTrueForNonEmptyPrefix(): void
    {
        $config = new LanguageConfig('nl', 'Nederlands', 'nl');

        $this->assertTrue($config->hasUrlPrefix());
    }
}
