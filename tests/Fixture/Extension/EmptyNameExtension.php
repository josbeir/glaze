<?php
declare(strict_types=1);

namespace Glaze\Tests\Fixture\Extension;

use Glaze\Template\Extension\GlazeExtension;

/**
 * Invalid extension fixture: carries the attribute with an empty name and helper: true.
 */
#[GlazeExtension('', helper: true)]
final class EmptyNameExtension
{
    /**
     * Returns a fixed string.
     */
    public function __invoke(): string
    {
        return 'empty-name';
    }
}
