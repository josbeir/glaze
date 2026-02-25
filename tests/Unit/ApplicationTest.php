<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit;

use Cake\Console\CommandCollection;
use Glaze\Application;
use Glaze\Image\ImageTransformerInterface;
use PHPUnit\Framework\TestCase;

/**
 * Tests for application container and command registration.
 */
final class ApplicationTest extends TestCase
{
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
}
