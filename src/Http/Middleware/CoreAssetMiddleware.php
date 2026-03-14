<?php
declare(strict_types=1);

namespace Glaze\Http\Middleware;

use Cake\Http\Response;
use Glaze\Config\BuildConfig;
use Glaze\Http\AssetResponder;
use Glaze\Http\Concern\BasePathAwareTrait;
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
 * When a project configures a basePath (e.g. `/myapp`), Sugar templates emit
 * asset hrefs prefixed with that basePath (e.g. `/myapp/_glaze/assets/...`).
 * This middleware strips the project basePath before matching so that
 * `/_glaze/assets/` is always resolved correctly regardless of deployment prefix.
 *
 * Example:
 *   GET /_glaze/assets/css/dev.css         → {package}/resources/assets/css/dev.css
 *   GET /myapp/_glaze/assets/css/dev.css   → same (basePath stripped first)
 */
final class CoreAssetMiddleware implements MiddlewareInterface
{
    use BasePathAwareTrait;

    /**
     * URL prefix handled by this middleware (after basePath is stripped).
     */
    private const URL_PREFIX = '/_glaze/assets';

    /**
     * Absolute path to the package's resources/assets/ directory.
     */
    private string $assetsRootPath;

    /**
     * Constructor.
     *
     * @param \Glaze\Config\BuildConfig $config Build configuration (provides site basePath).
     * @param \Glaze\Http\AssetResponder $assetResponder Asset file responder.
     */
    public function __construct(
        protected BuildConfig $config,
        private AssetResponder $assetResponder,
    ) {
        $this->assetsRootPath = dirname(__DIR__, 3) . '/resources/assets';
    }

    /**
     * @inheritDoc
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $path = $this->stripBasePathFromRequestPath($request->getUri()->getPath());

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
