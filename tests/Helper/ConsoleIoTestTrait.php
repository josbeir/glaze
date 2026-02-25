<?php
declare(strict_types=1);

namespace Glaze\Tests\Helper;

use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOutput;
use RuntimeException;

/**
 * Provides a silent ConsoleIo instance for unit tests.
 */
trait ConsoleIoTestTrait
{
    /**
     * Create a ConsoleIo instance that writes to temporary streams.
     */
    protected function createConsoleIo(): ConsoleIo
    {
        $stdoutPath = tempnam(sys_get_temp_dir(), 'glaze-test-out-');
        $stderrPath = tempnam(sys_get_temp_dir(), 'glaze-test-err-');

        if (!is_string($stdoutPath) || !is_string($stderrPath)) {
            throw new RuntimeException('Unable to create temporary ConsoleIo streams.');
        }

        return new ConsoleIo(
            new ConsoleOutput($stdoutPath),
            new ConsoleOutput($stderrPath),
        );
    }
}
