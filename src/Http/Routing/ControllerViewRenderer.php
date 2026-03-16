<?php
declare(strict_types=1);

namespace Glaze\Http\Routing;

use Cake\Http\Response;
use Glaze\Config\BuildConfig;
use Glaze\Config\Enum\CachePath;
use Glaze\Config\TemplateViteOptions;
use Glaze\Render\SugarPageRenderer;
use Glaze\Support\ResourcePathRewriter;
use Psr\Http\Message\ResponseInterface;

/**
 * Renders core controller action views using the Sugar template engine.
 *
 * Templates are resolved from the package's own resources/backend/templates/ directory:
 *   {package}/resources/backend/templates/{controller}/{action}.sugar.php
 *
 * Vite integration is wired to the package's own resources/backend/vite.config.js build
 * (port 5184, outDir assets/dist). A custom TemplateViteOptions instance may be injected
 * via the constructor to override the defaults, which is useful in tests.
 *
 * Example:
 *   $viewRenderer->render($matchedRoute, ['pages' => $pages]);
 */
final class ControllerViewRenderer
{
    /**
     * Absolute path to the template directory used for resolution.
     */
    private string $templateDirectory;

    /**
     * Vite options used when creating SugarPageRenderer instances.
     */
    protected TemplateViteOptions $backendVite;

    /**
     * Constructor.
     *
     * @param \Glaze\Config\BuildConfig $config Build configuration (cache path + site config).
     * @param \Glaze\Support\ResourcePathRewriter $resourcePathRewriter Shared path rewriter.
     * @param string|null $templateDirectory Override template directory. Defaults to the package's
     *   resources/backend/templates/ when null.
     */
    public function __construct(
        private BuildConfig $config,
        private ResourcePathRewriter $resourcePathRewriter,
        ?string $templateDirectory = null,
    ) {
        $packageRoot = dirname(__DIR__, 3);
        $this->templateDirectory = $templateDirectory ?? $packageRoot . '/resources/backend/templates';
        $this->backendVite = new TemplateViteOptions(
            buildEnabled: true,
            devEnabled: true,
            assetBaseUrl: '/_glaze/assets/dist/',
            manifestPath: $packageRoot . '/resources/backend/assets/dist/.vite/manifest.json',
            devServerUrl: 'http://localhost:5174',
            mode: 'dev',
        );
    }

    /**
     * Render a controller action and return a 200 HTML response.
     *
     * @param \Glaze\Http\Routing\MatchedRoute $route The matched route to render.
     * @param array<string, mixed> $data Template variables provided by the action.
     */
    public function render(MatchedRoute $route, array $data): ResponseInterface
    {
        $templateName = $route->controllerName . '/' . $route->actionName;

        $renderer = new SugarPageRenderer(
            templatePath: $this->templateDirectory,
            cachePath: $this->config->cachePath(CachePath::Sugar) . '/backend',
            template: $templateName,
            siteConfig: $this->config->site,
            resourcePathRewriter: $this->resourcePathRewriter,
            templateVite: $this->backendVite,
            debug: true,
        );

        $html = $renderer->render($data);

        return (new Response(['charset' => 'UTF-8']))
            ->withStatus(200)
            ->withHeader('Content-Type', 'text/html; charset=UTF-8')
            ->withStringBody($html);
    }

    /**
     * Check whether a template file exists for the given controller and action.
     *
     * @param string $controller Short controller name.
     * @param string $action Action name.
     */
    public function hasTemplate(string $controller, string $action): bool
    {
        $templateFile = $this->templateDirectory . '/' . $controller . '/' . $action . '.sugar.php';

        return is_file($templateFile);
    }
}
