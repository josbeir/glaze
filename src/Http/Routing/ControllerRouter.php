<?php
declare(strict_types=1);

namespace Glaze\Http\Routing;

use FilesystemIterator;
use Glaze\Http\Attribute\Route;
use Glaze\Http\Attribute\RoutePrefix;
use Psr\Http\Message\ServerRequestInterface;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

/**
 * Discovers and matches attribute-based controller routes.
 *
 * Scans a directory for PHP controller classes annotated with #[Route] and
 * optionally #[RoutePrefix], builds an internal route table, then matches
 * incoming PSR-7 requests against it.
 *
 * Example:
 *   $router = new ControllerRouter();
 *   $router->discover('/path/to/project/controllers');
 *   $match = $router->match($request); // MatchedRoute|null
 */
final class ControllerRouter
{
    /**
     * Compiled route table.
     *
     * Each entry describes one route:
     *   - pattern:    PCRE regex with named captures for path parameters
     *   - methods:    list of uppercase HTTP methods accepted
     *   - class:      fully-qualified controller class name
     *   - method:     action method name
     *   - controller: short controller name for view resolution
     *   - action:     action name for view resolution
     *
     * @var list<array{pattern: string, methods: list<string>, class: class-string, method: string, controller: string, action: string}>
     */
    private array $routes = [];

    /**
     * Scan a directory for controller PHP files and register their routes.
     *
     * Each PHP file in $directory is required and inspected via reflection.
     * Classes annotated with #[Route] on their methods (and optionally
     * #[RoutePrefix] on the class) are registered in the route table.
     *
     * @param string $directory Absolute path to the controllers directory.
     */
    public function discover(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $fileInfo */
        foreach ($iterator as $fileInfo) {
            if ($fileInfo->getExtension() !== 'php') {
                continue;
            }

            $this->registerFile($fileInfo->getPathname());
        }
    }

    /**
     * Match a PSR-7 request against the registered routes.
     *
     * Returns the first matching MatchedRoute, or null when no routes match
     * the request path and method combination.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request Incoming request.
     */
    public function match(ServerRequestInterface $request): ?MatchedRoute
    {
        $path = $request->getUri()->getPath();
        $path = $path !== '' ? $path : '/';

        $method = strtoupper($request->getMethod());

        foreach ($this->routes as $route) {
            if (!preg_match($route['pattern'], $path, $matches)) {
                continue;
            }

            if (!in_array($method, $route['methods'], true)) {
                continue;
            }

            $params = array_filter(
                $matches,
                static fn(int|string $key): bool => is_string($key),
                ARRAY_FILTER_USE_KEY,
            );

            return new MatchedRoute(
                controllerClass: $route['class'],
                actionMethod: $route['method'],
                params: array_map('strval', $params),
                controllerName: $route['controller'],
                actionName: $route['action'],
            );
        }

        return null;
    }

    /**
     * Return all registered routes (useful for debugging/testing).
     *
     * @return list<array{pattern: string, methods: list<string>, class: class-string, method: string, controller: string, action: string}>
     */
    public function routes(): array
    {
        return $this->routes;
    }

    /**
     * Require a PHP file and register the controller class it defines.
     *
     * The fully-qualified class name is extracted by parsing PHP tokens so
     * that classes already loaded by the autoloader are still discovered.
     *
     * @param string $filePath Absolute path to the PHP file.
     */
    private function registerFile(string $filePath): void
    {
        $className = $this->extractClassName($filePath);
        if ($className === null) {
            return;
        }

        if (!class_exists($className)) {
            require_once $filePath;
        }

        $this->registerClass($className);
    }

    /**
     * Extract the fully-qualified class name from a PHP source file.
     *
     * Parses PHP tokens to find the first namespace declaration and the first
     * class (or final class) declaration. Returns null when no class is found.
     *
     * @param string $filePath Absolute path to the PHP file.
     */
    private function extractClassName(string $filePath): ?string
    {
        $source = file_get_contents($filePath);
        if ($source === false) {
            return null;
        }

        $tokens = token_get_all($source);
        $count = count($tokens);
        $namespace = '';

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if (!is_array($token)) {
                continue;
            }

            if ($token[0] === T_NAMESPACE) {
                $i++;
                $ns = '';
                while ($i < $count) {
                    $t = $tokens[$i];
                    $namespaceParts = [T_STRING, T_NS_SEPARATOR, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED];
                    if (is_array($t) && in_array($t[0], $namespaceParts, true)) {
                        $ns .= $t[1];
                        $i++;
                        continue;
                    }

                    if (!is_array($t) && $t === '{') {
                        break;
                    }

                    if (!is_array($t) && $t === ';') {
                        break;
                    }

                    $i++;
                }

                $namespace = $ns;
            }

            if ($token[0] === T_CLASS) {
                // Skip whitespace to find the class name token.
                $j = $i + 1;
                while ($j < $count && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                    $j++;
                }

                if (is_array($tokens[$j]) && $tokens[$j][0] === T_STRING) {
                    $shortName = $tokens[$j][1];

                    return $namespace !== '' ? $namespace . '\\' . $shortName : $shortName;
                }
            }
        }

        return null;
    }

    /**
     * Inspect a class for route attributes and register each found route.
     *
     * @param class-string|string $className Fully-qualified class name.
     */
    private function registerClass(string $className): void
    {
        if (!class_exists($className)) {
            return;
        }

        $reflection = new ReflectionClass($className);

        if ($reflection->isAbstract() || $reflection->isInterface() || $reflection->isTrait()) {
            return;
        }

        $prefix = '';
        $prefixAttributes = $reflection->getAttributes(RoutePrefix::class);
        if ($prefixAttributes !== []) {
            /** @var \Glaze\Http\Attribute\RoutePrefix $routePrefixAttr */
            $routePrefixAttr = $prefixAttributes[0]->newInstance();
            $prefix = rtrim($routePrefixAttr->prefix, '/');
        }

        $controllerName = $this->resolveControllerName($reflection->getShortName());

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $routeAttributes = $method->getAttributes(Route::class);
            foreach ($routeAttributes as $attributeRef) {
                /** @var \Glaze\Http\Attribute\Route $routeAttr */
                $routeAttr = $attributeRef->newInstance();

                $fullPath = $prefix . '/' . ltrim($routeAttr->path, '/');

                // Normalise double slashes that arise when prefix ends at '/' and
                // path also starts with '/', except for the root path.
                $normalised = preg_replace('#/{2,}#', '/', $fullPath);
                $fullPath = $normalised ?? $fullPath;
                if ($fullPath === '') {
                    $fullPath = '/';
                }

                $this->routes[] = [
                    'pattern' => $this->buildPattern($fullPath),
                    'methods' => $routeAttr->methods,
                    'class' => $reflection->getName(),
                    'method' => $method->getName(),
                    'controller' => $controllerName,
                    'action' => strtolower($method->getName()),
                ];
            }
        }
    }

    /**
     * Derive the short, lowercase controller name from a class name.
     *
     * Strips the "Controller" suffix if present.
     *
     * @param string $shortName Short (unqualified) class name.
     */
    private function resolveControllerName(string $shortName): string
    {
        $name = preg_replace('/Controller$/i', '', $shortName);

        return strtolower($name ?? $shortName);
    }

    /**
     * Convert a route path into a PCRE pattern with named captures.
     *
     * Path parameters in the form {name} are converted to named captures
     * (?P<name>[^/]+).
     *
     * @param string $path Route path, e.g. '/articles/{slug}'.
     * @throws \RuntimeException When the compiled pattern is invalid.
     */
    private function buildPattern(string $path): string
    {
        $escaped = preg_quote($path, '#');
        $pattern = preg_replace('/\\\\{([a-zA-Z_]\w*)\\\\}/', '(?P<$1>[^/]+)', $escaped);

        if (!is_string($pattern)) {
            throw new RuntimeException(sprintf('Failed to compile route pattern for path "%s".', $path));
        }

        return '#^' . $pattern . '$#';
    }
}
