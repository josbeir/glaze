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
        $this->assertSame([], $config->meta);
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
            'meta' => [
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
        ], $config->meta);
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
            meta: ['robots' => 'index,follow', 'brandColor' => '#7c8cff'],
        );

        $this->assertSame([
            'title' => 'Title',
            'description' => 'Description',
            'baseUrl' => 'https://example.com',
            'basePath' => '/docs',
            'meta' => ['robots' => 'index,follow', 'brandColor' => '#7c8cff'],
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
            'meta' => [
                1 => 'ignored-numeric-key',
                'robots' => 'noindex',
            ],
        ]);

        $this->assertNull($config->title);
        $this->assertNull($config->description);
        $this->assertNull($config->baseUrl);
        $this->assertNull($config->basePath);
        $this->assertSame(['robots' => 'noindex'], $config->meta);
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

    /**
     * Ensure custom site keys preserve nested maps/lists and support dotted access.
     */
    public function testFromProjectConfigPreservesCustomSiteMetadataAndDottedAccess(): void
    {
        $config = SiteConfig::fromProjectConfig([
            'title' => 'Glaze Site',
            'hero' => [
                'title' => 'Build fast static sites',
                'actions' => [
                    ['label' => 'Docs', 'href' => '/installation'],
                ],
            ],
            'flags' => [true, false, 10],
        ]);

        $this->assertSame([
            'hero' => [
                'title' => 'Build fast static sites',
                'actions' => [
                    ['label' => 'Docs', 'href' => '/installation'],
                ],
            ],
            'flags' => [true, false, 10],
        ], $config->meta);

        $this->assertSame('Build fast static sites', $config->siteMeta('hero.title'));
        $this->assertSame('/installation', $config->siteMeta('hero.actions.0.href'));
        $this->assertTrue($config->hasMeta('meta.hero.actions.0.label'));
        $this->assertTrue($config->hasSiteMeta('hero.actions.0.label'));
        $this->assertSame($config->meta, $config->siteMeta(''));
        $this->assertSame('fallback', $config->siteMeta('hero.subtitle', 'fallback'));
    }

    /**
     * Ensure reserved site keys are not duplicated in custom metadata.
     */
    public function testFromProjectConfigMergesMetaMapWithRootSiteMetadata(): void
    {
        $config = SiteConfig::fromProjectConfig([
            'title' => 'Glaze Site',
            'description' => 'Desc',
            'baseUrl' => 'https://example.com',
            'basePath' => '/docs',
            'hero' => ['title' => 'Build fast'],
            'meta' => ['robots' => 'index,follow'],
        ]);

        $this->assertSame([
            'hero' => ['title' => 'Build fast'],
            'robots' => 'index,follow',
        ], $config->meta);
        $this->assertSame('Build fast', $config->meta('hero.title'));
        $this->assertSame('index,follow', $config->meta('robots'));
    }

    /**
     * Ensure empty-path metadata checks reflect whether any metadata exists.
     */
    public function testHasSiteMetaForEmptyPathReflectsMetadataPresence(): void
    {
        $empty = SiteConfig::fromProjectConfig(['title' => 'Only title']);
        $withMeta = SiteConfig::fromProjectConfig(['hero' => ['title' => 'Build fast']]);

        $this->assertFalse($empty->hasSiteMeta(''));
        $this->assertTrue($withMeta->hasSiteMeta(''));
    }

    /**
     * Ensure empty-string root keys are ignored when extracting custom metadata.
     */
    public function testFromProjectConfigIgnoresEmptyStringRootMetadataKey(): void
    {
        $config = SiteConfig::fromProjectConfig([
            1 => 'ignore-numeric-key',
            '' => 'ignore-me',
            'hero' => ['title' => 'Build fast'],
        ]);

        $this->assertSame(['hero' => ['title' => 'Build fast']], $config->meta);
    }
}
