<?php
declare(strict_types=1);

namespace Glaze\Tests\Fixture\Extension;

use Glaze\Template\Extension\GlazeExtension;

/**
 * Valid extension fixture: carries the attribute and implements __invoke.
 */
#[GlazeExtension('test-extension')]
final class NamedTestExtension
{
    /**
     * Returns a fixed string with an optional suffix for test assertions.
     */
    public function __invoke(string $suffix = ''): string
    {
        return 'result' . $suffix;
    }
}
