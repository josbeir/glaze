<?php
declare(strict_types=1);

namespace Glaze\Process;

/**
 * Shared lifecycle contract for background/runtime processes.
 */
interface ProcessInterface
{
    /**
     * Start process runtime for the given configuration.
     *
     * @param array<string, mixed> $configuration Process-specific configuration.
     * @param string $workingDirectory Working directory to use while starting.
     * @return mixed Process runtime handle or exit code, depending on implementation.
     */
    public function start(array $configuration, string $workingDirectory): mixed;

    /**
     * Stop previously started process runtime.
     *
     * @param mixed $runtime Runtime handle returned by `start()`.
     */
    public function stop(mixed $runtime): void;
}
