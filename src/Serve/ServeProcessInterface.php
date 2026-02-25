<?php
declare(strict_types=1);

namespace Glaze\Serve;

/**
 * Shared lifecycle contract for serve-related background/runtime processes.
 */
interface ServeProcessInterface
{
    /**
     * Start process runtime for the given configuration.
     *
     * @param object $configuration Process-specific configuration value object.
     * @param string $workingDirectory Working directory to use while starting.
     * @return mixed Process runtime handle or exit code, depending on implementation.
     */
    public function start(object $configuration, string $workingDirectory): mixed;

    /**
     * Stop previously started process runtime.
     *
     * @param mixed $runtime Runtime handle returned by `start()`.
     */
    public function stop(mixed $runtime): void;
}
