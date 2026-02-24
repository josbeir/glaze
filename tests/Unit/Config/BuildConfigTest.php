<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Config;

use Glaze\Config\BuildConfig;
use PHPUnit\Framework\TestCase;

/**
 * Tests for build configuration path resolution.
 */
final class BuildConfigTest extends TestCase
{
    /**
     * Ensure default directories resolve from project root.
     */
    public function testDefaultPathsResolveFromRoot(): void
    {
        $config = BuildConfig::fromProjectRoot('/tmp/glaze-project');

        $this->assertSame('/tmp/glaze-project/content', $config->contentPath());
        $this->assertSame('/tmp/glaze-project/templates', $config->templatePath());
        $this->assertSame('/tmp/glaze-project/public', $config->outputPath());
        $this->assertSame('/tmp/glaze-project/tmp/cache', $config->cachePath());
        $this->assertFalse($config->includeDrafts);
    }

    /**
     * Ensure draft inclusion can be configured at config creation time.
     */
    public function testIncludeDraftsCanBeEnabled(): void
    {
        $config = BuildConfig::fromProjectRoot('/tmp/glaze-project', true);

        $this->assertTrue($config->includeDrafts);
    }
}
