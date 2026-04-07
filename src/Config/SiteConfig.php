<?php
declare(strict_types=1);

namespace Glaze\Config;

use Cake\Utility\Hash;
use Glaze\Support\HasDottedMetadataAccessTrait;
use Glaze\Utility\Normalization;

/**
 * Immutable site-wide configuration loaded from project config.
 *
 * Typed fields (title, description, baseUrl, basePath) are extracted from the
 * `site` block in glaze.neon. All remaining non-reserved keys are collected
 * into `$meta` together with any explicit `meta` sub-map, providing flexible
 * arbitrary metadata access via dotted-path helpers.
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
     * Non-reserved root-level keys are merged into `$meta` alongside any
     * values from the explicit `meta` sub-map (which takes precedence).
     * String values in the `meta` sub-map are trimmed via {@see Normalization::stringMap()}.
     *
     * @param mixed $value Decoded `site` config value.
     */
    public static function fromProjectConfig(mixed $value): self
    {
        if (!is_array($value)) {
            return new self();
        }

        return new self(
            title: is_string($value['title'] ?? null) && trim($value['title']) !== ''
                ? trim($value['title'])
                : null,
            description: is_string($value['description'] ?? null) && trim($value['description']) !== ''
                ? trim($value['description'])
                : null,
            baseUrl: is_string($value['baseUrl'] ?? null) && trim($value['baseUrl']) !== ''
                ? trim($value['baseUrl'])
                : null,
            basePath: self::normalizeBasePath($value['basePath'] ?? null),
            meta: self::extractMeta($value),
        );
    }

    /**
     * Return a new SiteConfig with per-language overrides merged on top of this instance.
     *
     * Only the keys present in `$overrides` are applied; all other properties
     * fall back to the values of the current instance. Typed scalar fields
     * (`title`, `description`, `baseUrl`, `basePath`) are overridden when the
     * override map provides a non-empty string value. The `meta` bag is
     * deep-merged with `array_replace_recursive` so nested structures (e.g.
     * `hero`, `nav`) are handled correctly.
     *
     * This is called by the render pipeline when building a page that belongs
     * to a language which has a `site` override block in its `LanguageConfig`.
     *
     * @param array<string, mixed> $overrides Raw per-language site override map (the `site` block
     *   from a `LanguageConfig` entry).
     */
    public function withLanguageOverrides(array $overrides): self
    {
        if ($overrides === []) {
            return $this;
        }

        $overrideConfig = self::fromProjectConfig($overrides);

        /** @var array<string, mixed> $mergedMeta */
        $mergedMeta = array_replace_recursive($this->meta, $overrideConfig->meta);

        return new self(
            title: $overrideConfig->title ?? $this->title,
            description: $overrideConfig->description ?? $this->description,
            baseUrl: $overrideConfig->baseUrl ?? $this->baseUrl,
            basePath: $overrideConfig->basePath ?? $this->basePath,
            meta: $mergedMeta,
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
     * Extract the site metadata payload from a raw site config map.
     *
     * Non-reserved root-level string keys are collected first; the explicit
     * `meta` sub-map (filtered to scalar string values via stringMap) is
     * merged on top so it takes precedence.
     *
     * @param array<mixed> $value Raw decoded site configuration map.
     * @return array<string, mixed>
     */
    private static function extractMeta(array $value): array
    {
        $meta = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                continue;
            }

            if ($key === '') {
                continue;
            }

            if (in_array($key, self::RESERVED_SITE_KEYS, true)) {
                continue;
            }

            $meta[$key] = $item;
        }

        return array_replace($meta, Normalization::stringMap($value['meta'] ?? null));
    }

    /**
     * Normalize optional base path values.
     *
     * Ensures a leading slash, strips trailing slashes, and returns null for
     * blank, root-only, or non-string input.
     *
     * @param mixed $value Raw input value.
     */
    private static function normalizeBasePath(mixed $value): ?string
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
}
