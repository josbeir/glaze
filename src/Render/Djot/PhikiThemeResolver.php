<?php
declare(strict_types=1);

namespace Glaze\Render\Djot;

use Glaze\Config\DjotOptions;
use Phiki\Theme\Theme;

/**
 * Resolves configured code highlighting theme values for Phiki.
 */
final class PhikiThemeResolver
{
    /**
     * Resolve final Phiki theme configuration from Djot options.
     *
     * When `codeHighlightingThemes` is configured, it takes precedence and
     * returns a named map that enables Phiki multi-theme output. Otherwise a
     * single theme value from `codeHighlightingTheme` is used.
     *
     * @param \Glaze\Config\DjotOptions $djot Djot renderer options.
     * @return \Phiki\Theme\Theme|array<string, string>|string
     */
    public function resolve(DjotOptions $djot): array|string|Theme
    {
        if ($djot->codeHighlightingThemes !== []) {
            return $this->resolveNamedThemes($djot->codeHighlightingThemes);
        }

        return $this->resolveTheme($djot->codeHighlightingTheme);
    }

    /**
     * Resolve configured theme value to a Phiki theme enum when available.
     *
     * @param string $theme Theme identifier.
     */
    protected function resolveTheme(string $theme): string|Theme
    {
        $normalizedTheme = strtolower(trim($theme));
        if ($normalizedTheme === '') {
            return Theme::Nord;
        }

        foreach (Theme::cases() as $case) {
            if ($case->value === $normalizedTheme) {
                return $case;
            }
        }

        return $normalizedTheme;
    }

    /**
     * Resolve configured named theme map values to normalized Phiki theme identifiers.
     *
     * @param array<string, string> $themes Named themes map (name => theme identifier).
     * @return array<string, string>
     */
    protected function resolveNamedThemes(array $themes): array
    {
        $resolvedThemes = [];

        foreach ($themes as $themeName => $themeValue) {
            $resolvedThemes[$themeName] = strtolower(trim($themeValue));
        }

        return $resolvedThemes;
    }
}
