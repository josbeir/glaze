<?php
declare(strict_types=1);

namespace Glaze\Content;

use Glaze\Utility\Normalization;

/**
 * Value object representing a discoverable non-Djot content asset.
 */
final class ContentAsset
{
    /**
     * Supported image file extensions for `isImage()` checks.
     *
     * @var list<string>
     */
    protected const IMAGE_EXTENSIONS = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'avif'];

    /**
     * Constructor.
     *
     * @param string $relativePath Asset path relative to content root.
     * @param string $urlPath Public URL path for templates.
     * @param string $absolutePath Absolute filesystem path.
     * @param string $filename File basename.
     * @param string $extension Lowercased extension without leading dot.
     * @param int $size File size in bytes.
     */
    public function __construct(
        public readonly string $relativePath,
        public readonly string $urlPath,
        public readonly string $absolutePath,
        public readonly string $filename,
        public readonly string $extension,
        public readonly int $size,
    ) {
    }

    /**
     * Check whether asset extension matches any provided extension.
     *
     * @param string ...$extensions Extension names with or without leading dots.
     */
    public function is(string ...$extensions): bool
    {
        if ($extensions === []) {
            return false;
        }

        $normalized = [];
        foreach ($extensions as $extension) {
            $fragment = Normalization::optionalPathFragment($extension);
            if ($fragment === null) {
                continue;
            }

            $normalized[] = strtolower(ltrim($fragment, '.'));
        }

        if ($normalized === []) {
            return false;
        }

        return in_array($this->extension, $normalized, true);
    }

    /**
     * Check whether this asset is an image by extension.
     */
    public function isImage(): bool
    {
        return in_array($this->extension, self::IMAGE_EXTENSIONS, true);
    }
}
