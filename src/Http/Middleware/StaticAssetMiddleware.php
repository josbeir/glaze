<?php
declare(strict_types=1);

namespace Glaze\Http\Middleware;

/**
 * Serves raw static-folder assets for live development.
 *
 * Files placed in the configured `static/` directory are served at the
 * site root (e.g. `static/logo.svg` is accessible via `/logo.svg`).
 */
final class StaticAssetMiddleware extends AbstractAssetMiddleware
{
    /**
     * @inheritDoc
     */
    protected function assetRootPath(): string
    {
        return $this->config->staticPath();
    }
}
