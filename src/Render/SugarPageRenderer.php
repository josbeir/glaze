<?php
declare(strict_types=1);

namespace Glaze\Render;

use Sugar\Core\Cache\FileCache;
use Sugar\Core\Engine;
use Sugar\Core\Loader\FileTemplateLoader;
use Sugar\Extension\Component\ComponentExtension;
use Sugar\Extension\Vite\ViteExtension;

/**
 * Renders page-level HTML through Sugar templates.
 */
final class SugarPageRenderer
{
    /**
     * Constructor.
     *
     * @param string $templatePath Absolute template directory path.
     * @param string $cachePath Absolute template cache path.
     * @param string $template Sugar template name used for pages.
     * @param bool $debug Whether to enable debug freshness checks.
     * @param array{mode: string, assetBaseUrl: string, manifestPath: string|null, devServerUrl: string, injectClient: bool, defaultEntry: string|null}|null $viteConfiguration Optional Sugar Vite extension configuration.
     */
    public function __construct(
        protected string $templatePath,
        protected string $cachePath,
        protected string $template,
        protected bool $debug = false,
        protected ?array $viteConfiguration = null,
    ) {
    }

    /**
     * Render a full page using Sugar.
     *
     * @param array<string, mixed> $data Template data.
     * @param object|null $templateContext Template context available as `$this`.
     */
    public function render(array $data, ?object $templateContext = null): string
    {
        return $this->createEngine($templateContext)->render($this->template, $data);
    }

    /**
     * Create a configured Sugar engine.
     *
     * @param object|null $templateContext Template context available as `$this`.
     */
    protected function createEngine(?object $templateContext = null): Engine
    {
        if (!is_dir($this->cachePath)) {
            mkdir($this->cachePath, 0755, true);
        }

        $builder = Engine::builder()
            ->withTemplateLoader(new FileTemplateLoader($this->templatePath))
            ->withCache(new FileCache($this->cachePath))
            ->withDebug($this->debug)
            ->withTemplateContext($templateContext)
            ->withExtension(new ComponentExtension(['components']));

        $viteConfiguration = $this->viteConfiguration;
        if (is_array($viteConfiguration)) {
            $builder->withExtension(new ViteExtension(
                assetBaseUrl: $viteConfiguration['assetBaseUrl'],
                mode: $viteConfiguration['mode'],
                manifestPath: $viteConfiguration['manifestPath'],
                devServerUrl: $viteConfiguration['devServerUrl'],
                injectClient: $viteConfiguration['injectClient'],
                defaultEntry: $viteConfiguration['defaultEntry'],
            ));
        }

        return $builder
            ->withHtmlExceptionRenderer()
            ->build();
    }
}
