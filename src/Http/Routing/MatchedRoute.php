<?php
declare(strict_types=1);

namespace Glaze\Http\Routing;

/**
 * Immutable value object representing a successfully matched controller route.
 *
 * Carries the resolved controller class, the action method name, extracted
 * path parameters and the derived controller/action names used for view
 * template resolution.
 *
 * Example:
 *   $route->controllerName // 'admin' (derived from AdminController)
 *   $route->actionName     // 'edit'
 *   $route->params         // ['slug' => 'my-article']
 */
final readonly class MatchedRoute
{
    /**
     * Constructor.
     *
     * @param class-string $controllerClass Fully-qualified controller class name.
     * @param string $actionMethod Method name on the controller.
     * @param array<string, string> $params Captured path parameters keyed by name.
     * @param string $controllerName Lowercased short controller name (without "Controller" suffix).
     * @param string $actionName Action method name in lowercase.
     */
    public function __construct(
        public string $controllerClass,
        public string $actionMethod,
        public array $params,
        public string $controllerName,
        public string $actionName,
    ) {
    }
}
