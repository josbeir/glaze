<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Http;

use Cake\Http\ServerRequestFactory;
use Glaze\Config\BuildConfig;
use Glaze\Http\DevPageRequestHandler;
use PHPUnit\Framework\TestCase;

/**
 * Tests for development page request handling.
 */
final class DevPageRequestHandlerTest extends TestCase
{
    /**
     * Ensure known content paths return rendered HTML.
     */
    public function testHandleReturnsOkResponseForKnownPage(): void
    {
        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/content', 0755, true);
        mkdir($projectRoot . '/templates', 0755, true);

        file_put_contents($projectRoot . '/content/index.dj', "# Hello\n");
        file_put_contents($projectRoot . '/templates/page.sugar.php', '<h1><?= htmlspecialchars((string)$title, ENT_QUOTES, "UTF-8") ?></h1><?= $content |> raw() ?>');

        $config = BuildConfig::fromProjectRoot($projectRoot, true);
        $handler = new DevPageRequestHandler($config);
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
        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/content', 0755, true);
        mkdir($projectRoot . '/templates', 0755, true);

        file_put_contents($projectRoot . '/content/index.dj', "# Hello\n");
        file_put_contents($projectRoot . '/templates/page.sugar.php', '<h1><?= htmlspecialchars((string)$title, ENT_QUOTES, "UTF-8") ?></h1><?= $content |> raw() ?>');

        $config = BuildConfig::fromProjectRoot($projectRoot, true);
        $handler = new DevPageRequestHandler($config);
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
        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/content/blog', 0755, true);
        mkdir($projectRoot . '/templates', 0755, true);

        file_put_contents($projectRoot . '/content/blog/index.dj', "# Blog\n");
        file_put_contents($projectRoot . '/templates/page.sugar.php', '<h1><?= htmlspecialchars((string)$title, ENT_QUOTES, "UTF-8") ?></h1><?= $content |> raw() ?>');

        $config = BuildConfig::fromProjectRoot($projectRoot, true);
        $handler = new DevPageRequestHandler($config);
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/blog');

        $response = $handler->handle($request);

        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame('/blog/', $response->getHeaderLine('Location'));
    }

    /**
     * Create a temporary directory for isolated test execution.
     */
    protected function createTempDirectory(): string
    {
        $path = sys_get_temp_dir() . '/glaze_test_' . uniqid('', true);
        mkdir($path, 0755, true);

        return $path;
    }
}
