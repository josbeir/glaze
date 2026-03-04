<?php
declare(strict_types=1);

namespace Glaze\Command;

use Cake\Console\Arguments;
use Cake\Console\BaseCommand;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Cake\Core\Configure;
use Glaze\Build\BuildManifest;
use Glaze\Build\SiteBuilder;
use Glaze\Config\BuildConfig;
use Glaze\Config\CachePath;
use Glaze\Process\ViteBuildProcess;
use Glaze\Utility\Normalization;
use Glaze\Utility\Path;
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
     * @param \Glaze\Process\ViteBuildProcess $viteBuildProcess Vite build process.
     */
    public function __construct(
        protected SiteBuilder $siteBuilder,
        protected ViteBuildProcess $viteBuildProcess,
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
                'help' => 'Force cleaning the output directory before writing files.',
                'boolean' => true,
                'default' => false,
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
     * Creates the BuildConfig first (which populates Configure with the
     * merged reference + project configuration), then reads build-specific
     * options from Configure to resolve CLI overrides.
     *
     * @param \Cake\Console\Arguments $args Parsed command arguments.
     * @param \Cake\Console\ConsoleIo $io Console IO service.
     */
    public function execute(Arguments $args, ConsoleIo $io): int
    {
        $startedAt = microtime(true);
        $pendingIcon = '<comment>✓ working</comment>';
        $doneIcon = '<success>✓ done</success>';
        $formatStageMessage = static fn(string $icon, string $label, string $message): string => sprintf(
            '%s <info>[%s]</info> %s',
            $icon,
            $label,
            $message,
        );

        try {
            $configMessage = $formatStageMessage($pendingIcon, 'Config', 'Resolving project configuration...');
            $io->out($configMessage, 0);
            $projectRoot = ProjectRootResolver::resolve(Path::optional($args->getOption('root')));

            $config = BuildConfig::fromProjectRoot(
                $projectRoot,
                (bool)$args->getOption('drafts'),
            );

            $cleanOutput = (bool)$args->getOption('clean') || Configure::read('build.clean') === true;

            $viteEnabled = (bool)$args->getOption('vite') || Configure::read('build.vite.enabled') === true;
            $viteCommand = Normalization::optionalString($args->getOption('vite-command'))
                ?? Normalization::optionalString(Configure::read('build.vite.command'))
                ?? 'npm run build';
            $viteBuildConfiguration = [
                'enabled' => $viteEnabled,
                'command' => $viteCommand,
            ];

            $verbose = (bool)$args->getOption('verbose');
            $manifestPath = $config->cachePath(CachePath::BuildManifest);
            $previousManifest = BuildManifest::load($manifestPath);
            $cleanedOutput = false;
            $io->overwrite($formatStageMessage($doneIcon, 'Config', 'Resolving project configuration...'));

            if ($cleanOutput) {
                $cleanMessage = $formatStageMessage($pendingIcon, 'Clean', 'Cleaning output directory...');
                $io->out($cleanMessage, 0);
                $this->removeDirectory($config->outputPath());
                $cleanOutput = false;
                $cleanedOutput = true;
                $io->overwrite($formatStageMessage($doneIcon, 'Clean', 'Cleaning output directory...'));
            } else {
                $cleanMessage = $formatStageMessage($pendingIcon, 'Clean', 'Skipping output cleanup...');
                $io->out($cleanMessage, 0);
                $io->overwrite($formatStageMessage($doneIcon, 'Clean', 'Skipping output cleanup...'));
            }

            if ($viteBuildConfiguration['enabled']) {
                $viteMessage = $formatStageMessage($pendingIcon, 'Vite', 'Running Vite build...');
                $io->out($viteMessage, 0);
                $this->viteBuildProcess->start($viteBuildConfiguration, $projectRoot);
                $io->overwrite($formatStageMessage($doneIcon, 'Vite', 'Running Vite build...'));
            } else {
                $viteMessage = $formatStageMessage($pendingIcon, 'Vite', 'Skipping Vite build (disabled)...');
                $io->out($viteMessage, 0);
                $io->overwrite($formatStageMessage($doneIcon, 'Vite', 'Skipping Vite build (disabled)...'));
            }

            $buildMessage = $formatStageMessage($pendingIcon, 'Build', 'Building pages...');
            $buildProgressMessage = $buildMessage;
            $io->out($buildMessage, 0);
            $writtenFiles = $this->siteBuilder->build(
                $config,
                $cleanOutput,
                function (
                    int $completedPages,
                    int $totalPages,
                    string $file,
                    float $duration,
                ) use (
                    $io,
                    &$buildProgressMessage,
                    $pendingIcon,
                    $config,
                    $verbose,
                ): void {
                    $message = sprintf(
                        '%s <info>[Build]</info> Building pages... %d/%d',
                        $pendingIcon,
                        $completedPages,
                        $totalPages,
                    );
                    $buildProgressMessage = $message;
                    if ($verbose && $file !== '') {
                        if (str_starts_with($file, $config->projectRoot)) {
                            $relativePath = $this->relativeToRoot($file, $config->projectRoot);
                            if ($duration > 0.0) {
                                $io->overwrite(sprintf(
                                    '<success>generated</success> %s <info>(%d/%d)</info> <comment>%s</comment>',
                                    $relativePath,
                                    $completedPages,
                                    $totalPages,
                                    $this->formatDuration($duration),
                                ));
                            } else {
                                $io->overwrite(sprintf(
                                    '<comment>cached</comment>  %s <info>(%d/%d)</info>',
                                    $relativePath,
                                    $completedPages,
                                    $totalPages,
                                ));
                            }
                        } else {
                            $io->overwrite(sprintf(
                                '<comment>virtual</comment>  %s <info>(%d/%d)</info>',
                                $file,
                                $completedPages,
                                $totalPages,
                            ));
                        }
                    } else {
                        $io->overwrite($message, 0);
                    }
                },
            );
            $completedBuildMessage = preg_replace(
                '/^<comment>✓<\/comment>/',
                $doneIcon,
                $buildProgressMessage,
            ) ?: $buildProgressMessage;
            if (!$verbose) {
                $io->overwrite($completedBuildMessage);
            }

            if ($verbose && !$cleanedOutput && $previousManifest instanceof BuildManifest) {
                $currentManifest = BuildManifest::load($manifestPath);
                if ($currentManifest instanceof BuildManifest) {
                    $orphanedOutputPaths = array_values(array_unique(array_merge(
                        $currentManifest->orphanedPageOutputPaths($previousManifest),
                        $currentManifest->orphanedContentAssetOutputPaths($previousManifest),
                        $currentManifest->orphanedStaticAssetOutputPaths($previousManifest),
                    )));
                    foreach ($orphanedOutputPaths as $orphanedOutputPath) {
                        $orphanedAbsolutePath = $config->outputPath() . '/' . $orphanedOutputPath;
                        $relativePath = $this->relativeToRoot($orphanedAbsolutePath, $config->projectRoot);
                        $io->out(sprintf('<error>removed</error> %s', $relativePath));
                    }
                }
            }
        } catch (Throwable $throwable) {
            $io->err(sprintf('<error>%s</error>', $throwable->getMessage()));

            return self::CODE_ERROR;
        }

        $elapsedTime = max(0.0, microtime(true) - $startedAt);
        $peakMemory = memory_get_peak_usage(true);
        $io->hr();
        $io->out(sprintf(
            'Build complete: %d page(s) in %s (peak memory: %s).',
            count($writtenFiles),
            $this->formatDuration($elapsedTime),
            $this->formatBytes($peakMemory),
        ));

        return self::CODE_SUCCESS;
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
