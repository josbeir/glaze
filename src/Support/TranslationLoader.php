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
 * **Plural forms** are expressed as a NEON list under a key. Index 0 is used
 * when the `count` param equals 1 (singular), index 1 for all other counts.
 * Additional indices may be added for languages that require more plural forms
 * (e.g. zero, few, many) — form selection is purely index-based.
 *
 * Example translation file (`i18n/en.neon`):
 *
 * ```neon
 * read_more: Read more
 * posted_on: "Posted on {date}"
 * items:
 *   - one item
 *   - "{count} items"
 * nav:
 *   home: Home
 *   about: About
 * ```
 *
 * Usage:
 *
 * ```php
 * $loader = new TranslationLoader('/project/i18n');
 * echo $loader->translate('en', 'read_more');               // "Read more"
 * echo $loader->translate('en', 'items', ['count' => 1]);   // "one item"
 * echo $loader->translate('en', 'items', ['count' => 5]);   // "5 items"
 * echo $loader->translate('en', 'posted_on', ['date' => '2024-01']); // "Posted on 2024-01"
 * echo $loader->translate('en', 'nav.home');                // "Home"
 * echo $loader->translate('en', 'missing', [], 'Fallback'); // "Fallback"
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
     * When the resolved value is a NEON list (plural forms), the correct form is
     * selected based on the `count` key in `$params`: index 0 for count=1
     * (singular), index 1 for all other counts. When `count` is absent, index 0
     * is used. See {@see selectPluralForm()} for details.
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
        $count = isset($params['count']) ? (int)$params['count'] : 1;
        $pluralIndex = $this->selectPluralForm($count);

        $value = $this->lookup($language, $key, $pluralIndex)
            ?? ($language !== $this->defaultLanguage ? $this->lookup($this->defaultLanguage, $key, $pluralIndex) : null)
            ?? ($fallback !== '' ? $fallback : $key);

        return $this->interpolate($value, $params);
    }

    /**
     * Return whether a translation key exists for the given language.
     *
     * Returns true for both scalar string keys and plural-form list keys.
     *
     * @param string $language Language code.
     * @param string $key Dotted translation key.
     */
    public function has(string $language, string $key): bool
    {
        return $this->lookup($language, $key, 0) !== null;
    }

    /**
     * Look up a translation key from the cached map, loading the file if needed.
     *
     * When the raw value is a list (plural forms array), the form at `$pluralIndex`
     * is returned, falling back to the last available form when the index is out
     * of bounds. Returns null when the key does not exist, the translation file
     * is absent, or the resolved form is not a string.
     *
     * @param string $language Language code.
     * @param string $key Dotted translation key.
     * @param int $pluralIndex Zero-based plural form index to select from list values.
     */
    protected function lookup(string $language, string $key, int $pluralIndex = 0): ?string
    {
        if ($language === '') {
            return null;
        }

        $translations = $this->load($language);
        $value = Hash::get($translations, $key);

        if (is_string($value)) {
            return $value;
        }

        if (is_array($value) && $value !== []) {
            $form = $value[$pluralIndex] ?? $value[array_key_last($value)];

            return is_string($form) ? $form : null;
        }

        return null;
    }

    /**
     * Select the zero-based plural form index for a given count.
     *
     * Uses simple two-form rules: index 0 for count=1 (singular), index 1 for
     * all other values (plural). Languages requiring additional forms (zero, few,
     * many) can supply extra list entries — the index maps directly to their
     * position in the NEON list.
     *
     * @param int $count The count value driving plural selection.
     */
    protected function selectPluralForm(int $count): int
    {
        return $count === 1 ? 0 : 1;
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
