<?php
declare(strict_types=1);

namespace Glaze\Tests\Fixture\Http;

use Glaze\Http\Attribute\Route;

/**
 * Fixture controller without a RoutePrefix for testing unprefixed routes.
 */
final class ArticleController
{
    /**
     * Index action — GET /articles.
     *
     * @return array<string, mixed>
     */
    #[Route('/articles')]
    public function index(): array
    {
        return ['articles' => []];
    }

    /**
     * Show action — GET /articles/{slug}.
     *
     * @param string $slug Article slug path parameter.
     * @return array<string, mixed>
     */
    #[Route('/articles/{slug}')]
    public function show(string $slug): array
    {
        return ['slug' => $slug];
    }
}
