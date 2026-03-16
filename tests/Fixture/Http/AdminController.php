<?php
declare(strict_types=1);

namespace Glaze\Tests\Fixture\Http;

use Cake\Http\Response;
use Glaze\Http\Attribute\Route;
use Glaze\Http\Attribute\RoutePrefix;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Fixture admin controller for testing route attribute discovery and dispatch.
 */
#[RoutePrefix('/admin')]
final class AdminController
{
    /**
     * List action — GET /admin/dashboard.
     *
     * @return array<string, mixed>
     */
    #[Route('/dashboard')]
    public function dashboard(): array
    {
        return ['page' => 'dashboard'];
    }

    /**
     * Edit action — GET or POST /admin/articles/{slug}.
     *
     * @param string $slug Article slug path parameter.
     * @param \Psr\Http\Message\ServerRequestInterface $request Current request.
     * @return array<string, mixed>
     */
    #[Route('/articles/{slug}', methods: ['GET', 'POST'])]
    public function edit(string $slug, ServerRequestInterface $request): array
    {
        return ['slug' => $slug, 'method' => $request->getMethod()];
    }

    /**
     * Delete action — returns a ResponseInterface directly.
     */
    #[Route('/articles/{id}/delete', methods: 'POST')]
    public function delete(): ResponseInterface
    {
        return (new Response())->withStatus(204);
    }
}
