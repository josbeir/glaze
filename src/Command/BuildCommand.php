<?php
declare(strict_types=1);

namespace Glaze\Command;

use Cake\Console\Arguments;
use Cake\Console\BaseCommand;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Glaze\Build\SiteBuilder;
use Glaze\Config\BuildConfigFactory;
use Glaze\Config\ProjectConfigurationReader;
use Glaze\Serve\ViteBuildConfig;
use Glaze\Serve\ViteBuildService;
use Glaze\Utility\Normalization;
use Glaze\Utility\ProjectRootResolver;
use Throwable;

/**
 * Build the static site from Djot content and Sugar templates.
 */
final class BuildCommand extends BaseCommand
{
    /**
     * Constructor.
     *
     * @param \Glaze\Build\SiteBuilder $siteBuilder Site builder service.
     * @param \Glaze\Config\ProjectConfigurationReader $projectConfigurationReader Project configuration reader.
     * @param \Glaze\Serve\ViteBuildService $viteBuildService Vite build service.
     * @param \Glaze\Config\BuildConfigFactory $buildConfigFactory Build configuration factory.
     */
    public function __construct(
        protected SiteBuilder $siteBuilder,
        protected ProjectConfigurationReader $projectConfigurationReader,
        protected ViteBuildService $viteBuildService,
        protected BuildConfigFactory $buildConfigFactory,
    ) {
        parent::__construct();
    }

    /**
     * Get command description text.
     */
    public static function getDescription(): string
    {
        return 'Generate static HTML from content and templates.';
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
                'help' => 'Project root directory containing content/ and templates/.',
                'default' => null,
            ])
            ->addOption('clean', [
                'help' => 'Clean the output directory before writing files.',
                'boolean' => true,
                'default' => null,
            ])
            ->addOption('drafts', [
                'help' => 'Include draft pages during build.',
                'boolean' => true,
                'default' => null,
            ])
            ->addOption('vite', [
                'help' => 'Run Vite build process as part of static build.',
                'boolean' => true,
                'default' => false,
            ])
            ->addOption('vite-command', [
                'help' => 'Vite build command (defaults to build.vite.command or "npm run build").',
                'default' => null,
            ]);

        return $parser;
    }

    /**
     * Execute site build command.
     *
     * @param \Cake\Console\Arguments $args Parsed command arguments.
     * @param \Cake\Console\ConsoleIo $io Console IO service.
     */
    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $startedAt = microtime(true);

        try {
            $projectRoot = ProjectRootResolver::resolve($this->normalizeRootOption($args->getOption('root')));
            $buildConfiguration = $this->readBuildConfiguration($projectRoot);
            $includeDrafts = $this->resolveBuildBooleanOption($args, $buildConfiguration, 'drafts');
            $cleanOutput = $this->resolveBuildBooleanOption($args, $buildConfiguration, 'clean');
            $viteBuildConfiguration = $this->resolveViteBuildConfiguration($args, $buildConfiguration);

            $io->out('Building pages...', 0);
            $config = $this->buildConfigFactory->fromProjectRoot(
                $projectRoot,
                $includeDrafts,
            );

            if ($viteBuildConfiguration->enabled && $cleanOutput) {
                $this->removeDirectory($config->outputPath());
                $cleanOutput = false;
            }

            if ($viteBuildConfiguration->enabled) {
                $this->viteBuildService->run($viteBuildConfiguration, $projectRoot);
                $io->out('Vite build complete.');
            }

            $writtenFiles = $this->siteBuilder->build(
                $config,
                $cleanOutput,
                function (int $completedPages, int $totalPages) use ($io): void {
                    $message = sprintf('Building pages... %d/%d', $completedPages, $totalPages);
                    $io->overwrite($message, 0);
                },
            );
            $io->out();
        } catch (Throwable $throwable) {
            $io->err(sprintf('<error>%s</error>', $throwable->getMessage()));

            return self::CODE_ERROR;
        }

        foreach ($writtenFiles as $filePath) {
            $relativePath = $this->relativeToRoot($filePath, $config->projectRoot);
            $io->out(sprintf('<success>generated</success> %s', $relativePath));
        }

        $elapsedTime = max(0.0, microtime(true) - $startedAt);
        $peakMemory = memory_get_peak_usage(true);
        $io->out(sprintf(
            'Build complete: %d page(s) in %s (peak memory: %s).',
            count($writtenFiles),
            $this->formatDuration($elapsedTime),
            $this->formatBytes($peakMemory),
        ));

        return self::CODE_SUCCESS;
    }

    /**
     * Resolve Vite build configuration from project config and CLI options.
     *
     * @param \Cake\Console\Arguments $args Parsed CLI arguments.
     * @param array<string, mixed> $buildConfiguration Build configuration map.
     */
    protected function resolveViteBuildConfiguration(Arguments $args, array $buildConfiguration): ViteBuildConfig
    {
        $viteConfiguration = $buildConfiguration['vite'] ?? null;
        if (!is_array($viteConfiguration)) {
            $viteConfiguration = [];
        }

        $enabledFromConfiguration = is_bool($viteConfiguration['enabled'] ?? null) && $viteConfiguration['enabled'];
        $enabled = (bool)$args->getOption('vite') || $enabledFromConfiguration;

        $command = Normalization::optionalString($args->getOption('vite-command'))
            ?? Normalization::optionalString($viteConfiguration['command'] ?? null)
            ?? 'npm run build';

        return new ViteBuildConfig(
            enabled: $enabled,
            command: $command,
        );
    }

    /**
     * Resolve boolean build option from CLI value with configuration fallback.
     *
     * @param \Cake\Console\Arguments $args Parsed CLI arguments.
     * @param array<string, mixed> $buildConfiguration Build configuration map.
     * @param string $optionName Build option key.
     */
    protected function resolveBuildBooleanOption(
        Arguments $args,
        array $buildConfiguration,
        string $optionName,
    ): bool {
        $optionValue = $args->getOption($optionName);
        if (is_bool($optionValue) && $optionValue) {
            return true;
        }

        $configuredValue = $buildConfiguration[$optionName] ?? null;
        if (is_bool($configuredValue)) {
            return $configuredValue;
        }

        return false;
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
     * Read the build section from project configuration.
     *
     * @param string $projectRoot Project root directory.
     * @return array<string, mixed>
     */
    protected function readBuildConfiguration(string $projectRoot): array
    {
        $projectConfiguration = $this->readProjectConfiguration($projectRoot);
        $buildConfiguration = $projectConfiguration['build'] ?? null;
        if (!is_array($buildConfiguration)) {
            return [];
        }

        $normalized = [];
        foreach ($buildConfiguration as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    /**
     * Convert absolute path to root-relative display path.
     *
     * @param string $filePath Absolute file path.
     * @param string $rootPath Root path.
     */
    protected function relativeToRoot(string $filePath, string $rootPath): string
    {
        $normalizedFilePath = str_replace('\\', '/', $filePath);
        $normalizedRootPath = rtrim(str_replace('\\', '/', $rootPath), '/');
        if (str_starts_with($normalizedFilePath, $normalizedRootPath . '/')) {
            return substr($normalizedFilePath, strlen($normalizedRootPath) + 1);
        }

        return $filePath;
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

        $normalized = trim($rootOption);

        return $normalized === '' ? null : $normalized;
    }

    /**
     * Format elapsed seconds for console output.
     *
     * @param float $seconds Elapsed duration in seconds.
     */
    protected function formatDuration(float $seconds): string
    {
        return sprintf('%.2fs', $seconds);
    }

    /**
     * Format a byte value into a human-readable memory size.
     *
     * @param int $bytes Byte count.
     */
    protected function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return sprintf('%d B', $bytes);
        }

        $units = ['KB', 'MB', 'GB', 'TB'];
        $value = $bytes / 1024;
        foreach ($units as $unit) {
            if ($value < 1024 || $unit === 'TB') {
                return sprintf('%.2f %s', $value, $unit);
            }

            $value /= 1024;
        }

        return sprintf('%d B', $bytes);
    }

    /**
     * Remove a directory recursively.
     *
     * @param string $directory Absolute directory path.
     */
    protected function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $entries = scandir($directory);
        if (!is_array($entries)) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === '.') {
                continue;
            }

            if ($entry === '..') {
                continue;
            }

            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path)) {
                $this->removeDirectory($path);

                continue;
            }

            unlink($path);
        }

        rmdir($directory);
    }
}
