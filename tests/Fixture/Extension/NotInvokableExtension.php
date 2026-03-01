<?php
declare(strict_types=1);

namespace Glaze\Tests\Fixture\Extension;

use Glaze\Template\Extension\GlazeExtension;

/**
 * Invalid extension fixture: declares helper: true but has no __invoke method.
 */
#[GlazeExtension('not-invokable', helper: true)]
final class NotInvokableExtension
{
}
