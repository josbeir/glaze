<?php
declare(strict_types=1);

namespace Glaze\Http;

use Cake\Http\Response;
use Glaze\Build\SiteBuilder;
use Glaze\Config\BuildConfig;
use Glaze\Http\Concern\BasePathAwareTrait;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Fallback request handler that renders content pages in live mode.
 */
final class DevPageRequestHandler implements RequestHandlerInterface
{
    use BasePathAwareTrait;

    /**
     * Per-instance cache of rendered not-found page HTML keyed by lookup path.
     * Null indicates the path was attempted but yielded no rendered page.
     *
     * @var array<string, string|null>
     */
    private array $notFoundCache = [];

    /**
     * Constructor.
     *
     * @param \Glaze\Config\BuildConfig $config Build configuration.
     * @param \Glaze\Build\SiteBuilder $siteBuilder Site builder service.
     */
    public function __construct(
        protected BuildConfig $config,
        protected SiteBuilder $siteBuilder,
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
        $html = $this->siteBuilder->renderRequest($this->config, $lookupPath);
        if (!is_string($html)) {
            return (new Response(['charset' => 'UTF-8']))
                ->withStatus(404)
                ->withHeader('Content-Type', 'text/html; charset=UTF-8')
                ->withStringBody($this->resolveNotFoundHtml($lookupPath) ?? '<h1>404 Not Found</h1>');
        }

        $canonicalPath = $this->canonicalDirectoryPath($requestPath);
        if (is_string($canonicalPath)) {
            return (new Response(['charset' => 'UTF-8']))
                ->withStatus(301)
                ->withHeader('Location', $this->redirectLocation($canonicalPath, $request->getUri()->getQuery()));
        }

        return (new Response(['charset' => 'UTF-8']))
            ->withStatus(200)
            ->withHeader('Content-Type', 'text/html; charset=UTF-8')
            ->withStringBody($html);
    }

    /**
     * Build a canonical trailing-slash path for directory-like requests.
     *
     * @param string $requestPath Request URI path.
     */
    protected function canonicalDirectoryPath(string $requestPath): ?string
    {
        if ($requestPath === '/' || str_ends_with($requestPath, '/')) {
            return null;
        }

        if (pathinfo($requestPath, PATHINFO_EXTENSION) !== '') {
            return null;
        }

        return $requestPath . '/';
    }

    /**
     * Resolve the best-matching not-found page HTML for a failed lookup path.
     *
     * When i18n is enabled, first tries a language-scoped 404 page (e.g. `/nl/404.html`)
     * derived from the request prefix, then falls back to the root `/404.html`.
     * Results are cached per path on this instance to avoid redundant re-renders.
     *
     * @param string $lookupPath Normalized lookup path that yielded no result.
     */
    protected function resolveNotFoundHtml(string $lookupPath): ?string
    {
        $notFoundPath = $this->resolveNotFoundPath($lookupPath);
        $html = $this->renderCachedNotFoundPage($notFoundPath);

        if ($html === null && $notFoundPath !== '/404.html') {
            return $this->renderCachedNotFoundPage('/404.html');
        }

        return $html;
    }

    /**
     * Determine the not-found page lookup path for a failed request.
     *
     * When i18n is enabled and the failed path begins with a known language
     * URL prefix (e.g. `/nl/…`), returns the language-scoped 404 path
     * (e.g. `/nl/404.html`). Falls back to `/404.html` for all other cases.
     *
     * @param string $lookupPath Normalized lookup path that yielded no result.
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
     * Render the not-found page at the given path, caching the result.
     *
     * Null is stored in the cache when no page matches the path, preventing
     * repeated redundant renders on every request that misses.
     *
     * @param string $path URL path to render.
     */
    protected function renderCachedNotFoundPage(string $path): ?string
    {
        if (!array_key_exists($path, $this->notFoundCache)) {
            $html = $this->siteBuilder->renderRequest($this->config, $path);
            $this->notFoundCache[$path] = is_string($html) ? $html : null;
        }

        return $this->notFoundCache[$path];
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
