<?php
declare(strict_types=1);

namespace Glaze\Image;

use Cake\Http\MimeType;
use Cake\Http\Response;
use League\Glide\ServerFactory;
use Psr\Http\Message\ResponseInterface;
use Throwable;

/**
 * Transforms image requests using League Glide and returns PSR responses.
 */
final class GlideImageTransformer implements ImageTransformerInterface
{
    /**
     * Constructor.
     *
     * @param \Glaze\Image\ImagePresetResolver $presetResolver Preset resolver service.
     */
    public function __construct(protected ImagePresetResolver $presetResolver)
    {
    }

    /**
     * @inheritDoc
     */
    public function createResponse(
        string $rootPath,
        string $requestPath,
        array $queryParams,
        array $presets,
        string $cachePath,
        array $options = [],
    ): ?ResponseInterface {
        $resolvedCachedPath = $this->createTransformedPath(
            rootPath: $rootPath,
            requestPath: $requestPath,
            queryParams: $queryParams,
            presets: $presets,
            cachePath: $cachePath,
            options: $options,
        );

        $content = is_string($resolvedCachedPath)
            ? file_get_contents($resolvedCachedPath)
            : false;
        if (!is_string($content)) {
            return null;
        }

        return (new Response(['charset' => 'UTF-8']))
            ->withStatus(200)
            ->withHeader('Content-Type', MimeType::getMimeTypeForFile($resolvedCachedPath))
            ->withHeader('Cache-Control', 'max-age=31536000, public')
            ->withStringBody($content);
    }

    /**
     * Create transformed image and return absolute cached file path.
     *
     * @param string $rootPath Source image root path.
     * @param string $requestPath Request URI path.
     * @param array<string, mixed> $queryParams Request query parameters.
     * @param array<string, array<string, string>> $presets Configured preset map.
     * @param string $cachePath Absolute cache directory path.
     * @param array<string, string> $options Optional Glide server options.
     */
    public function createTransformedPath(
        string $rootPath,
        string $requestPath,
        array $queryParams,
        array $presets,
        string $cachePath,
        array $options = [],
    ): ?string {
        $manipulations = $this->presetResolver->resolve($queryParams, $presets);
        if ($manipulations === []) {
            return null;
        }

        $relativePath = $this->toRelativeRequestPath($requestPath);
        if ($relativePath === null || $this->isUnsafeRelativePath($relativePath)) {
            return null;
        }

        $this->ensureDirectory($cachePath);

        try {
            $server = ServerFactory::create($this->buildServerConfiguration($rootPath, $cachePath, $options));
            $cachedRelativePath = $server->makeImage($relativePath, $manipulations);
        } catch (Throwable) {
            return null;
        }

        $resolvedCachedPath = $this->resolveCachedPath($cachePath, $cachedRelativePath);

        return is_string($resolvedCachedPath) ? $resolvedCachedPath : null;
    }

    /**
     * Build Glide server configuration from defaults and optional overrides.
     *
     * @param string $rootPath Source image root path.
     * @param string $cachePath Absolute cache directory path.
     * @param array<string, string> $options Optional Glide server options.
     * @return array<string, string>
     */
    protected function buildServerConfiguration(string $rootPath, string $cachePath, array $options): array
    {
        $configuration = [
            'source' => $rootPath,
            'cache' => $cachePath,
            'driver' => 'gd',
        ];

        $driver = $this->normalizeDriverOption($options['driver'] ?? null);
        if ($driver !== null) {
            $configuration['driver'] = $driver;
        }

        return $configuration;
    }

    /**
     * Normalize configured Glide driver value.
     *
     * @param string|null $driver Raw configured Glide driver.
     */
    protected function normalizeDriverOption(?string $driver): ?string
    {
        if (!is_string($driver)) {
            return null;
        }

        $normalized = strtolower(trim($driver));
        if (!in_array($normalized, ['gd', 'imagick'], true)) {
            return null;
        }

        return $normalized;
    }

    /**
     * Normalize request URI path into a relative source path.
     *
     * @param string $requestPath Request URI path.
     */
    protected function toRelativeRequestPath(string $requestPath): ?string
    {
        $relativePath = ltrim(rawurldecode($requestPath), '/');

        return $relativePath === '' ? null : $relativePath;
    }

    /**
     * Check for unsafe relative path segments.
     *
     * @param string $relativePath Relative image path.
     */
    protected function isUnsafeRelativePath(string $relativePath): bool
    {
        return str_contains($relativePath, '..' . DIRECTORY_SEPARATOR)
            || str_contains($relativePath, '../')
            || str_contains($relativePath, '..\\')
            || str_starts_with($relativePath, '..');
    }

    /**
     * Ensure cache directory exists.
     *
     * @param string $directory Cache directory path.
     */
    protected function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        mkdir($directory, 0755, true);
    }

    /**
     * Resolve and validate cached file path under cache root.
     *
     * @param string $cacheRoot Absolute cache root directory.
     * @param string $cachedRelativePath Cache file path returned by Glide.
     */
    protected function resolveCachedPath(string $cacheRoot, string $cachedRelativePath): ?string
    {
        $cacheRootRealPath = realpath($cacheRoot);
        if (!is_string($cacheRootRealPath)) {
            return null;
        }

        $candidatePath = $cacheRootRealPath
            . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $cachedRelativePath);
        $resolvedPath = realpath($candidatePath);
        if (!is_string($resolvedPath) || !is_file($resolvedPath)) {
            return null;
        }

        $cacheRootPrefix = rtrim($cacheRootRealPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (!str_starts_with($resolvedPath, $cacheRootPrefix)) {
            return null;
        }

        return $resolvedPath;
    }
}
