<?php
declare(strict_types=1);

namespace Glaze\Config;

/**
 * Named cache subpaths used by build and runtime services.
 */
enum CachePath: string
{
    case Sugar = 'sugar';
    case Glide = 'glide';
    case PhikiHtml = 'phiki-html';
    case BuildManifest = 'build-manifest.json';

    /**
     * Human-readable cache label used by CLI output.
     */
    public function label(): string
    {
        return match ($this) {
            self::Sugar => 'Templates',
            self::PhikiHtml => 'Phiki',
            self::Glide => 'Images',
            self::BuildManifest => 'Build',
        };
    }

    /**
     * Whether this cache target is represented as a single file.
     */
    public function isFileTarget(): bool
    {
        return $this === self::BuildManifest;
    }

    /**
     * Determine whether this cache target should be cleared for the given command flags.
     *
     * @param bool $clearBoth Whether no selective flags were provided.
     * @param bool $templatesOnly Whether only template-related caches should be cleared.
     * @param bool $imagesOnly Whether only image-related caches should be cleared.
     */
    public function shouldClear(bool $clearBoth, bool $templatesOnly, bool $imagesOnly): bool
    {
        return match ($this) {
            self::Sugar, self::PhikiHtml => $clearBoth || $templatesOnly,
            self::Glide => $clearBoth || $imagesOnly,
            self::BuildManifest => $clearBoth,
        };
    }
}
