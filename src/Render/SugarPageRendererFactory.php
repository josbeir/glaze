<?php
declare(strict_types=1);

namespace Glaze\Render;

use Glaze\Config\BuildConfig;
use Glaze\Support\ResourcePathRewriter;

/**
 * Creates and caches page-level Sugar renderers.
 */
final class SugarPageRendererFactory
{
    /**
     * Renderer cache keyed by config/template/debug combination.
     *
     * @var array<string, \Glaze\Render\SugarPageRenderer>
     */
    protected array $cache = [];

    /**
     * Constructor.
     *
     * @param \Glaze\Support\ResourcePathRewriter $resourcePathRewriter Shared path rewriter.
     */
    public function __construct(protected ResourcePathRewriter $resourcePathRewriter)
    {
    }

    /**
     * Create a renderer instance.
     *
     * @param \Glaze\Config\BuildConfig $config Build configuration.
     * @param string $template Sugar template name.
     * @param bool $debug Whether to enable debug freshness checks.
     */
    public function create(BuildConfig $config, string $template, bool $debug = false): SugarPageRenderer
    {
        return new SugarPageRenderer(
            templatePath: $config->templatePath(),
            cachePath: $config->templateCachePath(),
            template: $template,
            siteConfig: $config->site,
            resourcePathRewriter: $this->resourcePathRewriter,
            templateVite: $config->templateViteOptions,
            debug: $debug,
        );
    }

    /**
     * Create or reuse a cached renderer instance.
     *
     * @param \Glaze\Config\BuildConfig $config Build configuration.
     * @param string $template Sugar template name.
     * @param bool $debug Whether to enable debug freshness checks.
     */
    public function createCached(BuildConfig $config, string $template, bool $debug = false): SugarPageRenderer
    {
        $cacheKey = $this->cacheKey($config, $template, $debug);
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $this->cache[$cacheKey] = $this->create($config, $template, $debug);

        return $this->cache[$cacheKey];
    }

    /**
     * Build a deterministic cache key for renderer reuse.
     *
     * @param \Glaze\Config\BuildConfig $config Build configuration.
     * @param string $template Sugar template name.
     * @param bool $debug Whether to enable debug freshness checks.
     */
    protected function cacheKey(BuildConfig $config, string $template, bool $debug): string
    {
        return hash('xxh3', implode('|', [
            $config->projectRoot,
            $template,
            $debug ? '1' : '0',
        ]));
    }
}
