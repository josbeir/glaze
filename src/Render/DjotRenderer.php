<?php
declare(strict_types=1);

namespace Glaze\Render;

use Djot\DjotConverter;

/**
 * Converts Djot source documents to HTML.
 */
final class DjotRenderer
{
    protected DjotConverter $converter;

    /**
     * Constructor.
     *
     * @param \Djot\DjotConverter|null $converter Djot converter instance.
     */
    public function __construct(
        ?DjotConverter $converter = null,
    ) {
        $this->converter = $converter ?? new DjotConverter();
    }

    /**
     * Render Djot source to HTML.
     *
     * @param string $source Djot source content.
     */
    public function render(string $source): string
    {
        return $this->converter->convert($source);
    }
}
