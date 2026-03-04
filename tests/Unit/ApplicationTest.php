<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit;

use Cake\Console\CommandCollection;
use Cake\Core\Configure;
use Glaze\Application;
use Glaze\Config\BuildConfig;
use Glaze\Config\ProjectConfigurationReader;
use Glaze\Image\ImageTransformerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Tests for application container and command registration.
 */
final class ApplicationTest extends TestCase
{
    /**
     * Ensure bootstrap registers the default NEON config engine.
     */
    public function testBootstrapRegistersDefaultNeonEngine(): void
    {
        Configure::drop('default');

        $application = new Application();
        $application->bootstrap();

        $this->assertTrue(Configure::isConfigured('default'));
    }

    /**
     * Ensure bootstrap does not re-register when engine is already configured.
     */
    public function testBootstrapSkipsWhenEngineAlreadyConfigured(): void
    {
        $application = new Application();

        // Should not throw even when called twice.
        $application->bootstrap();
        $application->bootstrap();

        $this->assertTrue(Configure::isConfigured('default'));
    }

    /**
     * Ensure services wiring resolves image transformer interface binding.
     */
    public function testContainerResolvesImageTransformerInterface(): void
    {
        $application = new Application();
        $container = $application->getContainer();

        $service = $container->get(ImageTransformerInterface::class);

        $this->assertInstanceOf(ImageTransformerInterface::class, $service);
    }

    /**
     * Ensure getContainer returns the same cached container instance.
     */
    public function testGetContainerReturnsCachedInstance(): void
    {
        $application = new Application();

        $first = $application->getContainer();
        $second = $application->getContainer();

        $this->assertSame($first, $second);
    }

    /**
     * Ensure console command registration includes all supported commands.
     */
    public function testConsoleRegistersExpectedCommands(): void
    {
        $application = new Application();
        $collection = $application->console(new CommandCollection());

        $this->assertTrue($collection->has('build'));
        $this->assertTrue($collection->has('init'));
        $this->assertTrue($collection->has('new'));
        $this->assertTrue($collection->has('serve'));
    }

    /**
     * Ensure BuildConfig is registered as a shared container service and resolves from Configure state.
     */
    public function testContainerResolvesSharedBuildConfig(): void
    {
        $application = new Application();
        $application->bootstrap();

        $container = $application->getContainer();

        (new ProjectConfigurationReader())->read('/tmp/glaze-project');
        Configure::write('projectRoot', '/tmp/glaze-project');

        $config = $container->get(BuildConfig::class);

        $this->assertInstanceOf(BuildConfig::class, $config);
        $this->assertSame('/tmp/glaze-project', $config->projectRoot);
    }

    /**
     * Ensure the shared BuildConfig returns the same instance on repeated resolution.
     */
    public function testSharedBuildConfigReturnsSameInstance(): void
    {
        $application = new Application();
        $application->bootstrap();

        $container = $application->getContainer();

        (new ProjectConfigurationReader())->read('/tmp/glaze-project');
        Configure::write('projectRoot', '/tmp/glaze-project');

        $first = $container->get(BuildConfig::class);
        $second = $container->get(BuildConfig::class);

        $this->assertSame($first, $second);
    }
}
