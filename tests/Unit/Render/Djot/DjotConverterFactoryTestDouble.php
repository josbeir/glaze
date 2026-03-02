<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Render\Djot;

use Glaze\Config\DjotOptions;
use Glaze\Render\Djot\DjotConverterFactory;
use Glaze\Render\Djot\PhikiCodeBlockRenderer;
use Phiki\Theme\Theme;

/**
 * Test double that promotes the protected caching methods of {@see DjotConverterFactory} to public
 * so they can be exercised directly in unit tests without going through the full converter pipeline.
 */
class DjotConverterFactoryTestDouble extends DjotConverterFactory
{
    /**
     * Expose {@see resolveRenderer()} for direct invocation in tests.
     *
     * @param \Glaze\Config\DjotOptions $djot Djot renderer options.
     */
    public function getRenderer(DjotOptions $djot): PhikiCodeBlockRenderer
    {
        return $this->resolveRenderer($djot);
    }

    /**
     * Expose {@see buildRendererSignature()} for direct invocation in tests.
     *
     * @param \Phiki\Theme\Theme|array<string, string>|string $theme Resolved Phiki theme.
     * @param bool $withGutter Whether line-number gutters are enabled.
     */
    public function getSignature(Theme|array|string $theme, bool $withGutter): string
    {
        return $this->buildRendererSignature($theme, $withGutter);
    }
}
