<?php
declare(strict_types=1);

namespace Glaze\Http;

use Cake\Http\Response;
use Glaze\Build\SiteBuilder;
use Glaze\Config\BuildConfig;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Fallback request handler that renders content pages in live mode.
 */
final class DevPageRequestHandler implements RequestHandlerInterface
{
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
                ->withStringBody('<h1>404 Not Found</h1>');
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

    /**
     * Strip configured base path prefix from incoming request path.
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

        if ($normalizedPath === $basePath) {
            return '/';
        }

        if (str_starts_with($normalizedPath, $basePath . '/')) {
            return substr($normalizedPath, strlen($basePath)) ?: '/';
        }

        return $normalizedPath;
    }
}
