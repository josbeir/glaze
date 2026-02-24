<?php
declare(strict_types=1);

namespace Glaze\Render;

use Djot\DjotConverter;
use Glaze\Render\Djot\PhikiCodeBlockRenderer;
use Glaze\Render\Djot\PhikiExtension;
use Phiki\Theme\Theme;

/**
 * Converts Djot source documents to HTML.
 */
final class DjotRenderer
{
    protected ?DjotConverter $converter;

    /**
     * Constructor.
     *
     * @param \Djot\DjotConverter|null $converter Djot converter instance.
     */
    public function __construct(
        ?DjotConverter $converter = null,
    ) {
        $this->converter = $converter;
    }

    /**
     * Render Djot source to HTML.
     *
     * @param string $source Djot source content.
     * @param array{enabled: bool, theme: string, withGutter: bool} $codeHighlighting Code highlighting options.
     */
    public function render(
        string $source,
        array $codeHighlighting = ['enabled' => true, 'theme' => 'nord', 'withGutter' => false],
    ): string {
        $converter = $this->converter ?? $this->createConverter($codeHighlighting);

        return $converter->convert($source);
    }

    /**
     * Create a converter instance with configured extensions.
     *
     * @param array{enabled: bool, theme: string, withGutter: bool} $codeHighlighting Code highlighting options.
     */
    protected function createConverter(array $codeHighlighting): DjotConverter
    {
        $converter = new DjotConverter();

        if (!$codeHighlighting['enabled']) {
            return $converter;
        }

        $converter->addExtension(
            new PhikiExtension(
                new PhikiCodeBlockRenderer(
                    theme: $this->resolveTheme($codeHighlighting['theme']),
                    withGutter: $codeHighlighting['withGutter'],
                ),
            ),
        );

        return $converter;
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
}
