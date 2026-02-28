<?php
declare(strict_types=1);

namespace Glaze\Process;

use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Installs Node dependencies for scaffolded projects.
 */
final class NpmInstallProcess implements ProcessInterface
{
    /**
     * Constructor.
     *
     * @param string $command Command template used to install Node dependencies.
     */
    public function __construct(
        protected string $command = 'npm install',
    ) {
    }

    /**
     * Run npm install in the provided project directory.
     *
     * @param array<string, mixed> $configuration Optional configuration overrides.
     * @param string $workingDirectory Project directory where dependencies should be installed.
     */
    public function start(array $configuration, string $workingDirectory): int
    {
        $this->assertConfiguration($configuration);

        $command = $configuration['command'] ?? $this->command;

        $process = Process::fromShellCommandline($command, $workingDirectory);
        $process->setTimeout(null);
        $process->run();

        if ($process->isSuccessful()) {
            return $process->getExitCode() ?? 0;
        }

        $errorOutput = trim($process->getErrorOutput());
        $stdOutput = trim($process->getOutput());
        $details = $errorOutput !== '' ? $errorOutput : $stdOutput;

        throw new RuntimeException(sprintf(
            'Failed to run npm install command "%s".%s',
            $command,
            $details !== '' ? ' ' . $details : '',
        ));
    }

    /**
     * Stop previously started process runtime.
     *
     * @param mixed $runtime Runtime handle returned by `start()`.
     */
    public function stop(mixed $runtime): void
    {
    }

    /**
     * Validate configuration payload.
     *
     * @param array<string, mixed> $configuration Optional configuration overrides.
     * @phpstan-assert array{command?: string} $configuration
     */
    protected function assertConfiguration(array $configuration): void
    {
        if (!array_key_exists('command', $configuration)) {
            return;
        }

        if (!is_string($configuration['command']) || trim($configuration['command']) === '') {
            throw new InvalidArgumentException(sprintf(
                'Invalid configuration for %s. Missing or invalid value for "command".',
                self::class,
            ));
        }
    }
}
