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
     */
    public function render(array $data): string
    {
        return $this->createEngine()->render($this->template, $data);
    }

    /**
     * Create a configured Sugar engine.
     */
    protected function createEngine(): Engine
    {
        if (!is_dir($this->cachePath)) {
            mkdir($this->cachePath, 0755, true);
        }

        return Engine::builder()
            ->withTemplateLoader(new FileTemplateLoader($this->templatePath))
            ->withCache(new FileCache($this->cachePath))
            ->withDebug($this->debug)
            ->withExtension(new ComponentExtension(['components']))
            ->withHtmlExceptionRenderer()
            ->build();
    }
}
