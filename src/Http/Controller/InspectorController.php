<?php
declare(strict_types=1);

namespace Glaze\Http\Controller;

use Glaze\Config\BuildConfig;
use Glaze\Content\ContentDiscoveryService;
use Glaze\Http\Attribute\Route;
use Glaze\Http\Attribute\RoutePrefix;

/**
 * Core development-mode controller for inspecting a Glaze project at runtime.
 *
 * Routes exposed by this controller are only active when the dev server is
 * running (i.e. not in static-build mode) and give developers a built-in UI
 * for exploring content, routes, and configuration without needing external
 * tools.
 *
 * All routes are mounted under the /_glaze/ prefix so they can never conflict
 * with project-defined content paths.
 *
 * Example:
 *   GET /_glaze/routes → renders a table of all discovered content pages.
 */
#[RoutePrefix('/_glaze')]
final class InspectorController
{
    /**
     * Constructor.
     *
     * @param \Glaze\Content\ContentDiscoveryService $discoveryService Content discovery service.
     * @param \Glaze\Config\BuildConfig $config Build configuration.
     */
    public function __construct(
        private ContentDiscoveryService $discoveryService,
        private BuildConfig $config,
    ) {
    }

    /**
     * List all discovered content pages and their URL paths.
     *
     * @return array<string, mixed> Template variables with key `pages` containing
     *   all discovered {@see \Glaze\Content\ContentPage} instances.
     */
    #[Route('/routes')]
    public function routes(): array
    {
        $pages = $this->discoveryService->discover(
            $this->config->contentPath(),
            $this->config->taxonomies,
            $this->config->contentTypes,
        );

        return ['pages' => $pages];
    }
}
