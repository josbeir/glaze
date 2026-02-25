<?php
declare(strict_types=1);

namespace Glaze\Http\Middleware;

use Glaze\Config\BuildConfig;
use Glaze\Http\AssetResponder;
use Glaze\Image\ImageTransformerInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Base middleware that serves assets and applies Glide transforms for image requests.
 */
abstract class AbstractGlideAssetMiddleware extends AbstractAssetMiddleware
{
    /**
     * Constructor.
     *
     * @param \Glaze\Config\BuildConfig $config Build configuration.
     * @param \Glaze\Http\AssetResponder $assetResponder Asset responder service.
     * @param \Glaze\Image\ImageTransformerInterface $imageTransformer Image transformer service.
     */
    public function __construct(
        BuildConfig $config,
        AssetResponder $assetResponder,
        protected ImageTransformerInterface $imageTransformer,
    ) {
        parent::__construct($config, $assetResponder);
    }

    /**
     * @inheritDoc
     */
    protected function createAssetResponse(ServerRequestInterface $request): ?ResponseInterface
    {
        $requestPath = $this->assetRequestPath($request);

        if ($this->isImagePath($requestPath)) {
            $transformedResponse = $this->imageTransformer->createResponse(
                rootPath: $this->assetRootPath(),
                requestPath: $requestPath,
                queryParams: $this->normalizeQueryParams($request->getQueryParams()),
                presets: $this->config->imagePresets,
                cachePath: $this->config->glideCachePath(),
                options: $this->config->imageOptions,
            );

            if ($transformedResponse instanceof ResponseInterface) {
                return $transformedResponse;
            }
        }

        return parent::createAssetResponse($request);
    }

    /**
     * Detect whether request path points to a transformable image file.
     *
     * @param string $requestPath Request URI path.
     */
    protected function isImagePath(string $requestPath): bool
    {
        $extension = strtolower(pathinfo($requestPath, PATHINFO_EXTENSION));
        if ($extension === '') {
            return false;
        }

        return in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'bmp', 'tif', 'tiff'], true);
    }

    /**
     * Normalize request query parameters to string-keyed map.
     *
     * @param array<mixed> $queryParams Raw request query parameters.
     * @return array<string, mixed>
     */
    protected function normalizeQueryParams(array $queryParams): array
    {
        $normalized = [];

        foreach ($queryParams as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }
}
