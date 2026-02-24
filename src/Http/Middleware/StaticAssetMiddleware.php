<?php
declare(strict_types=1);

namespace Glaze\Http\Middleware;

/**
 * Serves raw static-folder assets for live development.
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
