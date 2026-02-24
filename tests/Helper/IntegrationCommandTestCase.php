<?php
declare(strict_types=1);

namespace Glaze\Tests\Helper;

use Cake\Console\TestSuite\ConsoleIntegrationTestTrait;
use Cake\Core\ConsoleApplicationInterface;
use Glaze\Application;
use PHPUnit\Framework\TestCase;

/**
 * Base class for command integration tests with shared app and filesystem helpers.
 */
abstract class IntegrationCommandTestCase extends TestCase
{
    use ConsoleIntegrationTestTrait;
    use FilesystemTestTrait;

    /**
     * Return the console application for command runner tests.
     */
    protected function createApp(): ConsoleApplicationInterface
    {
        return new Application();
    }
}
