<?php
declare(strict_types=1);

namespace Glaze\Config;

use Glaze\Utility\Normalization;

/**
 * Immutable typed Djot renderer options.
 *
 * Built directly from a project's `djot` configuration block. Neon's own
 * parser preserves native PHP types (bool, int, string, array), so only
 * semantic constraints are applied here (e.g. valid anchor positions,
 * heading level range 1-6). Unknown or wrong-typed values fall back to
 * the constructor defaults.
 *
 * Example:
 *   $options = DjotOptions::fromProjectConfig($config['djot'] ?? []);
 */
final readonly class DjotOptions
{
    /**
     * Constructor.
     *
     * @param bool $codeHighlightingEnabled Whether Phiki highlighting is enabled.
     * @param string $codeHighlightingTheme Configured Phiki theme (lower-cased).
     * @param bool $codeHighlightingWithGutter Whether line-number gutter is enabled.
     * @param bool $headerAnchorsEnabled Whether heading permalinks are enabled.
     * @param string $headerAnchorsSymbol Permalink symbol.
     * @param string $headerAnchorsPosition Permalink position (`before` or `after`).
     * @param string $headerAnchorsCssClass Permalink CSS class.
     * @param string $headerAnchorsAriaLabel Permalink aria-label.
     * @param array<int> $headerAnchorsLevels Heading levels that receive permalinks.
     * @param bool $autolinkEnabled Whether autolink conversion is enabled.
     * @param array<string> $autolinkAllowedSchemes URL schemes allowed for autolinking.
     * @param bool $externalLinksEnabled Whether external link attribute injection is enabled.
     * @param array<string> $externalLinksInternalHosts Hostnames considered internal.
     * @param string $externalLinksTarget External link target attribute.
     * @param string $externalLinksRel External link rel attribute.
     * @param bool $externalLinksNofollow Whether nofollow should be added.
     * @param bool $smartQuotesEnabled Whether smart quotes conversion is enabled.
     * @param string|null $smartQuotesLocale Smart quotes locale.
     * @param string|null $smartQuotesOpenDouble Custom opening double quote.
     * @param string|null $smartQuotesCloseDouble Custom closing double quote.
     * @param string|null $smartQuotesOpenSingle Custom opening single quote.
     * @param string|null $smartQuotesCloseSingle Custom closing single quote.
     * @param bool $mentionsEnabled Whether mention expansion is enabled.
     * @param string $mentionsUrlTemplate URL template used for mention links.
     * @param string $mentionsCssClass CSS class used for mention links.
     * @param bool $semanticSpanEnabled Whether semantic span conversion is enabled.
     * @param bool $defaultAttributesEnabled Whether default attribute injection is enabled.
     * @param array<string, array<string, string>> $defaultAttributesDefaults Default attributes map.
     */
    public function __construct(
        public bool $codeHighlightingEnabled = true,
        public string $codeHighlightingTheme = 'nord',
        public bool $codeHighlightingWithGutter = false,
        public bool $headerAnchorsEnabled = false,
        public string $headerAnchorsSymbol = '#',
        public string $headerAnchorsPosition = 'after',
        public string $headerAnchorsCssClass = 'permalink-wrapper',
        public string $headerAnchorsAriaLabel = 'Anchor link',
        public array $headerAnchorsLevels = [1, 2, 3, 4, 5, 6],
        public bool $autolinkEnabled = false,
        public array $autolinkAllowedSchemes = ['https', 'http', 'mailto'],
        public bool $externalLinksEnabled = false,
        public array $externalLinksInternalHosts = [],
        public string $externalLinksTarget = '_blank',
        public string $externalLinksRel = 'noopener noreferrer',
        public bool $externalLinksNofollow = false,
        public bool $smartQuotesEnabled = false,
        public ?string $smartQuotesLocale = null,
        public ?string $smartQuotesOpenDouble = null,
        public ?string $smartQuotesCloseDouble = null,
        public ?string $smartQuotesOpenSingle = null,
        public ?string $smartQuotesCloseSingle = null,
        public bool $mentionsEnabled = false,
        public string $mentionsUrlTemplate = '/users/view/{username}',
        public string $mentionsCssClass = 'mention',
        public bool $semanticSpanEnabled = false,
        public bool $defaultAttributesEnabled = false,
        public array $defaultAttributesDefaults = [],
    ) {
    }

    /**
     * Build typed options from a project's `djot` configuration block.
     *
     * Each sub-section (codeHighlighting, headerAnchors, etc.) is extracted
     * from the map; missing or non-array sections fall back to an empty array
     * and the constructor defaults take effect. Only two fields receive
     * additional semantic validation: `position` (must be `before` or `after`)
     * and `levels` (integers in range 1-6).
     *
     * @param array<string, mixed> $djotConfig Raw `djot` configuration map.
     */
    public static function fromProjectConfig(array $djotConfig): self
    {
        $ch = self::section($djotConfig, 'codeHighlighting');
        $ha = self::section($djotConfig, 'headerAnchors');
        $al = self::section($djotConfig, 'autolink');
        $el = self::section($djotConfig, 'externalLinks');
        $sq = self::section($djotConfig, 'smartQuotes');
        $me = self::section($djotConfig, 'mentions');
        $ss = self::section($djotConfig, 'semanticSpan');
        $da = self::section($djotConfig, 'defaultAttributes');

        $position = strtolower(self::strVal($ha['position'] ?? null, 'after'));
        if (!in_array($position, ['before', 'after'], true)) {
            $position = 'after';
        }

        return new self(
            codeHighlightingEnabled: self::boolVal($ch['enabled'] ?? null, true),
            codeHighlightingTheme: strtolower(self::strVal($ch['theme'] ?? null, 'nord')),
            codeHighlightingWithGutter: self::boolVal($ch['withGutter'] ?? null, false),
            headerAnchorsEnabled: self::boolVal($ha['enabled'] ?? null, false),
            headerAnchorsSymbol: self::strVal($ha['symbol'] ?? null, '#'),
            headerAnchorsPosition: $position,
            headerAnchorsCssClass: self::strVal($ha['cssClass'] ?? null, 'permalink-wrapper'),
            headerAnchorsAriaLabel: self::strVal($ha['ariaLabel'] ?? null, 'Anchor link'),
            headerAnchorsLevels: self::headerAnchorLevels($ha['levels'] ?? null),
            autolinkEnabled: self::boolVal($al['enabled'] ?? null, false),
            autolinkAllowedSchemes: self::lowercaseStringList(
                $al['allowedSchemes'] ?? null,
                ['https', 'http', 'mailto'],
            ),
            externalLinksEnabled: self::boolVal($el['enabled'] ?? null, false),
            externalLinksInternalHosts: self::lowercaseStringList($el['internalHosts'] ?? null, []),
            externalLinksTarget: self::strVal($el['target'] ?? null, '_blank'),
            externalLinksRel: self::strVal($el['rel'] ?? null, 'noopener noreferrer'),
            externalLinksNofollow: self::boolVal($el['nofollow'] ?? null, false),
            smartQuotesEnabled: self::boolVal($sq['enabled'] ?? null, false),
            smartQuotesLocale: self::optStrVal($sq['locale'] ?? null),
            smartQuotesOpenDouble: self::optStrVal($sq['openDouble'] ?? null),
            smartQuotesCloseDouble: self::optStrVal($sq['closeDouble'] ?? null),
            smartQuotesOpenSingle: self::optStrVal($sq['openSingle'] ?? null),
            smartQuotesCloseSingle: self::optStrVal($sq['closeSingle'] ?? null),
            mentionsEnabled: self::boolVal($me['enabled'] ?? null, false),
            mentionsUrlTemplate: self::strVal($me['urlTemplate'] ?? null, '/users/view/{username}'),
            mentionsCssClass: self::strVal($me['cssClass'] ?? null, 'mention'),
            semanticSpanEnabled: self::boolVal($ss['enabled'] ?? null, false),
            defaultAttributesEnabled: self::boolVal($da['enabled'] ?? null, false),
            defaultAttributesDefaults: self::parseDefaultAttributesDefaults(
                is_array($da['defaults'] ?? null) ? $da['defaults'] : [],
            ),
        );
    }

    /**
     * Extract a named sub-section from a config map, returning an empty array
     * when the key is absent or holds a non-array value.
     *
     * @param array<string, mixed> $source Source config map.
     * @param string $key Section key to extract.
     * @return array<string, mixed>
     */
    private static function section(array $source, string $key): array
    {
        if (!is_array($source[$key] ?? null)) {
            return [];
        }

        /** @var array<string, mixed> $section */
        $section = $source[$key];

        return $section;
    }

    /**
     * Return the value as a bool, falling back to the given default for non-bool input.
     *
     * @param mixed $value Input value.
     * @param bool $default Fallback when value is not a native bool.
     */
    private static function boolVal(mixed $value, bool $default): bool
    {
        return is_bool($value) ? $value : $default;
    }

    /**
     * Return the value as a trimmed non-empty string, falling back to the default.
     *
     * @param mixed $value Input value.
     * @param string $default Fallback when value is not a non-empty string.
     */
    private static function strVal(mixed $value, string $default): string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : $default;
    }

    /**
     * Return the value as a string when present and non-empty, or null.
     *
     * @param mixed $value Input value.
     */
    private static function optStrVal(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Return a lowercase string list from a raw value, falling back to the given default.
     *
     * @param mixed $value Raw value, expected to be an array of strings.
     * @param array<string> $default Fallback list when value is absent or invalid.
     * @return array<string>
     */
    private static function lowercaseStringList(mixed $value, array $default): array
    {
        if (!is_array($value)) {
            return $default;
        }

        $result = array_values(array_filter(array_map(
            static fn(mixed $item): string => is_string($item) ? strtolower(trim($item)) : '',
            $value,
        ), static fn(string $item): bool => $item !== ''));

        return $result !== [] ? $result : $default;
    }

    /**
     * Return validated heading anchor levels (integers 1-6), falling back to all levels.
     *
     * @param mixed $value Raw levels value.
     * @return array<int>
     */
    private static function headerAnchorLevels(mixed $value): array
    {
        if (!is_array($value)) {
            return [1, 2, 3, 4, 5, 6];
        }

        $levels = array_values(array_unique(array_filter(
            array_map(static fn(mixed $l): int => is_int($l) ? $l : 0, $value),
            static fn(int $l): bool => $l >= 1 && $l <= 6,
        )));

        return $levels !== [] ? $levels : [1, 2, 3, 4, 5, 6];
    }

    /**
     * Parse the `defaultAttributes.defaults` map into a normalised element-to-attributes map.
     *
     * Element type names are lower-cased; the inner attributes map is filtered to
     * string key/value pairs via {@see Normalization::stringMap()}.
     *
     * @param array<mixed> $rawDefaults Raw defaults map.
     * @return array<string, array<string, string>>
     */
    private static function parseDefaultAttributesDefaults(array $rawDefaults): array
    {
        $result = [];
        foreach ($rawDefaults as $elementType => $attributes) {
            if (!is_string($elementType)) {
                continue;
            }
            if (!is_array($attributes)) {
                continue;
            }
            $normalizedType = strtolower(trim($elementType));
            if ($normalizedType === '') {
                continue;
            }

            $normalizedMap = Normalization::stringMap($attributes);
            if ($normalizedMap === []) {
                continue;
            }

            $result[$normalizedType] = $normalizedMap;
        }

        return $result;
    }
}
