<?php
declare(strict_types=1);

namespace Glaze\Tests\Fixture\Extension;

use Glaze\Template\Extension\GlazeExtension;

/**
 * Invalid extension fixture: carries the attribute but has no __invoke method.
 */
#[GlazeExtension('not-invokable')]
final class NotInvokableExtension
{
}
