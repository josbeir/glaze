<?php
declare(strict_types=1);

namespace Glaze\Config;

use Cake\Utility\Hash;
use Glaze\Support\HasDottedMetadataAccessTrait;
use Glaze\Utility\Normalization;

/**
 * Immutable site-wide configuration loaded from project config.
 */
final class SiteConfig
{
    use HasDottedMetadataAccessTrait;

    /**
     * Reserved keys consumed by typed site configuration fields.
     *
     * @var array<string>
     */
    protected const RESERVED_SITE_KEYS = [
        'title',
        'description',
        'baseUrl',
        'basePath',
        'meta',
    ];

    /**
     * Constructor.
     *
     * @param string|null $title Site title.
     * @param string|null $description Default site description.
     * @param string|null $baseUrl Canonical site base URL.
     * @param string|null $basePath Site base path/prefix (for example `/docs`).
     * @param array<string, mixed> $meta Site metadata payload.
     */
    public function __construct(
        public readonly ?string $title = null,
        public readonly ?string $description = null,
        public readonly ?string $baseUrl = null,
        public readonly ?string $basePath = null,
        public readonly array $meta = [],
    ) {
    }

    /**
     * Create site config from decoded project config input.
     *
     * @param mixed $value Decoded `site` config value.
     */
    public static function fromProjectConfig(mixed $value): self
    {
        if (!is_array($value)) {
            return new self();
        }

        return new self(
            title: Normalization::optionalString($value['title'] ?? null),
            description: Normalization::optionalString($value['description'] ?? null),
            baseUrl: Normalization::optionalString($value['baseUrl'] ?? null),
            basePath: self::normalizeBasePath($value['basePath'] ?? null),
            meta: self::normalizeSiteMetadata($value),
        );
    }

    /**
     * Convert to template-friendly array.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'title' => $this->title,
            'description' => $this->description,
            'baseUrl' => $this->baseUrl,
            'basePath' => $this->basePath,
            'meta' => $this->meta,
        ];
    }

    /**
     * Alias for metadata access that reads from site config payload.
     *
     * @param string $path Dotted metadata path.
     * @param mixed $default Default value when path does not exist.
     */
    public function siteMeta(string $path, mixed $default = null): mixed
    {
        if (trim($path) === '') {
            return $this->meta;
        }

        return Hash::get($this->meta, $path, $default);
    }

    /**
     * Alias for metadata existence checks in site config payload.
     *
     * @param string $path Dotted metadata path.
     */
    public function hasSiteMeta(string $path): bool
    {
        if (trim($path) === '') {
            return $this->meta !== [];
        }

        return Hash::check($this->meta, $path);
    }

    /**
     * Return metadata map consumed by dotted access helpers.
     *
     * @return array<string, mixed>
     */
    protected function metadataMap(): array
    {
        return array_replace($this->meta, $this->toArray());
    }

    /**
     * Normalize optional base path values.
     *
     * @param mixed $value Raw input value.
     */
    protected static function normalizeBasePath(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $normalized = trim($value);
        if ($normalized === '') {
            return null;
        }

        $normalized = '/' . trim($normalized, '/');
        if ($normalized === '/') {
            return null;
        }

        return $normalized;
    }

    /**
     * Normalize non-reserved custom site metadata.
     *
     * @param array<mixed> $value Raw decoded site configuration map.
     * @return array<string, mixed>
     */
    protected static function normalizeSiteMetadata(array $value): array
    {
        $normalized = self::normalizeRootSiteMetadata($value);
        $metaMap = Normalization::stringMap($value['meta'] ?? null);

        return array_replace($normalized, $metaMap);
    }

    /**
     * Normalize non-reserved root site metadata.
     *
     * @param array<mixed> $value Raw decoded site configuration map.
     * @return array<string, mixed>
     */
    protected static function normalizeRootSiteMetadata(array $value): array
    {
        $normalized = [];

        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                continue;
            }

            $normalizedKey = trim($key);
            if ($normalizedKey === '') {
                continue;
            }

            if (in_array($normalizedKey, self::RESERVED_SITE_KEYS, true)) {
                continue;
            }

            $normalizedValue = self::normalizeSiteMetadataValue($item);
            if (
                !is_scalar($normalizedValue)
                && $normalizedValue !== null
                && !is_array($normalizedValue)
            ) {
                continue;
            }

            $normalized[$normalizedKey] = $normalizedValue;
        }

        return $normalized;
    }

    /**
     * Normalize custom site metadata value recursively.
     *
     * @param mixed $value Raw custom site value.
     */
    protected static function normalizeSiteMetadataValue(mixed $value): mixed
    {
        if (is_scalar($value) || $value === null) {
            return $value;
        }

        if (is_array($value)) {
            return Normalization::normalizeNestedArray(
                value: $value,
                normalizeListItem: static fn(mixed $item): mixed => self::normalizeSiteMetadataValue($item),
                normalizeMapItem: static fn(string $key, mixed $item): mixed => self::normalizeSiteMetadataValue($item),
            );
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return (string)$value;
        }

        return null;
    }
}
