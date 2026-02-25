<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Config;

use Glaze\Config\SiteConfig;
use PHPUnit\Framework\TestCase;

/**
 * Tests for site configuration normalization.
 */
final class SiteConfigTest extends TestCase
{
    /**
     * Ensure non-array project config resolves to empty defaults.
     */
    public function testFromProjectConfigFallsBackToDefaults(): void
    {
        $config = SiteConfig::fromProjectConfig(true);

        $this->assertNull($config->title);
        $this->assertNull($config->description);
        $this->assertNull($config->baseUrl);
        $this->assertNull($config->basePath);
        $this->assertSame([], $config->metaDefaults);
    }

    /**
     * Ensure scalar fields and meta map are normalized from config input.
     */
    public function testFromProjectConfigNormalizesFieldsAndMetaDefaults(): void
    {
        $config = SiteConfig::fromProjectConfig([
            'title' => '  Glaze Site  ',
            'description' => '  Site description  ',
            'baseUrl' => ' https://example.com ',
            'basePath' => ' /docs/ ',
            'metaDefaults' => [
                'robots' => ' index,follow ',
                '' => 'ignored',
                'viewport' => 'width=device-width, initial-scale=1',
                'complex' => ['invalid'],
            ],
        ]);

        $this->assertSame('Glaze Site', $config->title);
        $this->assertSame('Site description', $config->description);
        $this->assertSame('https://example.com', $config->baseUrl);
        $this->assertSame('/docs', $config->basePath);
        $this->assertSame([
            'robots' => 'index,follow',
            'viewport' => 'width=device-width, initial-scale=1',
        ], $config->metaDefaults);
    }

    /**
     * Ensure conversion to template-friendly arrays includes all site keys.
     */
    public function testToArrayReturnsSiteFields(): void
    {
        $config = new SiteConfig(
            title: 'Title',
            description: 'Description',
            baseUrl: 'https://example.com',
            basePath: '/docs',
            metaDefaults: ['robots' => 'index,follow'],
        );

        $this->assertSame([
            'title' => 'Title',
            'description' => 'Description',
            'baseUrl' => 'https://example.com',
            'basePath' => '/docs',
            'metaDefaults' => ['robots' => 'index,follow'],
        ], $config->toArray());
    }

    /**
     * Ensure invalid site scalar fields and numeric meta keys are ignored.
     */
    public function testFromProjectConfigIgnoresInvalidScalarAndMetaKeyValues(): void
    {
        $config = SiteConfig::fromProjectConfig([
            'title' => 123,
            'description' => null,
            'baseUrl' => false,
            'basePath' => true,
            'metaDefaults' => [
                1 => 'ignored-numeric-key',
                'robots' => 'noindex',
            ],
        ]);

        $this->assertNull($config->title);
        $this->assertNull($config->description);
        $this->assertNull($config->baseUrl);
        $this->assertNull($config->basePath);
        $this->assertSame(['robots' => 'noindex'], $config->metaDefaults);
    }

    /**
     * Ensure basePath normalization handles root-only and relative input values.
     */
    public function testFromProjectConfigNormalizesBasePathVariants(): void
    {
        $root = SiteConfig::fromProjectConfig(['basePath' => '/']);
        $relative = SiteConfig::fromProjectConfig(['basePath' => 'docs']);
        $blank = SiteConfig::fromProjectConfig(['basePath' => '   ']);

        $this->assertNull($root->basePath);
        $this->assertSame('/docs', $relative->basePath);
        $this->assertNull($blank->basePath);
    }
}
