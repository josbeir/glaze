<?php
declare(strict_types=1);

namespace Glaze\Support;

use Cake\Utility\Hash;

/**
 * Provides dotted metadata access helpers for value objects.
 */
trait HasDottedMetadataAccessTrait
{
    /**
     * Read metadata using dotted path access.
     *
     * @param string $path Dotted metadata path.
     * @param mixed $default Default value when path does not exist.
     */
    public function meta(string $path, mixed $default = null): mixed
    {
        $metadata = $this->metadataMap();
        if (trim($path) === '') {
            return $metadata;
        }

        return Hash::get($metadata, $path, $default);
    }

    /**
     * Check whether metadata exists at a dotted path.
     *
     * @param string $path Dotted metadata path.
     */
    public function hasMeta(string $path): bool
    {
        $metadata = $this->metadataMap();
        if (trim($path) === '') {
            return $metadata !== [];
        }

        return Hash::check($metadata, $path);
    }

    /**
     * Return metadata map consumed by dotted access helpers.
     *
     * @return array<string, mixed>
     */
    abstract protected function metadataMap(): array;
}
