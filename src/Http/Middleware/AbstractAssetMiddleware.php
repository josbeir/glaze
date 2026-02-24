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
    protected AssetResponder $assetResponder;

    /**
     * Constructor.
     *
     * @param \Glaze\Config\BuildConfig $config Build configuration.
     * @param \Glaze\Http\AssetResponder|null $assetResponder Asset responder service.
     */
    public function __construct(
        protected BuildConfig $config,
        ?AssetResponder $assetResponder = null,
    ) {
        $this->assetResponder = $assetResponder ?? new AssetResponder();
    }

    /**
     * @inheritDoc
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $this->assetResponder->createFileResponse(
            rootPath: $this->assetRootPath(),
            requestPath: $request->getUri()->getPath(),
            allowDjot: false,
        );

        if ($response instanceof ResponseInterface) {
            return $response;
        }

        return $handler->handle($request);
    }

    /**
     * Resolve filesystem root path for the middleware asset scope.
     */
    abstract protected function assetRootPath(): string;
}
