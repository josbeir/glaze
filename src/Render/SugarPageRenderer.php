<?php
declare(strict_types=1);

namespace Glaze\Render;

use Glaze\Config\SiteConfig;
use Glaze\Config\TemplateViteOptions;
use Glaze\Render\Sugar\Path\ResourcePathSugarExtension;
use Glaze\Support\ResourcePathRewriter;
use Sugar\Core\Cache\FileCache;
use Sugar\Core\Engine;
use Sugar\Core\Extension\ExtensionInterface;
use Sugar\Core\Loader\FileTemplateLoader;
use Sugar\Extension\Component\ComponentExtension;
use Sugar\Extension\Vite\ViteExtension;

/**
 * Renders page-level HTML through Sugar templates.
 */
final class SugarPageRenderer
{
    /**
     * Lazily-created template loader reused by this renderer.
     */
    protected ?FileTemplateLoader $loader = null;

    /**
     * Lazily-created template cache reused by this renderer.
     */
    protected ?FileCache $cache = null;

    /**
     * Additional Sugar extensions registered at runtime.
     *
     * @var array<\Sugar\Core\Extension\ExtensionInterface>
     */
    protected array $additionalExtensions = [];

    /**
     * Constructor.
     *
     * @param string $templatePath Absolute template directory path.
     * @param string $cachePath Absolute template cache path.
     * @param string $template Sugar template name used for pages.
     * @param \Glaze\Config\SiteConfig $siteConfig Site configuration for static attribute rewriting.
     * @param \Glaze\Support\ResourcePathRewriter $resourcePathRewriter Shared path rewriter.
     * @param \Glaze\Config\TemplateViteOptions $templateVite Sugar Vite extension configuration.
     * @param bool $liveMode Whether this renderer is used for live serving.
     */
    public function __construct(
        protected string $templatePath,
        protected string $cachePath,
        protected string $template,
        protected SiteConfig $siteConfig,
        protected ResourcePathRewriter $resourcePathRewriter,
        protected TemplateViteOptions $templateVite,
        protected bool $liveMode = false,
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
     * Return live mode state.
     */
    public function isLiveMode(): bool
    {
        return $this->liveMode;
    }

    /**
     * Return debug mode state.
     */
    public function isDebugEnabled(): bool
    {
        return $this->liveMode;
    }

    /**
     * Register an additional Sugar extension for this renderer instance.
     *
     * @param \Sugar\Core\Extension\ExtensionInterface $extension Sugar extension to register.
     */
    public function addExtension(ExtensionInterface $extension): void
    {
        $this->additionalExtensions[] = $extension;
    }

    /**
     * Return the template loader used by this renderer.
     */
    public function getLoader(): FileTemplateLoader
    {
        if (!$this->loader instanceof FileTemplateLoader) {
            $this->loader = new FileTemplateLoader($this->templatePath);
        }

        return $this->loader;
    }

    /**
     * Return the template cache used by this renderer.
     */
    public function getCache(): FileCache
    {
        if (!$this->cache instanceof FileCache) {
            $this->cache = new FileCache($this->cachePath);
        }

        if (!is_dir($this->cachePath)) {
            mkdir($this->cachePath, 0755, true);
        }

        return $this->cache;
    }

    /**
     * Create a configured Sugar engine.
     *
     * @param object|null $templateContext Template context available as `$this`.
     */
    protected function createEngine(?object $templateContext = null): Engine
    {
        $debug = $this->isDebugEnabled();

        $builder = Engine::builder()
            ->withTemplateLoader($this->getLoader())
            ->withCache($this->getCache())
            ->withDebug($debug)
            ->withTemplateContext($templateContext)
            ->withExtension(new ComponentExtension(['components']))
            ->withExtension(new ResourcePathSugarExtension(
                $this->siteConfig,
                $this->resourcePathRewriter,
            ));

        foreach ($this->additionalExtensions as $extension) {
            $builder->withExtension($extension);
        }

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
     * The `mode` field of {@see TemplateViteOptions} is passed directly to the Sugar Vite extension:
     * - `auto` — Glaze resolves mode by render mode: live => `dev`, build => `prod`.
     * - `dev`  — always connects to the Vite dev server regardless of debug state.
     * - `prod` — always reads from the manifest regardless of debug state.
     *
     * @return array{mode: string, assetBaseUrl: string, manifestPath: string|null, devServerUrl: string, injectClient: bool, defaultEntry: string|null}|null
     */
    protected function resolveViteConfiguration(): ?array
    {
        $viteConfiguration = $this->templateVite;
        $isEnabled = $this->liveMode
            ? $viteConfiguration->devEnabled
            : $viteConfiguration->buildEnabled;

        if (!$isEnabled) {
            return null;
        }

        $devServerUrl = $viteConfiguration->devServerUrl;
        $assetBaseUrl = $viteConfiguration->assetBaseUrl;
        if (!$this->resourcePathRewriter->isExternalResourcePath($assetBaseUrl)) {
            $assetBaseUrl = $this->resourcePathRewriter->applyBasePathToPath($assetBaseUrl, $this->siteConfig);
        }

        $mode = $viteConfiguration->mode;
        if ($mode === 'auto') {
            $mode = $this->liveMode ? 'dev' : 'prod';
        }

        return [
            'mode' => $mode,
            'assetBaseUrl' => $assetBaseUrl,
            'manifestPath' => $viteConfiguration->manifestPath,
            'devServerUrl' => $devServerUrl,
            'injectClient' => $viteConfiguration->injectClient,
            'defaultEntry' => $viteConfiguration->defaultEntry,
        ];
    }
}
