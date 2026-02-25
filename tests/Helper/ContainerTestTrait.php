<?php
declare(strict_types=1);

namespace Glaze\Tests\Helper;

use Cake\Core\ContainerInterface;
use Glaze\Application;

/**
 * Provides access to the application dependency injection container in tests.
 */
trait ContainerTestTrait
{
    protected ?ContainerInterface $testContainer = null;

    /**
     * Return a cached application container for service resolution.
     */
    protected function container(): ContainerInterface
    {
        if ($this->testContainer instanceof ContainerInterface) {
            return $this->testContainer;
        }

        $application = new Application();
        $this->testContainer = $application->getContainer();

        return $this->testContainer;
    }

    /**
     * Resolve a service from the application container.
     *
     * @template TService of object
     * @param class-string<TService> $serviceId Service class name.
     * @return TService
     */
    protected function service(string $serviceId): object
    {
        /** @var TService $service */
        $service = $this->container()->get($serviceId);

        return $service;
    }
}
