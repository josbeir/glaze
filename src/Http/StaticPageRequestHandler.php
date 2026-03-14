<?php
declare(strict_types=1);

namespace Glaze\Http;

use Cake\Http\Response;
use Glaze\Config\BuildConfig;
use Glaze\Http\Concern\BasePathAwareTrait;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Fallback request handler that serves pre-built static pages in static-serve mode.
 *
 * Used when `glaze serve --static` is active. Instead of live-rendering content,
 * this handler reads pre-built HTML files from the configured output directory:
 *
 * - Strips the configured basePath prefix so that projects deployed under a
 *   sub-path (e.g. `basePath: /docs`) resolve correctly.
 * - Performs a 301 redirect for extensionless directory paths that lack a
 *   trailing slash, matching the canonical redirect behaviour of the live handler.
 * - Serves the pre-built `index.html` for matching directory paths.
 * - Returns a 404 response using the generated `404.html` when present,
 *   falling back to a plain HTML error body otherwise.
 *
 * Example:
 *   GET /docs/getting-started  →  301 /docs/getting-started/
 *   GET /docs/getting-started/ →  200, serves public/getting-started/index.html
 *   GET /docs/missing-page/    →  404, serves public/404.html
 */
final class StaticPageRequestHandler implements RequestHandlerInterface
{
    use BasePathAwareTrait;

    /**
     * Constructor.
     *
     * @param \Glaze\Config\BuildConfig $config Build configuration.
     */
    public function __construct(
        protected BuildConfig $config,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $requestPath = $request->getUri()->getPath();
        $requestPath = $requestPath !== '' ? $requestPath : '/';

        $lookupPath = $this->stripBasePathFromRequestPath($requestPath);

        // Redirect extensionless non-root paths without a trailing slash to their
        // canonical form so that relative URLs in the served HTML resolve correctly.
        $isExtensionlessNonRoot = $lookupPath !== '/'
            && !str_ends_with($requestPath, '/')
            && pathinfo($lookupPath, PATHINFO_EXTENSION) === '';
        if ($isExtensionlessNonRoot) {
            $canonicalPath = $requestPath . '/';

            return (new Response(['charset' => 'UTF-8']))
                ->withStatus(301)
                ->withHeader('Location', $this->redirectLocation($canonicalPath, $request->getUri()->getQuery()));
        }

        $indexHtmlPath = $this->resolveIndexHtmlPath($lookupPath);
        if ($indexHtmlPath !== null) {
            $content = file_get_contents($indexHtmlPath);

            return (new Response(['charset' => 'UTF-8']))
                ->withStatus(200)
                ->withHeader('Content-Type', 'text/html; charset=UTF-8')
                ->withStringBody($content !== false ? $content : '');
        }

        $notFoundPath = $this->resolveNotFoundPath($lookupPath);
        $notFoundHtml = $this->readOutputFile($notFoundPath);
        if ($notFoundHtml === null && $notFoundPath !== '/404.html') {
            $notFoundHtml = $this->readOutputFile('/404.html');
        }

        return (new Response(['charset' => 'UTF-8']))
            ->withStatus(404)
            ->withHeader('Content-Type', 'text/html; charset=UTF-8')
            ->withStringBody($notFoundHtml ?? '<h1>404 Not Found</h1>');
    }

    /**
     * Resolve the pre-built index.html path for a request path within the output directory.
     *
     * Returns null when no matching index.html exists or the resolved path escapes
     * the output root (path traversal protection).
     *
     * @param string $lookupPath Basepath-stripped request path.
     */
    protected function resolveIndexHtmlPath(string $lookupPath): ?string
    {
        $outputRoot = realpath($this->config->outputPath());
        if (!is_string($outputRoot)) {
            return null;
        }

        $relativePath = ltrim($lookupPath, '/');
        $dirCandidate = $outputRoot
            . ($relativePath !== '' ? DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath) : '');

        $resolvedDir = realpath($dirCandidate);
        if (!is_string($resolvedDir) || !is_dir($resolvedDir)) {
            return null;
        }

        // Path traversal protection: resolved directory must remain within the output root.
        if ($resolvedDir !== $outputRoot && !str_starts_with($resolvedDir, $outputRoot . DIRECTORY_SEPARATOR)) {
            return null;
        }

        $indexPath = $resolvedDir . DIRECTORY_SEPARATOR . 'index.html';

        return is_file($indexPath) ? $indexPath : null;
    }

    /**
     * Determine which 404 page to serve for a failed lookup path.
     *
     * When i18n is enabled and the request begins with a known language URL
     * prefix (e.g. `/nl/…`), returns the language-scoped 404 path
     * (e.g. `/nl/404.html`). Falls back to `/404.html` for all other cases.
     *
     * @param string $lookupPath Basepath-stripped request path.
     */
    protected function resolveNotFoundPath(string $lookupPath): string
    {
        if ($this->config->i18n->isEnabled()) {
            foreach ($this->config->i18n->languages as $language) {
                if ($language->urlPrefix === '') {
                    continue;
                }

                $prefix = '/' . $language->urlPrefix;
                if ($lookupPath === $prefix || str_starts_with($lookupPath, $prefix . '/')) {
                    return $prefix . '/404.html';
                }
            }
        }

        return '/404.html';
    }

    /**
     * Read a pre-built file from the output directory by its URL path.
     *
     * Returns null when the file does not exist or cannot be read.
     *
     * @param string $urlPath URL path relative to the output root (e.g. `/404.html`).
     */
    protected function readOutputFile(string $urlPath): ?string
    {
        $outputRoot = realpath($this->config->outputPath());
        if (!is_string($outputRoot)) {
            return null;
        }

        $relative = ltrim($urlPath, '/');
        $candidate = $outputRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $resolved = realpath($candidate);

        if (!is_string($resolved) || !is_file($resolved)) {
            return null;
        }

        if (!str_starts_with($resolved, $outputRoot . DIRECTORY_SEPARATOR)) {
            return null;
        }

        $content = file_get_contents($resolved);

        return $content !== false ? $content : null;
    }

    /**
     * Build redirect location preserving query string.
     *
     * @param string $path Canonical path.
     * @param string $query Original query string.
     */
    protected function redirectLocation(string $path, string $query): string
    {
        if ($query === '') {
            return $path;
        }

        return $path . '?' . $query;
    }
}
