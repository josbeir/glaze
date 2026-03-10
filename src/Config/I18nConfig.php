<?php
declare(strict_types=1);

namespace Glaze\Config;

use Glaze\Utility\Normalization;

/**
 * Immutable i18n (internationalization) configuration for a Glaze project.
 *
 * This config object is populated from the `i18n` block in `glaze.neon`.
 * When no `defaultLanguage` is set the entire subsystem is considered
 * disabled and all methods return safe null/empty defaults — making it
 * completely zero-cost for single-language sites.
 *
 * Example NEON configuration:
 *
 * ```neon
 * i18n:
 *   defaultLanguage: en
 *   translationsDir: i18n
 *   languages:
 *     en:
 *       label: English
 *       urlPrefix: ""
 *     nl:
 *       label: Nederlands
 *       urlPrefix: nl
 *       contentDir: content/nl
 *     fr:
 *       label: Français
 *       urlPrefix: fr
 *       contentDir: content/fr
 * ```
 */
final class I18nConfig
{
    /**
     * Constructor.
     *
     * @param string|null $defaultLanguage Primary language code. When null, i18n is disabled.
     * @param array<string, \Glaze\Config\LanguageConfig> $languages Language definitions keyed by language code.
     * @param string $translationsDir Directory (relative to project root) containing NEON translation files.
     */
    public function __construct(
        public readonly ?string $defaultLanguage,
        public readonly array $languages,
        public readonly string $translationsDir = 'i18n',
    ) {
    }

    /**
     * Create an I18nConfig from decoded project config input.
     *
     * Returns a disabled instance (null defaultLanguage, empty languages) when the
     * `i18n` block is absent or does not specify a valid `defaultLanguage`.
     *
     * @param mixed $value Decoded `i18n` config value.
     */
    public static function fromProjectConfig(mixed $value): self
    {
        if (!is_array($value)) {
            return new self(null, []);
        }

        $defaultLanguage = Normalization::optionalString($value['defaultLanguage'] ?? null);
        if ($defaultLanguage === null) {
            return new self(null, []);
        }

        $translationsDir = Normalization::optionalString($value['translationsDir'] ?? null) ?? 'i18n';

        $languages = [];
        $rawLanguages = $value['languages'] ?? null;
        if (is_array($rawLanguages)) {
            foreach ($rawLanguages as $code => $langValue) {
                if (!is_string($code)) {
                    continue;
                }

                if (trim($code) === '') {
                    continue;
                }

                $normalizedCode = trim($code);
                $languages[$normalizedCode] = LanguageConfig::fromConfig($normalizedCode, $langValue);
            }
        }

        // Ensure the default language always has an entry.
        if (!isset($languages[$defaultLanguage])) {
            $languages[$defaultLanguage] = new LanguageConfig(code: $defaultLanguage);
        }

        return new self($defaultLanguage, $languages, $translationsDir);
    }

    /**
     * Return whether i18n support is enabled.
     *
     * I18n is considered enabled when a `defaultLanguage` is configured.
     */
    public function isEnabled(): bool
    {
        return $this->defaultLanguage !== null;
    }

    /**
     * Return the language config for the given code, or null when not found.
     *
     * @param string $code Language code.
     */
    public function language(string $code): ?LanguageConfig
    {
        return $this->languages[$code] ?? null;
    }

    /**
     * Return the default language config, or null when i18n is disabled.
     */
    public function defaultLanguageConfig(): ?LanguageConfig
    {
        if ($this->defaultLanguage === null) {
            return null;
        }

        return $this->languages[$this->defaultLanguage] ?? null;
    }
}
