<?php
declare(strict_types=1);

namespace Glaze\Http\Middleware;

use Cake\Core\ContainerInterface;
use Glaze\Config\BuildConfig;
use Glaze\Http\Concern\BasePathAwareTrait;
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
    use BasePathAwareTrait;

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
     * @param \Glaze\Config\BuildConfig $config Build configuration (provides site basePath).
     * @param string $controllersDirectory Absolute path to the controllers directory to scan. Defaults to
     *   the package's own src/Http/Controller/ when empty.
     */
    public function __construct(
        protected ControllerRouter $router,
        protected ControllerViewRenderer $viewRenderer,
        protected ContainerInterface $container,
        protected BuildConfig $config,
        private string $controllersDirectory = '',
    ) {
    }

    /**
     * @inheritDoc
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $this->discoverOnce();

        // Use a stripped copy for route matching so the original request (with any
        // basePath prefix intact) is forwarded unchanged when no route matches.
        // Downstream handlers such as DevPageRequestHandler rely on the original
        // path for canonical redirects and Location headers.
        $strippedPath = $this->stripBasePathFromRequestPath($request->getUri()->getPath());
        $routingRequest = $request->withUri($request->getUri()->withPath($strippedPath));

        $match = $this->router->match($routingRequest);
        if (!$match instanceof MatchedRoute) {
            return $handler->handle($request);
        }

        /** @var object $controller */
        $controller = $this->container->get($match->controllerClass);

        $reflectionMethod = new ReflectionMethod($controller, $match->actionMethod);
        $args = $this->resolveArguments($reflectionMethod, $routingRequest, $match->params);

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

            // 2. Path parameter by name — coerced to the declared builtin type when possible.
            if (array_key_exists($name, $params)) {
                $rawValue = $params[$name];

                if ($type instanceof ReflectionNamedType && $type->isBuiltin()) {
                    $rawValue = $this->coercePathParam(
                        $rawValue,
                        $type->getName(),
                        $name,
                        $method,
                    );
                }

                $args[] = $rawValue;
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

    /**
     * Coerce a raw path-parameter string to the declared PHP builtin type.
     *
     * Handles `int`, `float`, and `bool`; passes all other types through as-is.
     *
     * @param string $raw Raw string extracted from the URL path.
     * @param string $typeName PHP builtin type name (e.g. 'int', 'float', 'bool', 'string').
     * @param string $paramName Parameter name (used in error messages).
     * @param \ReflectionMethod $method Reflected action method (used in error messages).
     * @return string|float|int|bool Coerced value.
     * @throws \RuntimeException When the value cannot be converted to the required type.
     */
    private function coercePathParam(
        string $raw,
        string $typeName,
        string $paramName,
        ReflectionMethod $method,
    ): int|float|bool|string {
        return match ($typeName) {
            'int' => is_numeric($raw) && !str_contains($raw, '.')
                ? (int)$raw
                : throw new RuntimeException(sprintf(
                    'Path parameter "$%s" for "%s::%s" expects int, got "%s".',
                    $paramName,
                    $method->getDeclaringClass()->getName(),
                    $method->getName(),
                    $raw,
                )),
            'float' => is_numeric($raw)
                ? (float)$raw
                : throw new RuntimeException(sprintf(
                    'Path parameter "$%s" for "%s::%s" expects float, got "%s".',
                    $paramName,
                    $method->getDeclaringClass()->getName(),
                    $method->getName(),
                    $raw,
                )),
            'bool' => match (strtolower($raw)) {
                '1', 'true', 'yes', 'on' => true,
                '0', 'false', 'no', 'off', '' => false,
                default => throw new RuntimeException(sprintf(
                    'Path parameter "$%s" for "%s::%s" expects bool, got "%s".',
                    $paramName,
                    $method->getDeclaringClass()->getName(),
                    $method->getName(),
                    $raw,
                )),
            },
            default => $raw,
        };
    }
}
