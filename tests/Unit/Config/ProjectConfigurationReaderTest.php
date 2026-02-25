<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Config;

use Glaze\Config\ProjectConfigurationReader;
use Glaze\Tests\Helper\FilesystemTestTrait;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Tests for shared project configuration reader service.
 */
final class ProjectConfigurationReaderTest extends TestCase
{
    use FilesystemTestTrait;

    /**
     * Ensure missing configuration files resolve to an empty map.
     */
    public function testReadReturnsEmptyArrayWhenConfigurationIsMissing(): void
    {
        $reader = new ProjectConfigurationReader();
        $projectRoot = $this->createTempDirectory();

        $configuration = $reader->read($projectRoot);

        $this->assertSame([], $configuration);
    }

    /**
     * Ensure invalid NEON content raises a runtime exception.
     */
    public function testReadThrowsRuntimeExceptionForInvalidNeon(): void
    {
        $reader = new ProjectConfigurationReader();
        $projectRoot = $this->createTempDirectory();
        file_put_contents($projectRoot . '/glaze.neon', "site:\n  title: [\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid project configuration');

        $reader->read($projectRoot);
    }

    /**
     * Ensure cache is refreshed when the configuration file metadata changes.
     */
    public function testReadRefreshesCacheWhenConfigurationChanges(): void
    {
        $reader = new ProjectConfigurationReader();
        $projectRoot = $this->createTempDirectory();
        $configurationPath = $projectRoot . '/glaze.neon';

        file_put_contents($configurationPath, "devServer:\n  php:\n    port: 8081\n");
        $first = $reader->read($projectRoot);

        $this->assertArrayHasKey('devServer', $first);
        $this->assertIsArray($first['devServer']);
        /** @var array<string, mixed> $firstDevServer */
        $firstDevServer = $first['devServer'];
        $this->assertArrayHasKey('php', $firstDevServer);
        $this->assertIsArray($firstDevServer['php']);
        /** @var array<string, mixed> $firstPhp */
        $firstPhp = $firstDevServer['php'];
        $this->assertSame(8081, $firstPhp['port']);

        file_put_contents($configurationPath, "devServer:\n  php:\n    port: 18081\n");
        $second = $reader->read($projectRoot);

        $this->assertArrayHasKey('devServer', $second);
        $this->assertIsArray($second['devServer']);
        /** @var array<string, mixed> $secondDevServer */
        $secondDevServer = $second['devServer'];
        $this->assertArrayHasKey('php', $secondDevServer);
        $this->assertIsArray($secondDevServer['php']);
        /** @var array<string, mixed> $secondPhp */
        $secondPhp = $secondDevServer['php'];
        $this->assertSame(18081, $secondPhp['port']);
    }

    /**
     * Ensure scalar root configuration is normalized to an empty map.
     */
    public function testReadReturnsEmptyArrayForScalarRootConfiguration(): void
    {
        $reader = new ProjectConfigurationReader();
        $projectRoot = $this->createTempDirectory();
        file_put_contents($projectRoot . '/glaze.neon', "true\n");

        $configuration = $reader->read($projectRoot);

        $this->assertSame([], $configuration);
    }

    /**
     * Ensure only string keys are retained during root-map normalization.
     */
    public function testReadFiltersNonStringRootKeys(): void
    {
        $reader = new ProjectConfigurationReader();
        $projectRoot = $this->createTempDirectory();
        file_put_contents($projectRoot . '/glaze.neon', "site:\n  title: Example\n1: ignored\n");

        $configuration = $reader->read($projectRoot);

        $this->assertArrayHasKey('site', $configuration);
        $this->assertArrayNotHasKey(1, $configuration);
    }
}
