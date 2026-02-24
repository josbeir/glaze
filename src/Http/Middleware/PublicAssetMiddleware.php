<?php
declare(strict_types=1);

namespace Glaze\Http\Middleware;

/**
 * Serves static files from the generated public output directory.
 */
final class PublicAssetMiddleware extends AbstractGlideAssetMiddleware
{
    /**
     * @inheritDoc
     */
    protected function assetRootPath(): string
    {
        return $this->config->outputPath();
    }
}
