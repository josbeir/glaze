<?php
declare(strict_types=1);

namespace Glaze\Config;

use Glaze\Utility\Normalization;

/**
 * Immutable configuration for a single language variant in a multi-language site.
 *
 * Each language specifies a human-readable label, an optional URL prefix used
 * to namespace content under a path segment (e.g. `/nl/`), and an optional
 * content directory path that overrides the project-level content path.
 * When `contentDir` is null the global content directory is used, which is
 * the recommended setup for the default/primary language.
 *
 * Example NEON configuration:
 *
 * ```neon
 * i18n:
 *   defaultLanguage: en
 *   languages:
 *     en:
 *       label: English
 *       urlPrefix: ""
 *     nl:
 *       label: Nederlands
 *       urlPrefix: nl
 *       contentDir: content/nl
 * ```
 */
final class LanguageConfig
{
    /**
     * Constructor.
     *
     * @param string $code Normalized language code (e.g. `en`, `nl`, `fr`).
     * @param string $label Human-readable language label shown in switchers.
     * @param string $urlPrefix URL path prefix for this language (empty string for the default language at root).
     * @param string|null $contentDir Relative or absolute content directory for this language. Null uses the project-level content dir.
     */
    public function __construct(
        public readonly string $code,
        public readonly string $label = '',
        public readonly string $urlPrefix = '',
        public readonly ?string $contentDir = null,
    ) {
    }

    /**
     * Create a LanguageConfig from a raw decoded language map entry.
     *
     * @param string $code Language code key from the `i18n.languages` map.
     * @param mixed $value Raw decoded language configuration value.
     */
    public static function fromConfig(string $code, mixed $value): self
    {
        if (!is_array($value)) {
            return new self(code: $code);
        }

        $label = Normalization::optionalString($value['label'] ?? null) ?? '';
        $urlPrefix = self::normalizeUrlPrefix($value['urlPrefix'] ?? null);
        $contentDir = Normalization::optionalString($value['contentDir'] ?? null);

        return new self(
            code: $code,
            label: $label,
            urlPrefix: $urlPrefix,
            contentDir: $contentDir,
        );
    }

    /**
     * Return whether this language uses a URL prefix.
     *
     * The primary/default language is typically configured with an empty prefix
     * so that its content lives at the site root (e.g. `/about/`). Secondary
     * languages use a non-empty prefix (e.g. `nl` → `/nl/about/`).
     */
    public function hasUrlPrefix(): bool
    {
        return $this->urlPrefix !== '';
    }

    /**
     * Normalize and trim the raw URL prefix value.
     *
     * Strips leading and trailing slashes so the stored value is a plain
     * segment name (e.g. `nl`, `fr-be`) and treats empty/null inputs as
     * an empty string (no prefix).
     *
     * @param mixed $value Raw URL prefix input.
     */
    private static function normalizeUrlPrefix(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }

        return trim($value, '/');
    }
}
