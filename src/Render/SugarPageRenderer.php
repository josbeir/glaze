<?php
declare(strict_types=1);

namespace Glaze\Render;

use Glaze\Config\SiteConfig;
use Glaze\Config\TemplateViteOptions;
use Glaze\Render\Sugar\Path\ResourcePathSugarExtension;
use Glaze\Support\ResourcePathRewriter;
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
     * @param \Glaze\Config\SiteConfig $siteConfig Site configuration for static attribute rewriting.
     * @param \Glaze\Support\ResourcePathRewriter $resourcePathRewriter Shared path rewriter.
     * @param \Glaze\Config\TemplateViteOptions $templateVite Sugar Vite extension configuration.
     * @param bool $debug Whether to enable debug freshness checks.
     */
    public function __construct(
        protected string $templatePath,
        protected string $cachePath,
        protected string $template,
        protected SiteConfig $siteConfig,
        protected ResourcePathRewriter $resourcePathRewriter,
        protected TemplateViteOptions $templateVite,
        protected bool $debug = false,
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
     * Return renderer template name.
     */
    public function templateName(): string
    {
        return $this->template;
    }

    /**
     * Return debug mode state.
     */
    public function isDebugEnabled(): bool
    {
        return $this->debug;
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

        $builder->withExtension(new ResourcePathSugarExtension($this->siteConfig, $this->resourcePathRewriter));

        $viteConfiguration = $this->resolveViteConfiguration();
        if ($viteConfiguration !== null) {
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
            ->withPhpSyntaxValidation(true)
            ->build();
    }

    /**
     * Resolve Sugar Vite extension configuration for current render mode.
     *
     * @return array{mode: string, assetBaseUrl: string, manifestPath: string|null, devServerUrl: string, injectClient: bool, defaultEntry: string|null}|null
     */
    protected function resolveViteConfiguration(): ?array
    {
        $viteConfiguration = $this->templateVite;
        $isEnabled = $this->debug
            ? $viteConfiguration->devEnabled
            : $viteConfiguration->buildEnabled;

        if ($this->debug) {
            $enabledOverride = getenv('GLAZE_VITE_ENABLED');
            if ($enabledOverride === '1') {
                $isEnabled = true;
            } elseif ($enabledOverride === '0') {
                $isEnabled = false;
            }
        }

        if (!$isEnabled) {
            return null;
        }

        $devServerUrl = $viteConfiguration->devServerUrl;
        if ($this->debug) {
            $runtimeViteUrl = getenv('GLAZE_VITE_URL');
            if (is_string($runtimeViteUrl) && $runtimeViteUrl !== '') {
                $devServerUrl = $runtimeViteUrl;
            }
        }

        $assetBaseUrl = $viteConfiguration->assetBaseUrl;
        if (!$this->resourcePathRewriter->isExternalResourcePath($assetBaseUrl)) {
            $assetBaseUrl = $this->resourcePathRewriter->applyBasePathToPath($assetBaseUrl, $this->siteConfig);
        }

        return [
            'mode' => $this->debug ? 'dev' : 'prod',
            'assetBaseUrl' => $assetBaseUrl,
            'manifestPath' => $viteConfiguration->manifestPath,
            'devServerUrl' => $devServerUrl,
            'injectClient' => $viteConfiguration->injectClient,
            'defaultEntry' => $viteConfiguration->defaultEntry,
        ];
    }
}
