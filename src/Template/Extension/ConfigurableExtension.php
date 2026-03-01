<?php
declare(strict_types=1);

namespace Glaze\Template\Extension;

/**
 * Contract for extensions that support options from project configuration.
 *
 * Implement this interface when an extension should be configurable through
 * the `extensions` section in `glaze.neon`.
 */
interface ConfigurableExtension
{
    /**
     * Create an extension instance from normalized extension options.
     *
     * @param array<string, mixed> $options Per-extension option map.
     */
    public static function fromConfig(array $options): static;
}
