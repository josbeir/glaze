<?php
declare(strict_types=1);

namespace Glaze\Render;

use Sugar\Core\Cache\FileCache;
use Sugar\Core\Engine;
use Sugar\Core\Loader\FileTemplateLoader;
use Sugar\Extension\Component\ComponentExtension;

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
     */
    public function __construct(
        protected string $templatePath,
        protected string $cachePath,
        protected string $template,
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
     * Create a configured Sugar engine.
     *
     * @param object|null $templateContext Template context available as `$this`.
     */
    protected function createEngine(?object $templateContext = null): Engine
    {
        if (!is_dir($this->cachePath)) {
            mkdir($this->cachePath, 0755, true);
        }

        return Engine::builder()
            ->withTemplateLoader(new FileTemplateLoader($this->templatePath))
            ->withCache(new FileCache($this->cachePath))
            ->withDebug($this->debug)
            ->withTemplateContext($templateContext)
            ->withExtension(new ComponentExtension(['components']))
            ->withHtmlExceptionRenderer()
            ->build();
    }
}
