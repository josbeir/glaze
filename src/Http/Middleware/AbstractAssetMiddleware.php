<?php
declare(strict_types=1);

namespace Glaze\Http\Middleware;

use Glaze\Config\BuildConfig;
use Glaze\Http\AssetResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Base middleware for serving static assets from a configured root path.
 */
abstract class AbstractAssetMiddleware implements MiddlewareInterface
{
    /**
     * Constructor.
     *
     * @param \Glaze\Config\BuildConfig $config Build configuration.
     * @param \Glaze\Http\AssetResponder $assetResponder Asset responder service.
     */
    public function __construct(
        protected BuildConfig $config,
        protected AssetResponder $assetResponder,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (!$this->shouldHandleRequest($request)) {
            return $handler->handle($request);
        }

        $response = $this->createAssetResponse($request);

        if ($response instanceof ResponseInterface) {
            return $response;
        }

        return $handler->handle($request);
    }

    /**
     * Determine whether the request should be evaluated by this middleware.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request Current request.
     */
    protected function shouldHandleRequest(ServerRequestInterface $request): bool
    {
        return true;
    }

    /**
     * Create an asset response for the request when a matching file exists.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request Current request.
     */
    protected function createAssetResponse(ServerRequestInterface $request): ?ResponseInterface
    {
        return $this->assetResponder->createFileResponse(
            rootPath: $this->assetRootPath(),
            requestPath: $this->assetRequestPath($request),
            allowDjot: false,
        );
    }

    /**
     * Resolve middleware asset request path with optional basePath stripping.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request Current request.
     */
    protected function assetRequestPath(ServerRequestInterface $request): string
    {
        $requestPath = $request->getUri()->getPath();
        if ($requestPath === '') {
            $requestPath = '/';
        }

        return $this->stripBasePathFromRequestPath($requestPath);
    }

    /**
     * Strip configured base path from request path for filesystem resolution.
     *
     * @param string $requestPath Request URI path.
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

    /**
     * Resolve filesystem root path for the middleware asset scope.
     */
    abstract protected function assetRootPath(): string;
}
