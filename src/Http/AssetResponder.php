<?php
declare(strict_types=1);

namespace Glaze\Http;

use Cake\Http\MimeType;
use Cake\Http\Response;
use RuntimeException;

/**
 * Creates HTTP responses for file assets within a configured root.
 */
final class AssetResponder
{
    /**
     * Build a response for a request path if a matching file exists and is allowed.
     *
     * @param string $rootPath Absolute root directory to resolve files from.
     * @param string $requestPath Request URI path.
     * @param bool $allowDjot Whether .dj source files may be served.
     */
    public function createFileResponse(string $rootPath, string $requestPath, bool $allowDjot = false): ?Response
    {
        $resolvedPath = $this->resolveFilePath($rootPath, $requestPath);
        if (!is_string($resolvedPath)) {
            return null;
        }

        if (!$allowDjot && strtolower(pathinfo($resolvedPath, PATHINFO_EXTENSION)) === 'dj') {
            return null;
        }

        $content = file_get_contents($resolvedPath);
        if ($content === false) {
            throw new RuntimeException(sprintf('Unable to read asset file "%s".', $resolvedPath));
        }

        return (new Response(['charset' => 'UTF-8']))
            ->withStatus(200)
            ->withHeader('Content-Type', MimeType::getMimeTypeForFile($resolvedPath))
            ->withStringBody($content);
    }

    /**
     * Resolve request path to a safe file path under the root directory.
     *
     * @param string $rootPath Absolute root directory.
     * @param string $requestPath Request URI path.
     */
    protected function resolveFilePath(string $rootPath, string $requestPath): ?string
    {
        $relativePath = ltrim(rawurldecode($requestPath), '/');
        if ($relativePath === '') {
            return null;
        }

        $rootRealPath = realpath($rootPath);
        if (!is_string($rootRealPath)) {
            return null;
        }

        $candidate = $rootRealPath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $resolved = realpath($candidate);
        if (!is_string($resolved) || !is_file($resolved)) {
            return null;
        }

        $rootPrefix = rtrim($rootRealPath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (!str_starts_with($resolved, $rootPrefix)) {
            return null;
        }

        return $resolved;
    }
}
