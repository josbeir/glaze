<?php
declare(strict_types=1);

namespace Glaze\Tests\Fixture\Extension;

/**
 * Invalid extension fixture: invokable but missing the GlazeExtension attribute.
 */
final class NoAttributeExtension
{
    /**
     * Returns a fixed string.
     */
    public function __invoke(): string
    {
        return 'no-attribute';
    }
}
