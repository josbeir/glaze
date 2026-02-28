<?php
declare(strict_types=1);

namespace Glaze\Scaffold;

/**
 * Renders scaffold template files by replacing `{variable}` tokens with values.
 *
 * Token syntax is `{variableName}` where the name consists of alphanumeric
 * characters and underscores. Only explicitly provided variable keys are
 * replaced; unknown tokens are left untouched, making the renderer safe to
 * use on JSON, NEON, or any other structured text format.
 *
 * Example:
 * ```php
 * $renderer = new TemplateRenderer();
 * $result = $renderer->render('Hello, {name}!', ['name' => 'World']);
 * // → "Hello, World!"
 * ```
 */
final class TemplateRenderer
{
    /**
     * Render a template string by substituting `{variable}` tokens.
     *
     * Only keys present in the `$variables` map are replaced. Keys are matched
     * case-sensitively. Tokens without a corresponding key are preserved as-is.
     *
     * @param string $template Template content with `{variable}` placeholders.
     * @param array<string, string> $variables Map of variable names to replacement values.
     */
    public function render(string $template, array $variables): string
    {
        if ($variables === []) {
            return $template;
        }

        $search = array_map(
            static fn(string $key): string => '{' . $key . '}',
            array_keys($variables),
        );

        return str_replace($search, array_values($variables), $template);
    }
}
