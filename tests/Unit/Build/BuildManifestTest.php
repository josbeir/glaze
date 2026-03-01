<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Build;

use Glaze\Build\BuildManifest;
use Glaze\Config\BuildConfig;
use Glaze\Content\ContentPage;
use Glaze\Tests\Helper\FilesystemTestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Tests for incremental build manifest persistence and diffing.
 */
final class BuildManifestTest extends TestCase
{
    use FilesystemTestTrait;

    /**
     * Ensure manifests can be saved and loaded with identical state.
     */
    public function testSaveAndLoadRoundTrip(): void
    {
        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/content', 0755, true);
        mkdir($projectRoot . '/static', 0755, true);
        mkdir($projectRoot . '/templates', 0755, true);
        mkdir($projectRoot . '/extensions', 0755, true);
        file_put_contents($projectRoot . '/glaze.neon', "site:\n  title: Test\n");
        file_put_contents($projectRoot . '/content/image.png', 'png-bytes');
        file_put_contents($projectRoot . '/static/app.js', 'console.log("ok");');

        $config = BuildConfig::fromProjectRoot($projectRoot);
        $manifest = BuildManifest::fromBuild($config, [
            $this->createPage('/a/', 'a/index.html', '# A'),
            $this->createPage('/b/', 'b/index.html', '# B'),
        ]);

        $manifestPath = $config->buildManifestPath();
        $manifest->save($manifestPath);
        $loaded = BuildManifest::load($manifestPath);

        $this->assertInstanceOf(BuildManifest::class, $loaded);
        $this->assertSame($manifest->globalHash, $loaded->globalHash);
        $this->assertSame($manifest->pageBodyHashes, $loaded->pageBodyHashes);
        $this->assertSame($manifest->contentAssetSignatures, $loaded->contentAssetSignatures);
        $this->assertSame($manifest->staticAssetSignatures, $loaded->staticAssetSignatures);
    }

    /**
     * Ensure changedPageOutputPaths() only reports pages with modified body hashes.
     */
    public function testChangedPageOutputPathsReportsOnlyBodyChanges(): void
    {
        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/templates', 0755, true);
        mkdir($projectRoot . '/extensions', 0755, true);
        file_put_contents($projectRoot . '/glaze.neon', "site:\n  title: Test\n");

        $config = BuildConfig::fromProjectRoot($projectRoot);

        $previous = BuildManifest::fromBuild($config, [
            $this->createPage('/a/', 'a/index.html', '# A'),
            $this->createPage('/b/', 'b/index.html', '# B'),
        ]);
        $current = BuildManifest::fromBuild($config, [
            $this->createPage('/a/', 'a/index.html', '# A changed'),
            $this->createPage('/b/', 'b/index.html', '# B'),
        ]);

        $changed = $current->changedPageOutputPaths($previous);

        $this->assertArrayHasKey('a/index.html', $changed);
        $this->assertArrayNotHasKey('b/index.html', $changed);
    }

    /**
     * Ensure orphanedPageOutputPaths() reports pages removed from the current snapshot.
     */
    public function testOrphanedPageOutputPathsReportsRemovedPages(): void
    {
        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/templates', 0755, true);
        mkdir($projectRoot . '/extensions', 0755, true);
        file_put_contents($projectRoot . '/glaze.neon', "site:\n  title: Test\n");

        $config = BuildConfig::fromProjectRoot($projectRoot);

        $previous = BuildManifest::fromBuild($config, [
            $this->createPage('/a/', 'a/index.html', '# A'),
            $this->createPage('/b/', 'b/index.html', '# B'),
        ]);
        $current = BuildManifest::fromBuild($config, [
            $this->createPage('/b/', 'b/index.html', '# B'),
        ]);

        $orphaned = $current->orphanedPageOutputPaths($previous);

        $this->assertSame(['a/index.html'], $orphaned);
    }

    /**
     * Ensure orphaned asset path methods report removed content and static assets.
     */
    public function testOrphanedAssetOutputPathsReportRemovedAssets(): void
    {
        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/content/nested', 0755, true);
        mkdir($projectRoot . '/static/js', 0755, true);
        mkdir($projectRoot . '/templates', 0755, true);
        mkdir($projectRoot . '/extensions', 0755, true);
        file_put_contents($projectRoot . '/glaze.neon', "site:\n  title: Test\n");

        file_put_contents($projectRoot . '/content/nested/keep.png', 'keep');
        file_put_contents($projectRoot . '/content/remove.png', 'remove');
        file_put_contents($projectRoot . '/static/js/keep.js', 'keep');
        file_put_contents($projectRoot . '/static/remove.js', 'remove');

        $config = BuildConfig::fromProjectRoot($projectRoot);
        $pages = [$this->createPage('/a/', 'a/index.html', '# A')];
        $previous = BuildManifest::fromBuild($config, $pages);

        unlink($projectRoot . '/content/remove.png');
        unlink($projectRoot . '/static/remove.js');

        $current = BuildManifest::fromBuild($config, $pages);

        $this->assertSame(['remove.png'], $current->orphanedContentAssetOutputPaths($previous));
        $this->assertSame(['remove.js'], $current->orphanedStaticAssetOutputPaths($previous));
    }

    /**
     * Ensure global hash changes force a full rebuild decision.
     */
    public function testRequiresFullBuildWhenGlobalHashChanges(): void
    {
        $projectRoot = $this->createTempDirectory();
        mkdir($projectRoot . '/templates', 0755, true);
        mkdir($projectRoot . '/extensions', 0755, true);
        file_put_contents($projectRoot . '/glaze.neon', "site:\n  title: Test\n");

        $config = BuildConfig::fromProjectRoot($projectRoot);
        $baseline = BuildManifest::fromBuild($config, [$this->createPage('/a/', 'a/index.html', '# A')]);

        file_put_contents($projectRoot . '/glaze.neon', "site:\n  title: Test changed\n");
        $updatedConfig = BuildConfig::fromProjectRoot($projectRoot);
        $updated = BuildManifest::fromBuild($updatedConfig, [$this->createPage('/a/', 'a/index.html', '# A')]);

        $this->assertTrue($updated->requiresFullBuild($baseline));
    }

    /**
     * Ensure load() returns null when the manifest file contains invalid JSON.
     */
    public function testLoadReturnsNullForInvalidJsonFile(): void
    {
        $path = $this->createTempDirectory() . '/manifest.json';
        file_put_contents($path, 'this is not json {{{');

        $result = BuildManifest::load($path);

        $this->assertNotInstanceOf(BuildManifest::class, $result);
    }

    /**
     * Ensure load() returns null when the JSON root is not an object.
     */
    public function testLoadReturnsNullForNonObjectJson(): void
    {
        $path = $this->createTempDirectory() . '/manifest.json';
        file_put_contents($path, '"just a string"');

        $result = BuildManifest::load($path);

        $this->assertNotInstanceOf(BuildManifest::class, $result);
    }

    /**
     * Ensure load() returns null when required top-level keys are missing.
     */
    public function testLoadReturnsNullForMissingRequiredKeys(): void
    {
        $path = $this->createTempDirectory() . '/manifest.json';
        file_put_contents($path, '{"globalHash": null, "pageBodyHashes": {}}');

        $result = BuildManifest::load($path);

        $this->assertNotInstanceOf(BuildManifest::class, $result);
    }

    /**
     * Ensure load() skips pageBodyHash entries whose values are not strings.
     */
    public function testLoadFiltersNonStringPageBodyHashValues(): void
    {
        $path = $this->createTempDirectory() . '/manifest.json';
        file_put_contents($path, json_encode([
            'globalHash' => 'abc123',
            'pageBodyHashes' => [
                'valid/page.html' => 'hash-ok',
                'bad/page.html' => 42,
            ],
            'contentAssetSignatures' => [],
            'staticAssetSignatures' => [],
        ]));

        $result = BuildManifest::load($path);

        $this->assertInstanceOf(BuildManifest::class, $result);
        $this->assertArrayHasKey('valid/page.html', $result->pageBodyHashes);
        $this->assertArrayNotHasKey('bad/page.html', $result->pageBodyHashes);
    }

    /**
     * Build a simple page fixture.
     *
     * @param string $urlPath Public URL path.
     * @param string $outputRelativePath Relative output file path.
     * @param string $source Djot source.
     */
    protected function createPage(string $urlPath, string $outputRelativePath, string $source): ContentPage
    {
        return new ContentPage(
            sourcePath: '/tmp/' . trim($outputRelativePath, '/'),
            relativePath: trim($outputRelativePath, '/'),
            slug: trim($urlPath, '/'),
            urlPath: $urlPath,
            outputRelativePath: $outputRelativePath,
            title: strtoupper(trim($urlPath, '/')),
            source: $source,
            draft: false,
            meta: [],
            taxonomies: [],
        );
    }
}
