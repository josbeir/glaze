<?php
declare(strict_types=1);

namespace Glaze\Command;

use Cake\Console\Arguments;
use Cake\Console\BaseCommand;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Core\Configure;
use Glaze\Build\SiteBuilder;
use Glaze\Config\BuildConfig;
use Glaze\Config\ProjectConfigurationReader;
use Glaze\Process\PhpServerProcess;
use Glaze\Process\ViteServeProcess;
use Glaze\Utility\Normalization;
use Glaze\Utility\Path;
use Glaze\Utility\ProjectRootResolver;
use InvalidArgumentException;
use RuntimeException;

/**
 * Serve generated static files using the PHP built-in web server.
 */
final class ServeCommand extends BaseCommand
{
    /**
     * Constructor.
     *
     * @param \Glaze\Build\SiteBuilder $siteBuilder Site builder service.
     * @param \Glaze\Process\ViteServeProcess $viteServeProcess Vite process service.
     * @param \Glaze\Process\PhpServerProcess $phpServerProcess PHP server process.
     */
    public function __construct(
        protected SiteBuilder $siteBuilder,
        protected ViteServeProcess $viteServeProcess,
        protected PhpServerProcess $phpServerProcess,
    ) {
        parent::__construct();
    }

    /**
     * Get command description text.
     */
    public static function getDescription(): string
    {
        return 'Start a local PHP web server for live testing/debugging.';
    }

    /**
     * Configure command options.
     *
     * @param \Cake\Console\ConsoleOptionParser $parser Parser instance.
     */
    protected function buildOptionParser(ConsoleOptionParser $parser): ConsoleOptionParser
    {
        $parser
            ->addOption('root', [
                'help' => 'Project root directory containing public/.',
                'default' => null,
            ])
            ->addOption('host', [
                'help' => 'Host interface to bind the server to (defaults to devServer.php.host or 127.0.0.1).',
                'default' => null,
            ])
            ->addOption('port', [
                'help' => 'Port to bind the server to (defaults to devServer.php.port or 8080).',
                'default' => null,
            ])
            ->addOption('static', [
                'help' => 'Serve prebuilt public/ files instead of live rendering.',
                'boolean' => true,
                'default' => false,
            ])
            ->addOption('build', [
                'help' => 'Build static files before starting the server (used with --static).',
                'boolean' => true,
                'default' => false,
            ])
            ->addOption('drafts', [
                'help' => 'Include draft pages for build/live rendering (live mode defaults to enabled).',
                'boolean' => true,
                'default' => false,
            ])
            ->addOption('debug', [
                'help' => 'Enable debug mode.',
                'boolean' => true,
                'default' => false,
            ])
            ->addOption('vite', [
                'help' => 'Enable Vite dev server integration in live mode.',
                'boolean' => true,
                'default' => false,
            ])
            ->addOption('vite-host', [
                'help' => 'Vite host interface (defaults to config or 127.0.0.1).',
                'default' => null,
            ])
            ->addOption('vite-port', [
                'help' => 'Vite port (defaults to config or 5173).',
                'default' => null,
            ])
            ->addOption('vite-command', [
                'help' => 'Vite start command. Supports {host} and {port} placeholders.',
                'default' => null,
            ]);

        return $parser;
    }

    /**
     * Execute server command.
     *
     * Loads the merged project configuration into Configure early so that
     * all subsequent option resolution can read directly from Configure
     * rather than performing separate file reads.
     *
     * @param \Cake\Console\Arguments $args Parsed command arguments.
     * @param \Cake\Console\ConsoleIo $io Console IO service.
     */
    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $verbose = (bool)$args->getOption('verbose');

        $projectRoot = ProjectRootResolver::resolve(Path::optional($args->getOption('root')));
        if (!is_dir($projectRoot)) {
            $io->err(sprintf('<error>Project root not found: %s</error>', $projectRoot));

            return self::CODE_ERROR;
        }

        (new ProjectConfigurationReader())->read($projectRoot);

        $isStaticMode = (bool)$args->getOption('static');
        $debug = (bool)$args->getOption('debug');
        $includeDrafts = !$isStaticMode || (bool)$args->getOption('drafts');

        if ((bool)$args->getOption('vite') && $isStaticMode) {
            $io->err('<error>--vite can only be used in live mode (without --static).</error>');

            return self::CODE_ERROR;
        }

        $viteConfiguration = $this->resolveViteConfiguration($args, $isStaticMode);
        /** @var array{enabled: bool, host: string, port: int, command: string} $viteConfiguration */

        if ((bool)$args->getOption('build')) {
            if (!$isStaticMode) {
                $io->err('<error>--build can only be used together with --static.</error>');

                return self::CODE_ERROR;
            }

            try {
                $writtenFiles = $this->siteBuilder->build(
                    BuildConfig::fromProjectRoot($projectRoot, $includeDrafts),
                );
                $io->out(sprintf('Build complete: %d page(s).', count($writtenFiles)));
            } catch (RuntimeException $runtimeException) {
                $io->err(sprintf('<error>%s</error>', $runtimeException->getMessage()));

                return self::CODE_ERROR;
            }
        }

        $staticPublicDir = $projectRoot . DIRECTORY_SEPARATOR . 'public';
        if ($isStaticMode && !is_dir($staticPublicDir)) {
            $io->err(sprintf('<error>Public directory not found: %s</error>', $staticPublicDir));

            return self::CODE_ERROR;
        }

        try {
            $phpServerConfiguration = $this->resolvePhpServerConfiguration(
                $args,
                $projectRoot,
                $verbose,
            );
            /** @var array{host: string, port: int, docRoot: string, projectRoot: string, streamOutput: bool} $phpServerConfiguration */
            $this->phpServerProcess->assertCanRun($phpServerConfiguration);
        } catch (InvalidArgumentException $invalidArgumentException) {
            $io->err(sprintf('<error>%s</error>', $invalidArgumentException->getMessage()));

            return self::CODE_ERROR;
        }

        $address = $this->phpServerProcess->address($phpServerConfiguration);
        if ($verbose) {
            if ($isStaticMode) {
                $io->out(sprintf('Serving static output from %s at http://%s', $staticPublicDir, $address));
            } else {
                $io->out(sprintf('Serving live templates/content from %s at http://%s', $projectRoot, $address));
            }

            if ($viteConfiguration['enabled']) {
                $io->out(sprintf('Vite integration enabled at %s', $this->viteServeProcess->url($viteConfiguration)));
            }

            $io->out('Press Ctrl+C to stop.');
        } else {
            $io->out(sprintf('<info>Glaze development server:</info> http://%s', $address));
        }

        $previousEnvironment = $this->applyEnvironment(
            $this->buildRouterEnvironment($projectRoot, $includeDrafts, $viteConfiguration, $isStaticMode, $debug),
        );

        $viteProcess = null;
        if (!$isStaticMode && $viteConfiguration['enabled']) {
            try {
                $viteProcess = $this->viteServeProcess->start($viteConfiguration, $projectRoot);
            } catch (RuntimeException $runtimeException) {
                $io->err(sprintf('<error>%s</error>', $runtimeException->getMessage()));
                $this->restoreEnvironment($previousEnvironment);

                return self::CODE_ERROR;
            }
        }

        $exitCode = self::CODE_SUCCESS;

        try {
            $exitCode = $this->phpServerProcess->start(
                $phpServerConfiguration,
                $projectRoot,
                static function (string $type, string $buffer) use ($io): void {
                    $line = rtrim($buffer, "\n");
                    if ($line === '') {
                        return;
                    }

                    if ($type === 'err') {
                        $io->err($line);

                        return;
                    }

                    $io->out($line);
                },
            );
        } finally {
            $this->viteServeProcess->stop($viteProcess);
            $this->restoreEnvironment($previousEnvironment);
        }

        return $exitCode;
    }

    /**
     * Build environment variables for the router process.
     *
     * Sets all variables the dev-router reads at boot time. GLAZE_STATIC_MODE
     * tells the router to use StaticPageRequestHandler instead of live rendering.
     *
     * @param string $projectRoot Project root directory.
     * @param bool $includeDrafts Whether draft pages should be included.
     * @param array{enabled: bool, host: string, port: int, command: string} $viteConfiguration Vite runtime configuration.
     * @param bool $isStaticMode Whether static serving mode is active.
     * @param bool $debug Whether diagnostic debug behavior is enabled.
     * @return array<string, string>
     */
    protected function buildRouterEnvironment(
        string $projectRoot,
        bool $includeDrafts,
        array $viteConfiguration,
        bool $isStaticMode,
        bool $debug,
    ): array {
        return [
            'GLAZE_PROJECT_ROOT' => $projectRoot,
            'GLAZE_STATIC_MODE' => $isStaticMode ? '1' : '0',
            'GLAZE_INCLUDE_DRAFTS' => $includeDrafts ? '1' : '0',
            'GLAZE_DEBUG' => $debug ? '1' : '0',
            'GLAZE_VITE_ENABLED' => $viteConfiguration['enabled'] ? '1' : '0',
            'GLAZE_VITE_URL' => $viteConfiguration['enabled'] ? $this->viteServeProcess->url($viteConfiguration) : '',
        ];
    }

    /**
     * Resolve Vite runtime configuration from Configure state and CLI options.
     *
     * Reads devServer.vite values from Configure (populated by the
     * ProjectConfigurationReader call in execute()) and merges with
     * any CLI overrides. When $isStaticMode is true, vite is always disabled
     * regardless of configuration, since static serving does not use Vite.
     *
     * @param \Cake\Console\Arguments $args Parsed CLI arguments.
     * @param bool $isStaticMode Whether static mode is active.
     * @return array{enabled: bool, host: string, port: int, command: string}
     */
    protected function resolveViteConfiguration(Arguments $args, bool $isStaticMode = false): array
    {
        $viteConfig = Configure::read('devServer.vite');
        if (!is_array($viteConfig)) {
            $viteConfig = [];
        }

        $enabledFromConfig = !$isStaticMode && is_bool($viteConfig['enabled'] ?? null) && $viteConfig['enabled'];
        $enabled = (bool)$args->getOption('vite') || $enabledFromConfig;

        $host = Normalization::optionalString($args->getOption('vite-host'))
            ?? Normalization::optionalString($viteConfig['host'] ?? null)
            ?? '127.0.0.1';

        $vitePortOption = $args->getOption('vite-port');
        $vitePortFromCli = $this->normalizePort($vitePortOption);
        if ($vitePortOption !== null && $vitePortFromCli === null) {
            throw new InvalidArgumentException('Invalid Vite port. Use a number between 1 and 65535.');
        }

        $port = $vitePortFromCli
            ?? $this->normalizePort($viteConfig['port'] ?? null)
            ?? 5173;

        $command = Normalization::optionalString($args->getOption('vite-command'))
            ?? Normalization::optionalString($viteConfig['command'] ?? null)
            ?? 'npm run dev -- --host {host} --port {port} --strictPort';

        return [
            'enabled' => $enabled,
            'host' => $host,
            'port' => $port,
            'command' => $command,
        ];
    }

    /**
     * Resolve PHP server runtime configuration from Configure state and CLI options.
     *
     * @param \Cake\Console\Arguments $args Parsed CLI arguments.
     * @param string $projectRoot Project root directory (used as both docRoot and projectRoot).
     * @param bool $streamOutput Whether process output should be streamed.
     * @return array{host: string, port: int, docRoot: string, projectRoot: string, streamOutput: bool}
     */
    protected function resolvePhpServerConfiguration(
        Arguments $args,
        string $projectRoot,
        bool $streamOutput = false,
    ): array {
        $phpConfig = Configure::read('devServer.php');
        if (!is_array($phpConfig)) {
            $phpConfig = [];
        }

        $host = Normalization::optionalString($args->getOption('host'))
            ?? Normalization::optionalString($phpConfig['host'] ?? null)
            ?? '127.0.0.1';

        $phpPortOption = $args->getOption('port');
        $phpPortFromCli = $this->normalizePort($phpPortOption);
        if ($phpPortOption !== null && $phpPortFromCli === null) {
            throw new InvalidArgumentException('Invalid port. Use a number between 1 and 65535.');
        }

        $port = $phpPortFromCli
            ?? $this->normalizePort($phpConfig['port'] ?? null)
            ?? 8080;

        return [
            'host' => $host,
            'port' => $port,
            'docRoot' => $projectRoot,
            'projectRoot' => $projectRoot,
            'streamOutput' => $streamOutput,
        ];
    }

    /**
     * Apply environment variables and capture previous values.
     *
     * @param array<string, string> $environment Environment variables to apply.
     * @return array<string, string|null> Previous environment values.
     */
    protected function applyEnvironment(array $environment): array
    {
        $previous = [];

        foreach ($environment as $key => $value) {
            $current = getenv($key);
            $previous[$key] = is_string($current) ? $current : null;
            putenv($key . '=' . $value);
        }

        return $previous;
    }

    /**
     * Restore previously captured environment values.
     *
     * @param array<string, string|null> $previousEnvironment Previous environment values.
     */
    protected function restoreEnvironment(array $previousEnvironment): void
    {
        foreach ($previousEnvironment as $key => $value) {
            if ($value === null) {
                putenv($key);
                continue;
            }

            putenv($key . '=' . $value);
        }
    }

    /**
     * Normalize and validate user-supplied port value.
     *
     * @param mixed $value Port option value.
     */
    protected function normalizePort(mixed $value): ?int
    {
        $port = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => [
                'min_range' => 1,
                'max_range' => 65535,
            ],
        ]);

        return $port === false ? null : $port;
    }
}
