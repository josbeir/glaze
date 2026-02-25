<?php
declare(strict_types=1);

namespace Glaze\Serve;

use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Starts and manages the optional Vite development server process.
 */
final class ViteProcessService implements ServeProcessInterface
{
    /**
     * Start Vite for the given project root.
     *
     * @param object $configuration Process-specific configuration.
     * @param string $workingDirectory Project root path used as working directory.
     * @return \Symfony\Component\Process\Process Running Vite process instance.
     */
    public function start(object $configuration, string $workingDirectory): Process
    {
        if (!$configuration instanceof ViteServeConfig) {
            throw new InvalidArgumentException(sprintf(
                'Invalid configuration type for %s. Expected %s.',
                self::class,
                ViteServeConfig::class,
            ));
        }

        $process = Process::fromShellCommandline($configuration->commandLine(), $workingDirectory);
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
            $configuration->commandLine(),
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
}
