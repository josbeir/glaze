<?php
declare(strict_types=1);

namespace Glaze\Http\Middleware;

/**
 * Serves raw content-folder assets for live development.
 */
final class ContentAssetMiddleware extends AbstractAssetMiddleware
{
    /**
     * @inheritDoc
     */
    protected function assetRootPath(): string
    {
        return $this->config->contentPath();
    }
}
