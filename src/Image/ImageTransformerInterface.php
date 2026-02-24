<?php
declare(strict_types=1);

namespace Glaze\Image;

use Psr\Http\Message\ResponseInterface;

/**
 * Contract for transforming image requests into HTTP responses.
 */
interface ImageTransformerInterface
{
    /**
     * Transform an image request and return an HTTP response when successful.
     *
     * @param string $rootPath Source image root path.
     * @param string $requestPath Request URI path.
     * @param array<string, mixed> $queryParams Request query parameters.
     * @param array<string, array<string, string>> $presets Configured preset map.
     * @param string $cachePath Absolute cache directory path.
     * @param array<string, string> $options Optional Glide server options.
     */
    public function createResponse(
        string $rootPath,
        string $requestPath,
        array $queryParams,
        array $presets,
        string $cachePath,
        array $options = [],
    ): ?ResponseInterface;
}
