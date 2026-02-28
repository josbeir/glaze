<?php
declare(strict_types=1);

namespace Glaze\Process;

use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Runs Vite build commands as part of static site builds.
 */
final class ViteBuildProcess implements ProcessInterface
{
    /**
     * Run the configured Vite build command.
     *
     * @param array<string, mixed> $configuration Vite build configuration.
     * @param string $workingDirectory Project root path used as working directory.
     */
    public function start(array $configuration, string $workingDirectory): int
    {
        $this->assertConfiguration($configuration);

        $process = Process::fromShellCommandline($configuration['command'], $workingDirectory);
        $process->setTimeout(null);
        $process->run();

        if ($process->isSuccessful()) {
            return $process->getExitCode() ?? 0;
        }

        $errorOutput = trim($process->getErrorOutput());
        $stdOutput = trim($process->getOutput());
        $details = $errorOutput !== '' ? $errorOutput : $stdOutput;

        throw new RuntimeException(sprintf(
            'Failed to run Vite build command "%s".%s',
            $configuration['command'],
            $details !== '' ? ' ' . $details : '',
        ));
    }

    /**
     * Stop previously started process runtime.
     *
     * @param mixed $runtime Runtime handle.
     */
    public function stop(mixed $runtime): void
    {
    }

    /**
     * Validate configuration payload.
     *
     * @param array<string, mixed> $configuration Vite build configuration.
     * @phpstan-assert array{command: string} $configuration
     */
    protected function assertConfiguration(array $configuration): void
    {
        if (
            !array_key_exists('command', $configuration)
            || !is_string($configuration['command'])
            || trim($configuration['command']) === ''
        ) {
            throw new InvalidArgumentException(sprintf(
                'Invalid configuration for %s. Missing or invalid value for "command".',
                self::class,
            ));
        }
    }
}
