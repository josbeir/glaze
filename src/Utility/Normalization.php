<?php
declare(strict_types=1);

namespace Glaze\Utility;

/**
 * Shared normalization helpers for decoded configuration and metadata values.
 */
final class Normalization
{
    /**
     * Normalize optional string values.
     *
     * Returns null for non-string or empty-string input.
     *
     * @param mixed $value Raw input value.
     */
    public static function optionalString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * Normalize scalar values to optional strings.
     *
     * Returns null for non-scalar input or empty normalized values.
     *
     * @param mixed $value Raw input value.
     */
    public static function optionalScalarString(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $normalized = trim((string)$value);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * Normalize map-like values to a string-keyed string map.
     *
     * Non-string keys, empty keys, and non-scalar values are ignored.
     *
     * @param mixed $value Raw input value.
     * @return array<string, string>
     */
    public static function stringMap(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                continue;
            }

            $normalizedKey = trim($key);
            if ($normalizedKey === '') {
                continue;
            }

            if (!is_scalar($item)) {
                continue;
            }

            $normalized[$normalizedKey] = trim((string)$item);
        }

        return $normalized;
    }

    /**
     * Normalize path separators and strip trailing directory separators.
     *
     * @param string $path Raw path value.
     */
    public static function path(string $path): string
    {
        $normalized = str_replace('\\', '/', $path);

        return rtrim($normalized, '/');
    }

    /**
     * Normalize path-like values for internal lookup keys.
     *
     * Produces lowercase, forward-slash-separated values without leading or
     * trailing slashes, independent of platform directory separators.
     *
     * @param string $path Raw path value.
     */
    public static function pathKey(string $path): string
    {
        return strtolower(trim(self::path($path), '/'));
    }

    /**
     * Normalize relative path segments by resolving `.` and `..` markers.
     *
     * Uses forward slashes in the resulting path and prevents traversal above
     * the virtual root by dropping unmatched parent-directory markers.
     *
     * @param string $path Relative path value.
     */
    public static function normalizePathSegments(string $path): string
    {
        $segments = explode('/', self::path($path));
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

    /**
     * Normalize optional path values.
     *
     * Returns null for non-string or empty-string input.
     *
     * @param mixed $path Raw path value.
     */
    public static function optionalPath(mixed $path): ?string
    {
        $normalized = self::optionalString($path);
        if ($normalized === null) {
            return null;
        }

        return self::path($normalized);
    }

    /**
     * Normalize a path-like value into a slash-trimmed fragment.
     *
     * Keeps original character case while converting separators to `/` and
     * stripping leading/trailing slashes.
     *
     * @param string $path Raw path value.
     */
    public static function pathFragment(string $path): string
    {
        return trim(self::path($path), '/');
    }

    /**
     * Normalize optional path fragment values.
     *
     * Returns null for non-string, empty, or slash-only values.
     *
     * @param mixed $path Raw path value.
     */
    public static function optionalPathFragment(mixed $path): ?string
    {
        $normalized = self::optionalString($path);
        if ($normalized === null) {
            return null;
        }

        $fragment = self::pathFragment($normalized);

        return $fragment === '' ? null : $fragment;
    }
}
