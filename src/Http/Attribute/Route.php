<?php
declare(strict_types=1);

namespace Glaze\Http\Attribute;

use Attribute;

/**
 * Marks a controller action method as a route handler.
 *
 * Attach this attribute to public controller methods to associate them with one
 * or more URL path patterns and HTTP methods. The attribute is repeatable, so a
 * single action may respond to several paths.
 *
 * Example:
 *   #[Route('/articles/{slug}')]
 *   #[Route('/articles/{slug}/edit', methods: ['GET', 'POST'])]
 *   public function edit(string $slug): array { ... }
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final class Route
{
    /**
     * Normalised list of HTTP methods this route accepts.
     *
     * @var list<string>
     */
    public readonly array $methods;

    /**
     * Constructor.
     *
     * @param string $path URL path pattern, e.g. '/articles/{slug}'.
     * @param list<string>|string $methods HTTP method(s) accepted, defaults to GET.
     */
    public function __construct(
        public readonly string $path,
        array|string $methods = 'GET',
    ) {
        $normalized = array_map('strtoupper', (array)$methods);
        $this->methods = array_values($normalized);
    }
}
