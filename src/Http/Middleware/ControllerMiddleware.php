<?php
declare(strict_types=1);

namespace Glaze\Http\Middleware;

use Cake\Core\ContainerInterface;
use Glaze\Http\Routing\ControllerRouter;
use Glaze\Http\Routing\ControllerViewRenderer;
use Glaze\Http\Routing\MatchedRoute;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ReflectionMethod;
use ReflectionNamedType;
use RuntimeException;

/**
 * Routes incoming requests to attribute-annotated controller action methods.
 *
 * On each request the router is asked to match the path and HTTP method. On a
 * match the associated controller is resolved from the DI container, its
 * action method parameters are auto-wired (path params by name, PSR-7 request
 * by type, arbitrary services by type), and the method is invoked.
 *
 * Action return types:
 *   - ResponseInterface — returned as-is
 *   - array             — passed to ControllerViewRenderer which renders the
 *                         matching Sugar template and returns an HTML response
 *
 * When no route matches the request is forwarded to the next handler.
 *
 * Example:
 *   #[RoutePrefix('/admin')]
 *   class AdminController {
 *       #[Route('/articles/{slug}')]
 *       public function edit(string $slug, ArticleRepository $repo): array { ... }
 *   }
 */
final class ControllerMiddleware implements MiddlewareInterface
{
    /**
     * Whether controller discovery has run for this request cycle.
     */
    private bool $discovered = false;

    /**
     * Constructor.
     *
     * @param \Glaze\Http\Routing\ControllerRouter $router Controller router.
     * @param \Glaze\Http\Routing\ControllerViewRenderer $viewRenderer View renderer for array returns.
     * @param \Cake\Core\ContainerInterface $container DI container for controller and service resolution.
     * @param string $controllersDirectory Absolute path to the controllers directory to scan. Defaults to
     *   the package's own src/Http/Controller/ when empty.
     */
    public function __construct(
        protected ControllerRouter $router,
        protected ControllerViewRenderer $viewRenderer,
        protected ContainerInterface $container,
        private string $controllersDirectory = '',
    ) {
    }

    /**
     * @inheritDoc
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->discoverOnce();

        $match = $this->router->match($request);
        if (!$match instanceof MatchedRoute) {
            return $handler->handle($request);
        }

        /** @var object $controller */
        $controller = $this->container->get($match->controllerClass);

        $reflectionMethod = new ReflectionMethod($controller, $match->actionMethod);
        $args = $this->resolveArguments($reflectionMethod, $request, $match->params);

        $result = $reflectionMethod->invokeArgs($controller, $args);

        if ($result instanceof ResponseInterface) {
            return $result;
        }

        if (is_array($result)) {
            /** @var array<string, mixed> $result */
            return $this->viewRenderer->render($match, $result);
        }

        throw new RuntimeException(sprintf(
            'Controller action "%s::%s" must return %s or array, got %s.',
            $match->controllerClass,
            $match->actionMethod,
            ResponseInterface::class,
            get_debug_type($result),
        ));
    }

    /**
     * Run controller discovery exactly once per middleware instance lifetime.
     *
     * Scans the package's own src/Http/Controller/ directory by default, or the
     * explicitly configured $controllersDirectory when provided.
     */
    private function discoverOnce(): void
    {
        if ($this->discovered) {
            return;
        }

        $dir = $this->controllersDirectory !== ''
            ? $this->controllersDirectory
            : dirname(__DIR__) . '/Controller';

        $this->router->discover($dir);
        $this->discovered = true;
    }

    /**
     * Resolve the argument list for an action method.
     *
     * Resolution order for each parameter:
     *   1. ServerRequestInterface — injected from the current PSR-7 request
     *   2. Path parameter — matched by parameter name from the route
     *   3. Named service — resolved by fully-qualified type from the container
     *   4. Default value — used when the parameter is optional
     *
     * @param \ReflectionMethod $method Reflected action method.
     * @param \Psr\Http\Message\ServerRequestInterface $request Current request.
     * @param array<string, string> $params Path parameters extracted from the route.
     * @return array<int, mixed> Positional argument list ready for invokeArgs.
     * @throws \RuntimeException When a required parameter cannot be resolved.
     */
    protected function resolveArguments(
        ReflectionMethod $method,
        ServerRequestInterface $request,
        array $params,
    ): array {
        $args = [];

        foreach ($method->getParameters() as $parameter) {
            $name = $parameter->getName();
            $type = $parameter->getType();

            // 1. PSR-7 request by type.
            if (
                $type instanceof ReflectionNamedType
                && !$type->isBuiltin()
                && is_a($type->getName(), ServerRequestInterface::class, true)
            ) {
                $args[] = $request;
                continue;
            }

            // 2. Path parameter by name.
            if (array_key_exists($name, $params)) {
                $args[] = $params[$name];
                continue;
            }

            // 3. Service from the DI container by type.
            if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
                $args[] = $this->container->get($type->getName());
                continue;
            }

            // 4. Optional parameter default.
            if ($parameter->isOptional()) {
                $args[] = $parameter->getDefaultValue();
                continue;
            }

            throw new RuntimeException(sprintf(
                'Cannot resolve parameter "$%s" for action "%s::%s": no type hint, path parameter, or default value.',
                $name,
                $method->getDeclaringClass()->getName(),
                $method->getName(),
            ));
        }

        return $args;
    }
}
