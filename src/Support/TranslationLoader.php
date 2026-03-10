<?php
declare(strict_types=1);

namespace Glaze\Support;

use Cake\Utility\Hash;
use Nette\Neon\Neon;
use RuntimeException;
use Throwable;

/**
 * Loads and caches NEON-based string translation files.
 *
 * Translation files are NEON documents stored in a configurable directory
 * (default: `i18n/`) with one file per language code (e.g. `i18n/en.neon`,
 * `i18n/nl.neon`). Flat or nested NEON maps are both supported; dotted-path
 * keys are used for nested access.
 *
 * Example translation file (`i18n/nl.neon`):
 *
 * ```neon
 * read_more: Lees meer
 * posted_on: "Geplaatst op {date}"
 * nav:
 *   home: Start
 *   about: Over ons
 * ```
 *
 * Usage:
 *
 * ```php
 * $loader = new TranslationLoader('/project/i18n');
 * echo $loader->translate('nl', 'read_more');               // "Lees meer"
 * echo $loader->translate('nl', 'posted_on', ['date' => '2024-01']); // "Geplaatst op 2024-01"
 * echo $loader->translate('nl', 'nav.home');                // "Start"
 * echo $loader->translate('nl', 'missing', [], 'Fallback'); // "Fallback"
 * ```
 */
final class TranslationLoader
{
    /**
     * Loaded translation maps keyed by language code.
     *
     * @var array<string, array<string, mixed>>
     */
    protected array $loaded = [];

    /**
     * Constructor.
     *
     * @param string $translationsPath Absolute path to the directory containing NEON translation files.
     * @param string $defaultLanguage Default language code to fall back to when a key is missing in the requested language.
     */
    public function __construct(
        protected string $translationsPath,
        protected string $defaultLanguage = '',
    ) {
    }

    /**
     * Translate a key for the given language with optional parameter substitution.
     *
     * Resolution order:
     * 1. The requested `$language` translation file.
     * 2. The `$defaultLanguage` fallback file (when configured and different).
     * 3. The `$fallback` value passed by the caller.
     *
     * Parameters are substituted by replacing `{key}` placeholders in the
     * translated string with the corresponding value from `$params`.
     *
     * @param string $language Language code.
     * @param string $key Dotted translation key (e.g. `nav.home`).
     * @param array<string, string|int|float> $params Substitution parameters.
     * @param string $fallback Fallback value returned when the key is not found in any translation file.
     */
    public function translate(
        string $language,
        string $key,
        array $params = [],
        string $fallback = '',
    ): string {
        $value = $this->lookup($language, $key)
            ?? ($language !== $this->defaultLanguage ? $this->lookup($this->defaultLanguage, $key) : null)
            ?? ($fallback !== '' ? $fallback : $key);

        return $this->interpolate($value, $params);
    }

    /**
     * Return whether a translation key exists for the given language.
     *
     * @param string $language Language code.
     * @param string $key Dotted translation key.
     */
    public function has(string $language, string $key): bool
    {
        return $this->lookup($language, $key) !== null;
    }

    /**
     * Look up a translation key from the cached map, loading the file if needed.
     *
     * Returns null when the key does not exist or the translation file is absent.
     *
     * @param string $language Language code.
     * @param string $key Dotted translation key.
     */
    protected function lookup(string $language, string $key): ?string
    {
        if ($language === '') {
            return null;
        }

        $translations = $this->load($language);
        $value = Hash::get($translations, $key);

        return is_string($value) ? $value : null;
    }

    /**
     * Load (or return cached) translations for a language code.
     *
     * Missing or unreadable files are silently treated as empty maps.
     *
     * @param string $language Language code.
     * @return array<string, mixed>
     */
    protected function load(string $language): array
    {
        if (isset($this->loaded[$language])) {
            return $this->loaded[$language];
        }

        $path = rtrim($this->translationsPath, '/') . '/' . $language . '.neon';

        if (!is_file($path)) {
            $this->loaded[$language] = [];

            return $this->loaded[$language];
        }

        $content = file_get_contents($path);
        if ($content === false) {
            $this->loaded[$language] = [];

            return $this->loaded[$language];
        }

        try {
            $decoded = Neon::decode($content);
        } catch (Throwable) {
            throw new RuntimeException(sprintf(
                'Failed to parse translation file "%s": invalid NEON syntax.',
                $path,
            ));
        }

        $decoded = is_array($decoded) ? $decoded : [];

        /** @var array<string, mixed> $translations */
        $translations = [];
        foreach ($decoded as $key => $value) {
            if (is_string($key)) {
                $translations[$key] = $value;
            }
        }

        $this->loaded[$language] = $translations;

        return $this->loaded[$language];
    }

    /**
     * Substitute `{key}` placeholders in a translation string with parameter values.
     *
     * @param string $value Translation string.
     * @param array<string, string|int|float> $params Parameter map.
     */
    protected function interpolate(string $value, array $params): string
    {
        if ($params === []) {
            return $value;
        }

        $search = [];
        $replace = [];
        foreach ($params as $paramKey => $paramValue) {
            $search[] = '{' . $paramKey . '}';
            $replace[] = (string)$paramValue;
        }

        return str_replace($search, $replace, $value);
    }
}
