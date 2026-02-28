<?php
declare(strict_types=1);

namespace Glaze\Process;

use Glaze\Utility\Normalization;
use InvalidArgumentException;
use Symfony\Component\Process\Process;

/**
 * Manages PHP built-in web server command creation and execution.
 */
final class PhpServerProcess implements ProcessInterface
{
    /**
     * Start PHP server runtime for shared process interface compatibility.
     *
     * When `streamOutput` is enabled on the configuration, process output is forwarded
     * to the provided `$outputCallback`. If no callback is given, the default behaviour
     * forwards stdout to `STDOUT` and stderr to `STDERR`.
     *
     * @param array<string, mixed> $configuration Process-specific configuration.
     * @param string $workingDirectory Unused working directory argument.
     * @param callable|null $outputCallback Optional output callback `(string $type, string $buffer): void`.
     */
    public function start(array $configuration, string $workingDirectory, ?callable $outputCallback = null): int
    {
        $this->assertConfiguration($configuration);

        $command = $this->buildCommand($configuration);
        $process = Process::fromShellCommandline(
            $command,
            $workingDirectory,
            $this->forwardedEnvironmentVariables(),
        );
        $process->setTimeout(null);

        $streamOutput = $configuration['streamOutput'] ?? false;
        if ($streamOutput) {
            $callback = $outputCallback ?? static function (string $type, string $buffer): void {
                if ($type === Process::ERR) {
                    fwrite(STDERR, $buffer);

                    return;
                }

                fwrite(STDOUT, $buffer);
            };
            $process->run($callback);
        } else {
            $process->run();
        }

        return $process->getExitCode() ?? 1;
    }

    /**
     * Collect environment variables that must be forwarded to the server process.
     *
     * @return array<string, string>
     */
    protected function forwardedEnvironmentVariables(): array
    {
        $variables = [
            'GLAZE_PROJECT_ROOT',
            'GLAZE_INCLUDE_DRAFTS',
            'GLAZE_VITE_ENABLED',
            'GLAZE_VITE_URL',
            'GLAZE_CLI_ROOT',
        ];

        $environment = [];
        foreach ($variables as $name) {
            $value = getenv($name);
            if (!is_string($value)) {
                continue;
            }

            if ($value === '') {
                continue;
            }

            $environment[$name] = $value;
        }

        return $environment;
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
     * @param array<string, mixed> $configuration Server configuration.
     */
    public function assertCanRun(array $configuration): void
    {
        $this->assertConfiguration($configuration);
        $this->buildCommand($configuration);
    }

    /**
     * Return host:port address string.
     *
     * @param array<string, mixed> $configuration Server configuration.
     */
    public function address(array $configuration): string
    {
        $this->assertConfiguration($configuration);

        return $this->addressFromConfiguration($configuration);
    }

    /**
     * Build command string for the PHP built-in server.
     *
     * @param array<string, mixed> $configuration Server configuration.
     */
    protected function buildCommand(array $configuration): string
    {
        $this->assertConfiguration($configuration);

        if ($configuration['staticMode']) {
            return sprintf(
                'php -S %s -t %s',
                escapeshellarg($this->addressFromConfiguration($configuration)),
                escapeshellarg($configuration['docRoot']),
            );
        }

        $routerPath = $this->resolveLiveRouterPath($configuration['projectRoot']);
        if (!is_string($routerPath)) {
            throw new InvalidArgumentException(sprintf(
                'Live router script not found: %s',
                $configuration['projectRoot'] . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'dev-router.php',
            ));
        }

        return sprintf(
            'php -S %s -t %s %s',
            escapeshellarg($this->addressFromConfiguration($configuration)),
            escapeshellarg($configuration['docRoot']),
            escapeshellarg($routerPath),
        );
    }

    /**
     * Create host:port address from configuration.
     *
     * @param array<string, mixed> $configuration Server configuration.
     */
    protected function addressFromConfiguration(array $configuration): string
    {
        $this->assertConfiguration($configuration);

        return $configuration['host'] . ':' . $configuration['port'];
    }

    /**
     * Validate configuration payload.
     *
     * @param array<string, mixed> $configuration Server configuration.
     * @phpstan-assert array{host: string, port: int, docRoot: string, projectRoot: string, staticMode: bool, streamOutput?: bool} $configuration
     */
    protected function assertConfiguration(array $configuration): void
    {
        foreach (['host', 'docRoot', 'projectRoot'] as $key) {
            if (
                !array_key_exists($key, $configuration)
                || !is_string($configuration[$key])
                || trim($configuration[$key]) === ''
            ) {
                throw new InvalidArgumentException(sprintf(
                    'Invalid configuration for %s. Missing or invalid value for "%s".',
                    self::class,
                    $key,
                ));
            }
        }

        if (!array_key_exists('port', $configuration) || !is_int($configuration['port'])) {
            throw new InvalidArgumentException(sprintf(
                'Invalid configuration for %s. Missing or invalid value for "port".',
                self::class,
            ));
        }

        if (!array_key_exists('staticMode', $configuration) || !is_bool($configuration['staticMode'])) {
            throw new InvalidArgumentException(sprintf(
                'Invalid configuration for %s. Missing or invalid value for "staticMode".',
                self::class,
            ));
        }

        if (array_key_exists('streamOutput', $configuration) && !is_bool($configuration['streamOutput'])) {
            throw new InvalidArgumentException(sprintf(
                'Invalid configuration for %s. Missing or invalid value for "streamOutput".',
                self::class,
            ));
        }
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
