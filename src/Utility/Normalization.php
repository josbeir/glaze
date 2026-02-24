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
     * Normalize list-like values to a trimmed string list.
     *
     * Non-string items and empty strings are ignored.
     *
     * @param mixed $value Raw input value.
     * @return array<string>
     */
    public static function stringList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $item) {
            if (!is_string($item)) {
                continue;
            }

            $normalizedItem = trim($item);
            if ($normalizedItem === '') {
                continue;
            }

            $normalized[] = $normalizedItem;
        }

        return $normalized;
    }
}
