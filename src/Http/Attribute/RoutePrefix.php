<?php
declare(strict_types=1);

namespace Glaze\Http\Attribute;

use Attribute;

/**
 * Defines a URL prefix that is prepended to every route in the controller.
 *
 * Apply this class-level attribute to a controller to scope all its actions
 * under a common URL segment, eliminating repetition across individual
 * #[Route] declarations.
 *
 * Example:
 *   #[RoutePrefix('/admin')]
 *   class AdminController { ... }
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class RoutePrefix
{
    /**
     * Constructor.
     *
     * @param string $prefix URL prefix prepended to every route in the controller.
     */
    public function __construct(public readonly string $prefix)
    {
    }
}
