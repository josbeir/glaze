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
 * An alternative template directory may be injected via the constructor for
 * testing or future extension. The renderer always runs in debug mode so that
 * template edits during Glaze development are picked up on every request.
 *
 * Vite integration is intentionally disabled here; core dev-UI templates link
 * to /_glaze/assets/ directly. The Vite pipeline (resources/vite.config.js)
 * is wired in once the built assets are committed to resources/dist/.
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
     * Constructor.
     *
     * @param \Glaze\Config\BuildConfig $config Build configuration (cache path + site config).
     * @param \Glaze\Support\ResourcePathRewriter $resourcePathRewriter Shared path rewriter.
     * @param string|null $templateDirectory Override template directory for testing. Defaults to
     *   the package's resources/backend/templates/ when null.
     */
    public function __construct(
        private BuildConfig $config,
        private ResourcePathRewriter $resourcePathRewriter,
        ?string $templateDirectory = null,
    ) {
        $this->templateDirectory = $templateDirectory ?? dirname(__DIR__, 3) . '/resources/backend/templates';
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
            cachePath: $this->config->cachePath(CachePath::Sugar),
            template: $templateName,
            siteConfig: $this->config->site,
            resourcePathRewriter: $this->resourcePathRewriter,
            templateVite: new TemplateViteOptions(),
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
