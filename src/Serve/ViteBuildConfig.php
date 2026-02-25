<?php
declare(strict_types=1);

namespace Glaze\Serve;

/**
 * Immutable Vite build configuration.
 */
final class ViteBuildConfig
{
    /**
     * Constructor.
     *
     * @param bool $enabled Whether Vite build integration is enabled.
     * @param string $command Command template used to run Vite build.
     */
    public function __construct(
        public readonly bool $enabled,
        public readonly string $command,
    ) {
    }
}
