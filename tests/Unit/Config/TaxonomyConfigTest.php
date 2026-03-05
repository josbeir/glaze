<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Config;

use Glaze\Config\TaxonomyConfig;
use PHPUnit\Framework\TestCase;

/**
 * Tests for taxonomy dimension configuration value object.
 */
final class TaxonomyConfigTest extends TestCase
{
    /**
     * Ensure default values are set correctly for a minimal config.
     */
    public function testDefaultValues(): void
    {
        $config = new TaxonomyConfig('tags');

        $this->assertSame('tags', $config->name);
        $this->assertFalse($config->generatePages);
        $this->assertSame('', $config->basePath);
        $this->assertSame('', $config->termTemplate);
        $this->assertSame('', $config->listTemplate);
    }

    /**
     * Ensure resolvedBasePath falls back to /{name} when basePath is empty.
     */
    public function testResolvedBasePathDerivesFromNameByDefault(): void
    {
        $this->assertSame('/tags', (new TaxonomyConfig('tags'))->resolvedBasePath());
        $this->assertSame('/categories', (new TaxonomyConfig('categories'))->resolvedBasePath());
    }

    /**
     * Ensure resolvedBasePath uses the explicit basePath when set.
     */
    public function testResolvedBasePathUsesExplicitValue(): void
    {
        $config = new TaxonomyConfig('tags', basePath: '/blog/tags');
        $this->assertSame('/blog/tags', $config->resolvedBasePath());
    }

    /**
     * Ensure resolvedBasePath normalises leading and trailing slashes.
     */
    public function testResolvedBasePathNormalisesSlashes(): void
    {
        $config = new TaxonomyConfig('tags', basePath: '//topics//');
        $this->assertSame('/topics', $config->resolvedBasePath());
    }

    /**
     * Ensure resolvedTermTemplate falls back to taxonomy/term when empty.
     */
    public function testResolvedTermTemplateDefaultsFallback(): void
    {
        $config = new TaxonomyConfig('tags');
        $this->assertSame('taxonomy/term', $config->resolvedTermTemplate());
    }

    /**
     * Ensure resolvedTermTemplate returns the explicit template when configured.
     */
    public function testResolvedTermTemplateUsesExplicitValue(): void
    {
        $config = new TaxonomyConfig('tags', termTemplate: 'taxonomy/tags');
        $this->assertSame('taxonomy/tags', $config->resolvedTermTemplate());
    }

    /**
     * Ensure resolvedTermTemplate trims whitespace from configured value.
     */
    public function testResolvedTermTemplateTrimmed(): void
    {
        $config = new TaxonomyConfig('tags', termTemplate: '  taxonomy/tags  ');
        $this->assertSame('taxonomy/tags', $config->resolvedTermTemplate());
    }

    /**
     * Ensure resolvedListTemplate falls back to taxonomy/list when empty.
     */
    public function testResolvedListTemplateDefaultsFallback(): void
    {
        $config = new TaxonomyConfig('tags');
        $this->assertSame('taxonomy/list', $config->resolvedListTemplate());
    }

    /**
     * Ensure resolvedListTemplate returns the explicit template when configured.
     */
    public function testResolvedListTemplateUsesExplicitValue(): void
    {
        $config = new TaxonomyConfig('tags', listTemplate: 'taxonomy/tags-list');
        $this->assertSame('taxonomy/tags-list', $config->resolvedListTemplate());
    }

    /**
     * Ensure all constructor parameters are applied correctly via named args.
     */
    public function testFullyConfiguredInstance(): void
    {
        $config = new TaxonomyConfig(
            name: 'categories',
            generatePages: true,
            basePath: '/cat',
            termTemplate: 'taxonomy/cat-term',
            listTemplate: 'taxonomy/cat-list',
        );

        $this->assertSame('categories', $config->name);
        $this->assertTrue($config->generatePages);
        $this->assertSame('/cat', $config->resolvedBasePath());
        $this->assertSame('taxonomy/cat-term', $config->resolvedTermTemplate());
        $this->assertSame('taxonomy/cat-list', $config->resolvedListTemplate());
    }
}
