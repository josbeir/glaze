<?php
declare(strict_types=1);

namespace Glaze\Support;

use Glaze\Config\SiteConfig;

/**
 * Rewrites internal resource paths across Djot and Sugar rendering pipelines.
 */
final class ResourcePathRewriter
{
    /**
     * Rewrite a Djot-emitted resource path against page-relative content paths.
     *
     * @param string $resourcePath Resource path from Djot output.
     * @param string $relativePagePath Relative source page path.
     * @param \Glaze\Config\SiteConfig $siteConfig Site configuration.
     */
    public function rewriteDjotResourcePath(
        string $resourcePath,
        string $relativePagePath,
        SiteConfig $siteConfig,
    ): string {
        if ($resourcePath === '' || $this->isExternalResourcePath($resourcePath)) {
            return $resourcePath;
        }

        $internalPath = str_starts_with($resourcePath, '/')
            ? $resourcePath
            : $this->toContentAbsoluteResourcePath($resourcePath, $relativePagePath);

        return $this->applyBasePathToPath($internalPath, $siteConfig);
    }

    /**
     * Rewrite template static resource paths where only base-path prefixing is required.
     *
     * @param string $resourcePath Resource path from template attribute values.
     * @param \Glaze\Config\SiteConfig $siteConfig Site configuration.
     */
    public function rewriteTemplateResourcePath(string $resourcePath, SiteConfig $siteConfig): string
    {
        if ($resourcePath === '' || $this->isExternalResourcePath($resourcePath)) {
            return $resourcePath;
        }

        if (!str_starts_with($resourcePath, '/')) {
            return $resourcePath;
        }

        return $this->applyBasePathToPath($resourcePath, $siteConfig);
    }

    /**
     * Strip configured base path from internal request paths.
     *
     * @param string $path Internal path.
     * @param \Glaze\Config\SiteConfig $siteConfig Site configuration.
     */
    public function stripBasePathFromPath(string $path, SiteConfig $siteConfig): string
    {
        $basePath = $siteConfig->basePath;
        $normalizedPath = '/' . ltrim($path, '/');

        if ($basePath === null || $basePath === '') {
            return $normalizedPath;
        }

        if ($normalizedPath === $basePath) {
            return '/';
        }

        if (str_starts_with($normalizedPath, $basePath . '/')) {
            return substr($normalizedPath, strlen($basePath)) ?: '/';
        }

        return $normalizedPath;
    }

    /**
     * Apply configured base path to an internal URL path.
     *
     * @param string $path Internal URL path.
     * @param \Glaze\Config\SiteConfig $siteConfig Site configuration.
     */
    public function applyBasePathToPath(string $path, SiteConfig $siteConfig): string
    {
        $basePath = $siteConfig->basePath;
        if ($basePath === null || $basePath === '') {
            return $path;
        }

        preg_match('/^([^?#]*)(.*)$/', $path, $parts);
        $pathPart = $parts[1] ?? $path;
        $suffix = $parts[2] ?? '';

        $normalizedPath = '/' . ltrim($pathPart, '/');
        if ($normalizedPath === '//') {
            $normalizedPath = '/';
        }

        if ($normalizedPath === '/') {
            return $basePath . '/' . $suffix;
        }

        if ($normalizedPath === $basePath || str_starts_with($normalizedPath, $basePath . '/')) {
            return $normalizedPath . $suffix;
        }

        return rtrim($basePath, '/') . $normalizedPath . $suffix;
    }

    /**
     * Convert a relative resource path to a content-root absolute path.
     *
     * @param string $resourcePath Resource path from content.
     * @param string $relativePagePath Relative source page path.
     */
    public function toContentAbsoluteResourcePath(string $resourcePath, string $relativePagePath): string
    {
        if ($this->isAbsoluteResourcePath($resourcePath)) {
            return $resourcePath;
        }

        preg_match('/^([^?#]*)(.*)$/', $resourcePath, $parts);
        $pathPart = $parts[1] ?? $resourcePath;
        $suffix = $parts[2] ?? '';

        $baseDirectory = dirname(str_replace('\\', '/', $relativePagePath));
        $baseDirectory = $baseDirectory === '.' ? '' : trim($baseDirectory, '/');

        $combinedPath = ($baseDirectory !== '' ? $baseDirectory . '/' : '') . ltrim($pathPart, '/');
        $normalizedPath = $this->normalizePathSegments($combinedPath);

        return '/' . ltrim($normalizedPath, '/') . $suffix;
    }

    /**
     * Detect whether path points to external or non-rewritable resources.
     *
     * @param string $resourcePath Resource path.
     */
    public function isExternalResourcePath(string $resourcePath): bool
    {
        if ($resourcePath === '') {
            return true;
        }

        if (str_starts_with($resourcePath, '#')) {
            return true;
        }

        if (str_starts_with($resourcePath, '//')) {
            return true;
        }

        return preg_match('/^[a-z][a-z0-9+.-]*:/i', $resourcePath) === 1;
    }

    /**
     * Detect whether path is already absolute.
     *
     * @param string $resourcePath Resource path.
     */
    public function isAbsoluteResourcePath(string $resourcePath): bool
    {
        if ($resourcePath === '') {
            return true;
        }

        if (str_starts_with($resourcePath, '/')) {
            return true;
        }

        if (str_starts_with($resourcePath, '#')) {
            return true;
        }

        if (str_starts_with($resourcePath, '//')) {
            return true;
        }

        return preg_match('/^[a-z][a-z0-9+.-]*:/i', $resourcePath) === 1;
    }

    /**
     * Normalize `.` and `..` segments.
     *
     * @param string $path Relative path.
     */
    public function normalizePathSegments(string $path): string
    {
        $segments = explode('/', str_replace('\\', '/', $path));
        $normalized = [];

        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }

            if ($segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($normalized);
                continue;
            }

            $normalized[] = $segment;
        }

        return implode('/', $normalized);
    }
}
