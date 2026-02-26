<?php
declare(strict_types=1);

namespace Glaze\Command;

use Cake\Console\Arguments;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Glaze\Build\SiteBuilder;
use Glaze\Config\BuildConfigFactory;
use Glaze\Config\ProjectConfigurationReader;
use Glaze\Serve\PhpServerConfig;
use Glaze\Serve\PhpServerService;
use Glaze\Serve\ViteProcessService;
use Glaze\Serve\ViteServeConfig;
use Glaze\Utility\Normalization;
use Glaze\Utility\ProjectRootResolver;
use InvalidArgumentException;
use RuntimeException;

/**
 * Serve generated static files using the PHP built-in web server.
 */
final class ServeCommand extends AbstractGlazeCommand
{
    /**
     * Constructor.
     *
     * @param \Glaze\Build\SiteBuilder $siteBuilder Site builder service.
     * @param \Glaze\Config\ProjectConfigurationReader $projectConfigurationReader Project configuration reader.
     * @param \Glaze\Serve\ViteProcessService $viteProcessService Vite process service.
     * @param \Glaze\Serve\PhpServerService $phpServerService PHP server service.
     * @param \Glaze\Config\BuildConfigFactory $buildConfigFactory Build configuration factory.
     */
    public function __construct(
        protected SiteBuilder $siteBuilder,
        protected ProjectConfigurationReader $projectConfigurationReader,
        protected ViteProcessService $viteProcessService,
        protected PhpServerService $phpServerService,
        protected BuildConfigFactory $buildConfigFactory,
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
     * @param \Cake\Console\Arguments $args Parsed command arguments.
     * @param \Cake\Console\ConsoleIo $io Console IO service.
     */
    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $verbose = (bool)$args->getOption('verbose');
        if ($verbose) {
            $this->renderVersionHeader($io);
        }

        $projectRoot = ProjectRootResolver::resolve($this->normalizeRootOption($args->getOption('root')));
        if (!is_dir($projectRoot)) {
            $io->err(sprintf('<error>Project root not found: %s</error>', $projectRoot));

            return self::CODE_ERROR;
        }

        $isStaticMode = (bool)$args->getOption('static');
        $includeDrafts = !$isStaticMode || (bool)$args->getOption('drafts');
        $viteConfiguration = $this->resolveViteConfiguration($args, $projectRoot);

        if ($viteConfiguration->enabled && $isStaticMode) {
            $io->err('<error>--vite can only be used in live mode (without --static).</error>');

            return self::CODE_ERROR;
        }

        $docRoot = $isStaticMode ? $projectRoot . DIRECTORY_SEPARATOR . 'public' : $projectRoot;

        if ((bool)$args->getOption('build')) {
            if (!$isStaticMode) {
                $io->err('<error>--build can only be used together with --static.</error>');

                return self::CODE_ERROR;
            }

            try {
                $writtenFiles = $this->siteBuilder->build(
                    $this->buildConfigFactory->fromProjectRoot($projectRoot, $includeDrafts),
                );
                $io->out(sprintf('Build complete: %d page(s).', count($writtenFiles)));
            } catch (RuntimeException $runtimeException) {
                $io->err(sprintf('<error>%s</error>', $runtimeException->getMessage()));

                return self::CODE_ERROR;
            }
        }

        if (!is_dir($docRoot)) {
            $io->err(sprintf('<error>Public directory not found: %s</error>', $docRoot));

            return self::CODE_ERROR;
        }

        try {
            $phpServerConfiguration = $this->resolvePhpServerConfiguration(
                $args,
                $projectRoot,
                $docRoot,
                $isStaticMode,
                $verbose,
            );
            $this->phpServerService->assertCanRun($phpServerConfiguration);
        } catch (InvalidArgumentException $invalidArgumentException) {
            $io->err(sprintf('<error>%s</error>', $invalidArgumentException->getMessage()));

            return self::CODE_ERROR;
        }

        $address = $phpServerConfiguration->address();
        if ($verbose) {
            if ($isStaticMode) {
                $io->out(sprintf('Serving static output from %s at http://%s', $docRoot, $address));
            } else {
                $io->out(sprintf('Serving live templates/content from %s at http://%s', $projectRoot, $address));
            }

            if ($viteConfiguration->enabled) {
                $io->out(sprintf('Vite integration enabled at %s', $viteConfiguration->url()));
            }

            $io->out('Press Ctrl+C to stop.');
        } else {
            $io->out(sprintf('<info>Glaze development server:</info> http://%s', $address));
        }

        $previousEnvironment = [];
        if (!$isStaticMode) {
            $previousEnvironment = $this->applyEnvironment(
                $this->buildLiveEnvironment($projectRoot, $includeDrafts, $viteConfiguration),
            );
        }

        $viteProcess = null;
        if (!$isStaticMode && $viteConfiguration->enabled) {
            try {
                $viteProcess = $this->viteProcessService->start($viteConfiguration, $projectRoot);
            } catch (RuntimeException $runtimeException) {
                $io->err(sprintf('<error>%s</error>', $runtimeException->getMessage()));
                $this->restoreEnvironment($previousEnvironment);

                return self::CODE_ERROR;
            }
        }

        $exitCode = self::CODE_SUCCESS;

        try {
            $exitCode = $this->phpServerService->start(
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
            $this->viteProcessService->stop($viteProcess);

            if (!$isStaticMode) {
                $this->restoreEnvironment($previousEnvironment);
            }
        }

        return $exitCode;
    }

    /**
     * Build environment variables for live router execution.
     *
     * @param string $projectRoot Project root directory.
     * @param bool $includeDrafts Whether draft pages should be included.
     * @param \Glaze\Serve\ViteServeConfig $viteConfiguration Vite runtime configuration.
     * @return array<string, string>
     */
    protected function buildLiveEnvironment(
        string $projectRoot,
        bool $includeDrafts,
        ViteServeConfig $viteConfiguration,
    ): array {
        return [
            'GLAZE_PROJECT_ROOT' => $projectRoot,
            'GLAZE_INCLUDE_DRAFTS' => $includeDrafts ? '1' : '0',
            'GLAZE_VITE_ENABLED' => $viteConfiguration->enabled ? '1' : '0',
            'GLAZE_VITE_URL' => $viteConfiguration->enabled ? $viteConfiguration->url() : '',
        ];
    }

    /**
     * Resolve Vite runtime configuration from project config and CLI options.
     *
     * @param \Cake\Console\Arguments $args Parsed CLI arguments.
     * @param string $projectRoot Project root directory.
     */
    protected function resolveViteConfiguration(Arguments $args, string $projectRoot): ViteServeConfig
    {
        $devServerConfig = $this->readDevServerConfiguration($projectRoot);

        $viteConfig = $devServerConfig['vite'] ?? null;
        if (!is_array($viteConfig)) {
            $viteConfig = [];
        }

        $enabledFromConfig = is_bool($viteConfig['enabled'] ?? null) && $viteConfig['enabled'];
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

        return new ViteServeConfig(
            enabled: $enabled,
            host: $host,
            port: $port,
            command: $command,
        );
    }

    /**
     * Resolve PHP server runtime configuration from project config and CLI options.
     *
     * @param \Cake\Console\Arguments $args Parsed CLI arguments.
     * @param string $projectRoot Project root directory.
     * @param string $docRoot PHP server document root.
     * @param bool $isStaticMode Whether static mode is enabled.
     * @param bool $streamOutput Whether process output should be streamed.
     */
    protected function resolvePhpServerConfiguration(
        Arguments $args,
        string $projectRoot,
        string $docRoot,
        bool $isStaticMode,
        bool $streamOutput = false,
    ): PhpServerConfig {
        $devServerConfig = $this->readDevServerConfiguration($projectRoot);

        $phpConfig = $devServerConfig['php'] ?? null;
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

        return new PhpServerConfig(
            host: $host,
            port: $port,
            docRoot: $docRoot,
            projectRoot: $projectRoot,
            staticMode: $isStaticMode,
            streamOutput: $streamOutput,
        );
    }

    /**
     * Read decoded project configuration from glaze.neon.
     *
     * @param string $projectRoot Project root directory.
     * @return array<string, mixed>
     */
    protected function readProjectConfiguration(string $projectRoot): array
    {
        return $this->projectConfigurationReader->read($projectRoot);
    }

    /**
     * Read the devServer section from project configuration.
     *
     * @param string $projectRoot Project root directory.
     * @return array<string, mixed>
     */
    protected function readDevServerConfiguration(string $projectRoot): array
    {
        $projectConfiguration = $this->readProjectConfiguration($projectRoot);
        $devServerConfiguration = $projectConfiguration['devServer'] ?? null;
        if (!is_array($devServerConfiguration)) {
            return [];
        }

        $normalized = [];
        foreach ($devServerConfiguration as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $normalized[$key] = $value;
        }

        return $normalized;
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
     * Normalize optional root option values.
     *
     * @param mixed $rootOption Raw root option value.
     */
    protected function normalizeRootOption(mixed $rootOption): ?string
    {
        if (!is_string($rootOption)) {
            return null;
        }

        return Normalization::optionalPath($rootOption);
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
