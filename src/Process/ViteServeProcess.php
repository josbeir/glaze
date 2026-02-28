<?php
declare(strict_types=1);

namespace Glaze\Process;

use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Starts and manages the optional Vite development server process.
 */
final class ViteServeProcess implements ProcessInterface
{
    /**
     * Start Vite for the given project root.
     *
     * @param array<string, mixed> $configuration Process-specific configuration.
     * @param string $workingDirectory Project root path used as working directory.
     * @return \Symfony\Component\Process\Process Running Vite process instance.
     */
    public function start(array $configuration, string $workingDirectory): Process
    {
        $commandLine = $this->commandLine($configuration);

        $process = Process::fromShellCommandline($commandLine, $workingDirectory);
        $process->setTimeout(null);
        $process->start();

        usleep(350000);

        if ($process->isRunning()) {
            return $process;
        }

        $errorOutput = trim($process->getErrorOutput());
        $stdOutput = trim($process->getOutput());
        $details = $errorOutput !== '' ? $errorOutput : $stdOutput;

        throw new RuntimeException(sprintf(
            'Failed to start Vite process using command "%s".%s',
            $commandLine,
            $details !== '' ? ' ' . $details : '',
        ));
    }

    /**
     * Stop a running Vite process.
     *
     * @param mixed $runtime Vite process runtime handle.
     */
    public function stop(mixed $runtime): void
    {
        $process = $runtime;
        if (!$process instanceof Process || !$process->isRunning()) {
            return;
        }

        $process->stop(3.0);
    }

    /**
     * Build final command line for starting Vite.
     *
     * @param array<string, mixed> $configuration Vite runtime configuration.
     */
    public function commandLine(array $configuration): string
    {
        $this->assertConfiguration($configuration);

        return strtr($configuration['command'], [
            '{host}' => $configuration['host'],
            '{port}' => (string)$configuration['port'],
        ]);
    }

    /**
     * Return Vite server URL.
     *
     * @param array<string, mixed> $configuration Vite runtime configuration.
     */
    public function url(array $configuration): string
    {
        $this->assertConfiguration($configuration);

        return sprintf('http://%s:%d', $configuration['host'], $configuration['port']);
    }

    /**
     * Validate configuration payload.
     *
     * @param array<string, mixed> $configuration Vite runtime configuration.
     * @phpstan-assert array{host: string, port: int, command: string} $configuration
     */
    protected function assertConfiguration(array $configuration): void
    {
        if (
            !array_key_exists('host', $configuration)
            || !is_string($configuration['host'])
            || trim($configuration['host']) === ''
        ) {
            throw new InvalidArgumentException(sprintf(
                'Invalid configuration for %s. Missing or invalid value for "host".',
                self::class,
            ));
        }

        if (!array_key_exists('port', $configuration) || !is_int($configuration['port'])) {
            throw new InvalidArgumentException(sprintf(
                'Invalid configuration for %s. Missing or invalid value for "port".',
                self::class,
            ));
        }

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
