<?php
declare(strict_types=1);

namespace Glaze\Render;

use Cake\Core\Configure;
use Glaze\Build\Enum\BuildEvent;
use Glaze\Build\Event\EventDispatcher;
use Glaze\Build\Event\SugarRendererCreatedEvent;
use Glaze\Config\BuildConfig;
use Glaze\Config\Enum\CachePath;
use Glaze\Support\ResourcePathRewriter;
use Glaze\Utility\Hash;

/**
 * Creates and caches page-level Sugar renderers.
 */
final class SugarPageRendererFactory
{
    /**
     * Renderer cache keyed by config/template/liveMode/debug combination.
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
     * @param bool $liveMode Whether this renderer is used for live serving.
     * @param \Glaze\Build\Event\EventDispatcher|null $dispatcher Optional build event dispatcher.
     */
    public function create(
        BuildConfig $config,
        string $template,
        bool $liveMode = false,
        ?EventDispatcher $dispatcher = null,
    ): SugarPageRenderer {
        $debug = $liveMode && (bool)Configure::read('debug', false);

        $viteOptions = $liveMode && $debug
            ? $config->templateViteOptions->applyEnvironmentOverrides()
            : $config->templateViteOptions;

        $renderer = new SugarPageRenderer(
            templatePath: $config->templatePath(),
            cachePath: $config->cachePath(CachePath::Sugar),
            template: $template,
            siteConfig: $config->site,
            resourcePathRewriter: $this->resourcePathRewriter,
            templateVite: $viteOptions,
            liveMode: $liveMode,
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
     * @param bool $liveMode Whether this renderer is used for live serving.
     * @param \Glaze\Build\Event\EventDispatcher|null $dispatcher Optional build event dispatcher.
     */
    public function createCached(
        BuildConfig $config,
        string $template,
        bool $liveMode = false,
        ?EventDispatcher $dispatcher = null,
    ): SugarPageRenderer {
        $debug = $liveMode && (bool)Configure::read('debug', false);
        $cacheKey = $this->cacheKey($config, $template, $liveMode, $debug);
        if (isset($this->cache[$cacheKey])) {
            return $this->cache[$cacheKey];
        }

        $this->cache[$cacheKey] = $this->create($config, $template, $liveMode, $dispatcher);

        return $this->cache[$cacheKey];
    }

    /**
     * Build a deterministic cache key for renderer reuse.
     *
     * @param \Glaze\Config\BuildConfig $config Build configuration.
     * @param string $template Sugar template name.
     * @param bool $liveMode Whether this renderer is used for live serving.
     * @param bool $debug Effective debug state for this renderer context.
     */
    protected function cacheKey(BuildConfig $config, string $template, bool $liveMode, bool $debug): string
    {
        return Hash::makeFromParts([
            $config->projectRoot,
            $template,
            $liveMode ? '1' : '0',
            $debug ? '1' : '0',
        ]);
    }
}
