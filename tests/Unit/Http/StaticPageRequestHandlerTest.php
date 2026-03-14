<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Http;

use Cake\Http\ServerRequestFactory;
use Glaze\Config\BuildConfig;
use Glaze\Http\StaticPageRequestHandler;
use Glaze\Tests\Helper\FilesystemTestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the static-serve fallback request handler.
 *
 * The handler reads pre-built HTML files from the output directory instead
 * of live-rendering content, so tests set up a fake output directory with
 * pre-built index.html and 404.html files.
 */
final class StaticPageRequestHandlerTest extends TestCase
{
    use FilesystemTestTrait;

    /**
     * Create a minimal project with a pre-built public/ output directory.
     *
     * Returns the project root. The output directory is populated with
     * the files listed in $outputFiles (relative paths → content).
     *
     * @param array<string, string> $outputFiles Relative output paths and their HTML content.
     * @param list<string> $neonOverrides Extra glaze.neon lines appended to base config.
     */
    protected function createStaticProject(
        array $outputFiles = [],
        array $neonOverrides = [],
    ): string {
        $projectRoot = $this->copyFixtureToTemp('projects/basic');

        $neon = "output:\n  path: public\n";
        foreach ($neonOverrides as $line) {
            $neon .= $line . "\n";
        }

        file_put_contents($projectRoot . '/glaze.neon', $neon);

        $outputDir = $projectRoot . '/public';
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        // Default pages
        $defaults = [
            'index.html' => '<html><body>Homepage</body></html>',
            '404.html' => '<html><body>Custom 404</body></html>',
        ];

        foreach (array_merge($defaults, $outputFiles) as $relativePath => $content) {
            $fullPath = $outputDir . '/' . $relativePath;
            $dir = dirname($fullPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            file_put_contents($fullPath, $content);
        }

        return $projectRoot;
    }

    /**
     * Ensure the root path serves the pre-built homepage.
     */
    public function testHandleServesRootIndexHtml(): void
    {
        $projectRoot = $this->createStaticProject();
        $config = BuildConfig::fromProjectRoot($projectRoot, false);
        $handler = new StaticPageRequestHandler($config);

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/');
        $response = $handler->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('text/html', $response->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('Homepage', (string)$response->getBody());
    }

    /**
     * Ensure sub-pages are served from their pre-built index.html.
     */
    public function testHandleServesSubPageIndexHtml(): void
    {
        $projectRoot = $this->createStaticProject([
            'about/index.html' => '<html><body>About</body></html>',
        ]);
        $config = BuildConfig::fromProjectRoot($projectRoot, false);
        $handler = new StaticPageRequestHandler($config);

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/about/');
        $response = $handler->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('About', (string)$response->getBody());
    }

    /**
     * Ensure extensionless paths without a trailing slash redirect to the canonical form.
     */
    public function testHandleRedirectsDirectoryPathToTrailingSlash(): void
    {
        $projectRoot = $this->createStaticProject([
            'about/index.html' => '<html><body>About</body></html>',
        ]);
        $config = BuildConfig::fromProjectRoot($projectRoot, false);
        $handler = new StaticPageRequestHandler($config);

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/about');
        $response = $handler->handle($request);

        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame('/about/', $response->getHeaderLine('Location'));
    }

    /**
     * Ensure unmatched paths return 404 with the custom 404.html content.
     */
    public function testHandleReturnsNotFoundForMissingPage(): void
    {
        $projectRoot = $this->createStaticProject();
        $config = BuildConfig::fromProjectRoot($projectRoot, false);
        $handler = new StaticPageRequestHandler($config);

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/missing/');
        $response = $handler->handle($request);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('Custom 404', (string)$response->getBody());
    }

    /**
     * Ensure 404 falls back to the plain HTML skeleton when no 404.html is present.
     */
    public function testHandleReturnsPlain404WhenNoCustomPageExists(): void
    {
        $projectRoot = $this->createStaticProject();
        unlink($projectRoot . '/public/404.html');
        $config = BuildConfig::fromProjectRoot($projectRoot, false);
        $handler = new StaticPageRequestHandler($config);

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/missing/');
        $response = $handler->handle($request);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('404 Not Found', (string)$response->getBody());
    }

    /**
     * Ensure the configured basePath prefix is stripped before resolving output files.
     */
    public function testHandleResolvesCorrectlyWithBasePath(): void
    {
        $projectRoot = $this->createStaticProject(
            ['about/index.html' => '<html><body>About</body></html>'],
            ['site:', '  basePath: /docs'],
        );
        $config = BuildConfig::fromProjectRoot($projectRoot, false);
        $handler = new StaticPageRequestHandler($config);

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/docs/about/');
        $response = $handler->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('About', (string)$response->getBody());
    }

    /**
     * Ensure basePath-prefixed extensionless paths redirect to canonical trailing-slash form.
     */
    public function testHandleRedirectsPrefixedDirectoryPathWithBasePath(): void
    {
        $projectRoot = $this->createStaticProject(
            ['about/index.html' => '<html><body>About</body></html>'],
            ['site:', '  basePath: /docs'],
        );
        $config = BuildConfig::fromProjectRoot($projectRoot, false);
        $handler = new StaticPageRequestHandler($config);

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/docs/about');
        $response = $handler->handle($request);

        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame('/docs/about/', $response->getHeaderLine('Location'));
    }

    /**
     * Ensure basePath root path serves the pre-built homepage correctly.
     */
    public function testHandleServesBasePathRootAsHomepage(): void
    {
        $projectRoot = $this->createStaticProject(
            [],
            ['site:', '  basePath: /docs'],
        );
        $config = BuildConfig::fromProjectRoot($projectRoot, false);
        $handler = new StaticPageRequestHandler($config);

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/docs/');
        $response = $handler->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Homepage', (string)$response->getBody());
    }

    /**
     * Ensure query strings are preserved in redirect Location headers.
     */
    public function testHandlePreservesQueryStringInRedirect(): void
    {
        $projectRoot = $this->createStaticProject([
            'about/index.html' => '<html><body>About</body></html>',
        ]);
        $config = BuildConfig::fromProjectRoot($projectRoot, false);
        $handler = new StaticPageRequestHandler($config);

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/about');
        $request = $request->withUri($request->getUri()->withQuery('ref=nav'));

        $response = $handler->handle($request);

        $this->assertSame(301, $response->getStatusCode());
        $this->assertSame('/about/?ref=nav', $response->getHeaderLine('Location'));
    }

    /**
     * Ensure i18n-enabled sites serve language-scoped 404.html for requests under a language prefix.
     *
     * When i18n is enabled and the project has a pre-built `public/nl/404.html`, a request
     * to a missing page under `/nl/` must serve that file instead of the root `/404.html`.
     */
    public function testHandleServesLanguageScopedNotFoundPageForI18nPrefix(): void
    {
        $projectRoot = $this->createStaticProject(
            ['nl/404.html' => '<html><body>Dutch 404</body></html>'],
            [
                'i18n:',
                '  defaultLanguage: en',
                '  languages:',
                '    en:',
                '      label: English',
                '      urlPrefix: ""',
                '    nl:',
                '      label: Nederlands',
                '      urlPrefix: nl',
            ],
        );
        $config = BuildConfig::fromProjectRoot($projectRoot, false);
        $handler = new StaticPageRequestHandler($config);

        $response = $handler->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/nl/missing/'),
        );

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('Dutch 404', (string)$response->getBody());
    }

    /**
     * Ensure root-language requests fall back to /404.html even when i18n is enabled.
     *
     * The default language has an empty urlPrefix, so requests that are not prefixed
     * with a known language code should still serve the global public/404.html.
     */
    public function testHandleServesRootNotFoundPageForDefaultLanguageWhenI18nEnabled(): void
    {
        $projectRoot = $this->createStaticProject(
            ['nl/404.html' => '<html><body>Dutch 404</body></html>'],
            [
                'i18n:',
                '  defaultLanguage: en',
                '  languages:',
                '    en:',
                '      label: English',
                '      urlPrefix: ""',
                '    nl:',
                '      label: Nederlands',
                '      urlPrefix: nl',
            ],
        );
        $config = BuildConfig::fromProjectRoot($projectRoot, false);
        $handler = new StaticPageRequestHandler($config);

        $response = $handler->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/missing/'),
        );

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('Custom 404', (string)$response->getBody());
    }
}
