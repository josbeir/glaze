<?php
declare(strict_types=1);

namespace Glaze\Config;

/**
 * Immutable site-wide configuration loaded from project config.
 */
final class SiteConfig
{
    /**
     * Constructor.
     *
     * @param string|null $title Site title.
     * @param string|null $description Default site description.
     * @param string|null $baseUrl Canonical site base URL.
     * @param string|null $basePath Site base path/prefix (for example `/docs`).
     * @param array<string, string> $metaDefaults Default meta tags.
     */
    public function __construct(
        public readonly ?string $title = null,
        public readonly ?string $description = null,
        public readonly ?string $baseUrl = null,
        public readonly ?string $basePath = null,
        public readonly array $metaDefaults = [],
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
            title: self::normalizeString($value['title'] ?? null),
            description: self::normalizeString($value['description'] ?? null),
            baseUrl: self::normalizeString($value['baseUrl'] ?? null),
            basePath: self::normalizeBasePath($value['basePath'] ?? null),
            metaDefaults: self::normalizeMetaMap($value['metaDefaults'] ?? null),
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
            'metaDefaults' => $this->metaDefaults,
        ];
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
     * Normalize optional string values.
     *
     * @param mixed $value Raw input value.
     */
    protected static function normalizeString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * Normalize map-like meta config values.
     *
     * @param mixed $value Raw input value.
     * @return array<string, string>
     */
    protected static function normalizeMetaMap(mixed $value): array
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
}
