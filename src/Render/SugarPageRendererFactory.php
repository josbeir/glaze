<?php
declare(strict_types=1);

namespace Glaze\Render;

use Glaze\Build\Event\BuildEvent;
use Glaze\Build\Event\EventDispatcher;
use Glaze\Build\Event\SugarRendererCreatedEvent;
use Glaze\Config\BuildConfig;
use Glaze\Support\ResourcePathRewriter;
use Glaze\Utility\Hash;

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
     * @param \Glaze\Build\Event\EventDispatcher|null $dispatcher Optional build event dispatcher.
     */
    public function create(
        BuildConfig $config,
        string $template,
        bool $debug = false,
        ?EventDispatcher $dispatcher = null,
    ): SugarPageRenderer {
        $renderer = new SugarPageRenderer(
            templatePath: $config->templatePath(),
            cachePath: $config->templateCachePath(),
            template: $template,
            siteConfig: $config->site,
            resourcePathRewriter: $this->resourcePathRewriter,
            templateVite: $config->templateViteOptions,
            debug: $debug,
        );

        $dispatcher?->dispatch(
            BuildEvent::SugarRendererCreated,
            new SugarRendererCreatedEvent($renderer, $template, $config),
        );

        return $renderer;
    }

    /**
     * Create or reuse a cached renderer instance.
     *
     * @param \Glaze\Config\BuildConfig $config Build configuration.
     * @param string $template Sugar template name.
     * @param bool $debug Whether to enable debug freshness checks.
     * @param \Glaze\Build\Event\EventDispatcher|null $dispatcher Optional build event dispatcher.
     */
    public function createCached(
        BuildConfig $config,
        string $template,
        bool $debug = false,
        ?EventDispatcher $dispatcher = null,
    ): SugarPageRenderer {
        $cacheKey = $this->cacheKey($config, $template, $debug);
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $this->cache[$cacheKey] = $this->create($config, $template, $debug, $dispatcher);

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
        return Hash::makeFromParts([
            $config->projectRoot,
            $template,
            $debug ? '1' : '0',
        ]);
    }
}
