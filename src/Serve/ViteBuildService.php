<?php
declare(strict_types=1);

namespace Glaze\Serve;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Runs Vite build commands as part of static site builds.
 */
final class ViteBuildService
{
    /**
     * Run the configured Vite build command.
     *
     * @param \Glaze\Serve\ViteBuildConfig $configuration Vite build configuration.
     * @param string $workingDirectory Project root path used as working directory.
     */
    public function run(ViteBuildConfig $configuration, string $workingDirectory): void
    {
        $process = Process::fromShellCommandline($configuration->command, $workingDirectory);
        $process->setTimeout(null);
        $process->run();

        if ($process->isSuccessful()) {
            return;
        }

        $errorOutput = trim($process->getErrorOutput());
        $stdOutput = trim($process->getOutput());
        $details = $errorOutput !== '' ? $errorOutput : $stdOutput;

        throw new RuntimeException(sprintf(
            'Failed to run Vite build command "%s".%s',
            $configuration->command,
            $details !== '' ? ' ' . $details : '',
        ));
    }
}
