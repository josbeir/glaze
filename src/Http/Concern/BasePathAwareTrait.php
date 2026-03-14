<?php
declare(strict_types=1);

namespace Glaze\Http\Concern;

/**
 * Provides base-path stripping helpers for HTTP handlers and middleware.
 *
 * Classes that use this trait must expose a `$config` property whose
 * `site->basePath` attribute holds the configured base path (or null when
 * no base path is configured).
 *
 * Example:
 *
 * ```php
 * final class MyHandler implements RequestHandlerInterface
 * {
 *     use BasePathAwareTrait;
 *
 *     public function __construct(protected BuildConfig $config) {}
 * }
 * ```
 */
trait BasePathAwareTrait
{
    /**
     * Strip the configured base path prefix from an incoming request path.
     *
     * Returns a normalized path that starts with `/` and has the base path
     * prefix removed, or the original path when no base path is configured or
     * the path does not start with the prefix.
     *
     * @param string $requestPath Incoming request path.
     */
    protected function stripBasePathFromRequestPath(string $requestPath): string
    {
        $basePath = $this->config->site->basePath;
        $normalizedPath = '/' . ltrim($requestPath, '/');

        if ($basePath === null || $basePath === '') {
            return $normalizedPath;
        }

        return $this->stripPathPrefix($normalizedPath, $basePath);
    }

    /**
     * Strip a leading prefix from a path, returning the remainder or `/` when the path matches exactly.
     *
     * @param string $path Normalized request path.
     * @param string $prefix Prefix to strip (e.g. `/docs` or `/static`).
     */
    protected function stripPathPrefix(string $path, string $prefix): string
    {
        if ($path === $prefix) {
            return '/';
        }

        if (str_starts_with($path, $prefix . '/')) {
            return substr($path, strlen($prefix)) ?: '/';
        }

        return $path;
    }
}
