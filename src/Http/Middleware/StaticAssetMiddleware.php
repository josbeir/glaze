<?php
declare(strict_types=1);

namespace Glaze\Http\Middleware;

/**
 * Serves raw static-folder assets for live development, with optional Glide image transforms.
 *
 * Files placed in the configured `static/` directory are served at the
 * site root (e.g. `static/logo.svg` is accessible via `/logo.svg`).
 * Image files support Glide manipulation query parameters (e.g. `?w=200&h=100`).
 */
final class StaticAssetMiddleware extends AbstractGlideAssetMiddleware
{
    /**
     * @inheritDoc
     */
    protected function assetRootPath(): string
    {
        return $this->config->staticPath();
    }
}
