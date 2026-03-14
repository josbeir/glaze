<?php
declare(strict_types=1);

namespace Glaze\Http\Middleware;

use Cake\Http\Response;
use Glaze\Http\AssetResponder;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Serves package-internal assets from under the /_glaze/assets/ URL prefix.
 *
 * Assets are resolved from the package's own resources/assets/ directory so
 * that glaze's dev-UI (inspector, routes viewer, etc.) works without any
 * project-level configuration.
 *
 * This middleware intentionally does NOT extend AbstractAssetMiddleware.
 * The /_glaze/ prefix is absolute and must never have a project base-path
 * stripped from it.
 *
 * Example:
 *   GET /_glaze/assets/css/dev.css → {package}/resources/assets/css/dev.css
 */
final class CoreAssetMiddleware implements MiddlewareInterface
{
    /**
     * URL prefix handled by this middleware.
     */
    private const URL_PREFIX = '/_glaze/assets';

    /**
     * Absolute path to the package's resources/assets/ directory.
     */
    private string $assetsRootPath;

    /**
     * Constructor.
     *
     * @param \Glaze\Http\AssetResponder $assetResponder Asset file responder.
     */
    public function __construct(private AssetResponder $assetResponder)
    {
        $this->assetsRootPath = dirname(__DIR__, 3) . '/resources/assets';
    }

    /**
     * @inheritDoc
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $request->getUri()->getPath();

        if (!str_starts_with($path, self::URL_PREFIX . '/')) {
            return $handler->handle($request);
        }

        $relativePath = substr($path, strlen(self::URL_PREFIX));

        $response = $this->assetResponder->createFileResponse($this->assetsRootPath, $relativePath);

        if ($response instanceof Response) {
            return $response;
        }

        return $handler->handle($request);
    }
}
