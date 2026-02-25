<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Config;

use Glaze\Config\BuildConfigFactory;
use Glaze\Config\ProjectConfigurationReader;
use Glaze\Tests\Helper\FilesystemTestTrait;
use PHPUnit\Framework\TestCase;

/**
 * Tests for build configuration factory service.
 */
final class BuildConfigFactoryTest extends TestCase
{
    use FilesystemTestTrait;

    /**
     * Ensure factory delegates project configuration loading through the injected reader.
     */
    public function testFromProjectRootLoadsConfigurationFromInjectedReader(): void
    {
        $projectRoot = $this->createTempDirectory();
        file_put_contents($projectRoot . '/glaze.neon', "pageTemplate: landing\n");

        $factory = new BuildConfigFactory(new ProjectConfigurationReader());
        $config = $factory->fromProjectRoot($projectRoot);

        $this->assertSame('landing', $config->pageTemplate);
    }

    /**
     * Ensure factory forwards include-drafts mode to created configuration objects.
     */
    public function testFromProjectRootSupportsDraftInclusion(): void
    {
        $projectRoot = $this->createTempDirectory();

        $factory = new BuildConfigFactory(new ProjectConfigurationReader());
        $config = $factory->fromProjectRoot($projectRoot, true);

        $this->assertTrue($config->includeDrafts);
    }
}
