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
 * Assets are resolved from the package's own resources/backend/assets/ directory so
 * that glaze's dev-UI (inspector, routes viewer, etc.) works without any
 * project-level configuration.
 *
 * Inspector templates emit asset hrefs with the basePath prepended via PHP
 * (e.g. `<?= $basePath ?>/_glaze/assets/css/dev.css`), so the browser always
 * requests the full `/{basePath}/_glaze/assets/...` URL. This middleware strips
 * the configured basePath before matching so both bare and prefixed forms are
 * resolved correctly:
 *
 * Example:
 *   GET /_glaze/assets/css/dev.css         → {package}/resources/backend/assets/css/dev.css
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
     * Absolute path to the package's resources/backend/assets/ directory.
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
        $this->assetsRootPath = dirname(__DIR__, 3) . '/resources/backend/assets';
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
