<?php
declare(strict_types=1);

namespace Glaze\Serve;

use Glaze\Utility\Normalization;
use InvalidArgumentException;

/**
 * Manages PHP built-in web server command creation and execution.
 */
final class PhpServerService implements ServeProcessInterface
{
    /**
     * Start PHP server runtime for shared process interface compatibility.
     *
     * @param object $configuration Process-specific configuration.
     * @param string $workingDirectory Unused working directory argument.
     */
    public function start(object $configuration, string $workingDirectory): int
    {
        if (!$configuration instanceof PhpServerConfig) {
            throw new InvalidArgumentException(sprintf(
                'Invalid configuration type for %s. Expected %s.',
                self::class,
                PhpServerConfig::class,
            ));
        }

        $command = $this->buildCommand($configuration);
        passthru($command, $exitCode);

        return $exitCode;
    }

    /**
     * Stop PHP server runtime for shared process interface compatibility.
     *
     * The PHP built-in server run is blocking and does not expose a detachable
     * runtime handle in this service, so this method is intentionally a no-op.
     *
     * @param mixed $runtime Runtime handle.
     */
    public function stop(mixed $runtime): void
    {
    }

    /**
     * Ensure the PHP server can be started for the provided configuration.
     *
     * @param \Glaze\Serve\PhpServerConfig $config Server configuration.
     */
    public function assertCanRun(PhpServerConfig $config): void
    {
        $this->buildCommand($config);
    }

    /**
     * Build command string for the PHP built-in server.
     *
     * @param \Glaze\Serve\PhpServerConfig $config Server configuration.
     */
    protected function buildCommand(PhpServerConfig $config): string
    {
        if ($config->staticMode) {
            return sprintf(
                'php -S %s -t %s',
                escapeshellarg($config->address()),
                escapeshellarg($config->docRoot),
            );
        }

        $routerPath = $this->resolveLiveRouterPath($config->projectRoot);
        if (!is_string($routerPath)) {
            throw new InvalidArgumentException(sprintf(
                'Live router script not found: %s',
                $config->projectRoot . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'dev-router.php',
            ));
        }

        return sprintf(
            'php -S %s -t %s %s',
            escapeshellarg($config->address()),
            escapeshellarg($config->docRoot),
            escapeshellarg($routerPath),
        );
    }

    /**
     * Resolve live router path for the given project root.
     *
     * @param string $projectRoot Project root directory.
     */
    protected function resolveLiveRouterPath(string $projectRoot): ?string
    {
        $projectRouterPath = $projectRoot . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'dev-router.php';
        if (is_file($projectRouterPath)) {
            return $projectRouterPath;
        }

        if (!is_file($projectRoot . DIRECTORY_SEPARATOR . 'glaze.neon')) {
            return null;
        }

        $cliRoot = Normalization::optionalPath(getenv('GLAZE_CLI_ROOT') ?: null);
        if ($cliRoot !== null) {
            $cliRouterPath = $cliRoot . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'dev-router.php';
            if (is_file($cliRouterPath)) {
                return $cliRouterPath;
            }
        }

        $packageRoot = dirname(__DIR__, 2);
        $packageRouterPath = $packageRoot . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'dev-router.php';

        return is_file($packageRouterPath) ? $packageRouterPath : null;
    }
}
