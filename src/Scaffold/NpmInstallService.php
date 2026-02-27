<?php
declare(strict_types=1);

namespace Glaze\Scaffold;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Installs Node dependencies for scaffolded projects.
 */
final class NpmInstallService
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
     * @param string $workingDirectory Project directory where dependencies should be installed.
     */
    public function install(string $workingDirectory): void
    {
        $process = Process::fromShellCommandline($this->command, $workingDirectory);
        $process->setTimeout(null);
        $process->run();

        if ($process->isSuccessful()) {
            return;
        }

        $errorOutput = trim($process->getErrorOutput());
        $stdOutput = trim($process->getOutput());
        $details = $errorOutput !== '' ? $errorOutput : $stdOutput;

        throw new RuntimeException(sprintf(
            'Failed to run npm install command "%s".%s',
            $this->command,
            $details !== '' ? ' ' . $details : '',
        ));
    }
}
