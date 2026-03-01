<?php
declare(strict_types=1);

namespace Glaze\Utility;

/**
 * Shared hashing helpers used across build and rendering workflows.
 */
final class Hash
{
    /**
     * Default hash algorithm used across the project.
     */
    public const ALGORITHM = 'xxh3';

    /**
     * Create a hash for a string payload.
     *
     * @param string $value Input value to hash.
     */
    public static function make(string $value): string
    {
        return hash(self::ALGORITHM, $value);
    }

    /**
     * Create a hash from multiple ordered parts.
     *
     * Parts are joined with a pipe character before hashing.
     *
     * @param array<string> $parts Ordered hash input fragments.
     */
    public static function makeFromParts(array $parts): string
    {
        return self::make(implode('|', $parts));
    }
}
