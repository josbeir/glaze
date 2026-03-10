<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Http;

use Cake\Http\ServerRequestFactory;
use Glaze\Build\SiteBuilder;
use Glaze\Config\BuildConfig;
use Glaze\Http\DevPageRequestHandler;
use Glaze\Tests\Helper\ContainerTestTrait;
use Glaze\Tests\Helper\FilesystemTestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Tests for development page request handling.
 */
final class DevPageRequestHandlerTest extends TestCase
{
    use ContainerTestTrait;
    use FilesystemTestTrait;

    /**
     * Ensure known content paths return rendered HTML.
     */
    public function testHandleReturnsOkResponseForKnownPage(): void
    {
        $projectRoot = $this->copyFixtureToTemp('projects/basic');

        $config = BuildConfig::fromProjectRoot($projectRoot, true);
        $handler = $this->createHandler($config);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/');

        $response = $handler->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('<h1>Home</h1>', (string)$response->getBody());
    }

    /**
     * Ensure unknown paths return a 404 response.
     */
    public function testHandleReturnsNotFoundForUnknownPage(): void
    {
        $projectRoot = $this->copyFixtureToTemp('projects/basic');

        $config = BuildConfig::fromProjectRoot($projectRoot, true);
        $handler = $this->createHandler($config);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/missing');

        $response = $handler->handle($request);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('404 Not Found', (string)$response->getBody());
    }

    /**
     * Ensure directory-like routes redirect to canonical trailing slash paths.
     */
    public function testHandleRedirectsDirectoryPathToTrailingSlash(): void
    {
        $projectRoot = $this->copyFixtureToTemp('projects/basic');
        mkdir($projectRoot . '/content/blog', 0755, true);
        file_put_contents($projectRoot . '/content/blog/index.dj', "# Blog\n");

        $config = BuildConfig::fromProjectRoot($projectRoot, true);
        $handler = $this->createHandler($config);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/blog');

        $response = $handler->handle($request);

        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame('/blog/', $response->getHeaderLine('Location'));
    }

    /**
     * Ensure configured basePath request prefixes are tolerated in live mode.
     */
    public function testHandleResolvesPrefixedPathWhenBasePathConfigured(): void
    {
        $projectRoot = $this->copyFixtureToTemp('projects/basic');
        file_put_contents($projectRoot . '/glaze.neon', "site:\n  basePath: /docs\n");

        $config = BuildConfig::fromProjectRoot($projectRoot, true);
        $handler = $this->createHandler($config);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/docs/');

        $response = $handler->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('<h1>Home</h1>', (string)$response->getBody());
    }

    /**
     * Ensure prefixed directory-like requests keep canonical redirect behavior.
     */
    public function testHandleRedirectsPrefixedDirectoryPathToTrailingSlash(): void
    {
        $projectRoot = $this->copyFixtureToTemp('projects/basic');
        mkdir($projectRoot . '/content/blog', 0755, true);
        file_put_contents($projectRoot . '/content/blog/index.dj', "# Blog\n");
        file_put_contents($projectRoot . '/glaze.neon', "site:\n  basePath: /docs\n");

        $config = BuildConfig::fromProjectRoot($projectRoot, true);
        $handler = $this->createHandler($config);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/docs/blog');

        $response = $handler->handle($request);

        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame('/docs/blog/', $response->getHeaderLine('Location'));
    }

    /**
     * Create a request handler with explicit dependencies.
     */
    protected function createHandler(BuildConfig $config): DevPageRequestHandler
    {
        return new DevPageRequestHandler($config, $this->createSiteBuilder());
    }

    /**
     * Create a site builder with concrete dependencies.
     */
    protected function createSiteBuilder(): SiteBuilder
    {
        /** @var \Glaze\Build\SiteBuilder */
        return $this->service(SiteBuilder::class);
    }

    // -------------------------------------------------------------------------
    // Custom 404 page via outputPath frontmatter
    // -------------------------------------------------------------------------

    /**
     * Ensure an unknown path returns a 404 response using the custom 404 page
     * when a content file with outputPath set to 404.html exists.
     */
    public function testHandleServesCustomNotFoundPageWith404Status(): void
    {
        $projectRoot = $this->copyFixtureToTemp('projects/basic');
        file_put_contents(
            $projectRoot . '/content/not-found.dj',
            "+++\ntitle: Page Not Found\noutputPath: 404.html\nunlisted: true\n+++\n# Not Found\n\nThis page does not exist.\n",
        );

        $config = BuildConfig::fromProjectRoot($projectRoot, true);
        $handler = $this->createHandler($config);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/missing-page');

        $response = $handler->handle($request);

        $this->assertSame(404, $response->getStatusCode());
        $body = (string)$response->getBody();
        $this->assertStringContainsString('<h1>Not Found</h1>', $body);
        $this->assertStringContainsString('This page does not exist.', $body);
    }

    /**
     * Ensure the custom 404 page is directly accessible at its own URL.
     */
    public function testHandleReturnsCustomNotFoundPageAtOwnUrl(): void
    {
        $projectRoot = $this->copyFixtureToTemp('projects/basic');
        file_put_contents(
            $projectRoot . '/content/not-found.dj',
            "+++\ntitle: Page Not Found\noutputPath: 404.html\nunlisted: true\n+++\n# Not Found\n",
        );

        $config = BuildConfig::fromProjectRoot($projectRoot, true);
        $handler = $this->createHandler($config);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/404.html');

        $response = $handler->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('<h1>Not Found</h1>', (string)$response->getBody());
    }
}
