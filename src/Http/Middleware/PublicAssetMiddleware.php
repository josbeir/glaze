<?php
declare(strict_types=1);

namespace Glaze\Http\Middleware;

/**
 * Serves static files from the generated public output directory.
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
}
