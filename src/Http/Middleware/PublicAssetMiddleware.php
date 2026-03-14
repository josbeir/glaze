<?php
declare(strict_types=1);

namespace Glaze\Http\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Serves static files from the generated public output directory.
 *
 * When a requested file also exists in a source directory (content or
 * static), this middleware yields to the downstream middlewares so the
 * authoritative source is always served. This prevents stale pre-built
 * copies from shadowing updated source files — images, PDFs, or any
 * other asset type.
 */
final class PublicAssetMiddleware extends AbstractAssetMiddleware
{
    /**
     * @inheritDoc
     */
    protected function assetRootPath(): string
    {
        return $this->config->outputPath();
    }

    /**
     * Skip files that exist in content or static source directories.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request Current request.
     */
    protected function createAssetResponse(ServerRequestInterface $request): ?ResponseInterface
    {
        $requestPath = $this->assetRequestPath($request);

        if ($this->existsInSourceDirectory($requestPath)) {
            return null;
        }

        return parent::createAssetResponse($request);
    }

    /**
     * Check whether a file exists in the content or static source directories.
     *
     * @param string $requestPath Resolved request path.
     */
    protected function existsInSourceDirectory(string $requestPath): bool
    {
        $relativePath = ltrim($requestPath, '/');
        if ($relativePath === '') {
            return false;
        }

        $sourcePaths = [
            $this->config->contentPath(),
            $this->config->staticPath(),
        ];

        foreach ($sourcePaths as $sourcePath) {
            $candidate = $sourcePath . DIRECTORY_SEPARATOR . $relativePath;
            if (is_file($candidate)) {
                return true;
            }
        }

        return false;
    }
}
