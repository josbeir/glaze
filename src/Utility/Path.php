<?php
declare(strict_types=1);

namespace Glaze\Utility;

/**
 * Shared helpers for path normalization and path-shape utilities.
 */
final class Path
{
    /**
     * Normalize separators and strip trailing directory separators.
     *
     * @param string $value Raw path value.
     */
    public static function normalize(string $value): string
    {
        $normalized = str_replace('\\', '/', $value);

        return rtrim($normalized, '/');
    }

    /**
     * Determine whether a path is absolute on Unix, UNC, or Windows drive formats.
     *
     * @param string $value Path to inspect.
     */
    public static function isAbsolute(string $value): bool
    {
        if ($value === '') {
            return false;
        }

        $normalized = self::normalize($value);

        if (str_starts_with($normalized, '//') || str_starts_with($normalized, '/')) {
            return true;
        }

        return preg_match('/^[A-Za-z]:\//', $normalized) === 1;
    }

    /**
     * Resolve a path value against a base path.
     *
     * Absolute values are normalized and returned unchanged. Relative values
     * are joined to the provided base path.
     *
     * @param string $base Base directory path.
     * @param string $value Relative or absolute path value.
     */
    public static function resolve(string $base, string $value): string
    {
        if (self::isAbsolute($value)) {
            return self::normalize($value);
        }

        return self::normalize(
            self::normalize($base) . '/' . ltrim($value, '/'),
        );
    }

    /**
     * Normalize a path into an internal lookup key.
     *
     * Produces lowercase, forward-slash-separated values without leading or
     * trailing slashes, independent of platform directory separators.
     *
     * @param string $value Raw path value.
     */
    public static function key(string $value): string
    {
        return strtolower(trim(self::normalize($value), '/'));
    }

    /**
     * Normalize relative segments by resolving `.` and `..` markers.
     *
     * Prevents traversal above the virtual root by dropping unmatched
     * parent-directory markers.
     *
     * @param string $value Relative path-like value.
     */
    public static function normalizeSegments(string $value): string
    {
        $segments = explode('/', self::normalize($value));
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
     * Normalize an optional path-like value.
     *
     * Returns null for non-string or empty-string input.
     *
     * @param mixed $value Raw path value.
     */
    public static function optional(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $normalized = trim($value);
        if ($normalized === '') {
            return null;
        }

        return self::normalize($normalized);
    }

    /**
     * Normalize a slash-trimmed path fragment.
     *
     * Keeps original character case while converting separators to `/` and
     * stripping leading/trailing slashes.
     *
     * @param string $value Raw path value.
     */
    public static function fragment(string $value): string
    {
        return trim(self::normalize($value), '/');
    }

    /**
     * Normalize an optional path fragment value.
     *
     * Returns null for non-string, empty, or slash-only values.
     *
     * @param mixed $value Raw path value.
     */
    public static function optionalFragment(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $normalized = trim($value);
        if ($normalized === '') {
            return null;
        }

        $fragment = self::fragment($normalized);

        return $fragment === '' ? null : $fragment;
    }
}
