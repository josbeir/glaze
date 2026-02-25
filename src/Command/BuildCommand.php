<?php
declare(strict_types=1);

namespace Glaze\Command;

use Cake\Console\Arguments;
use Cake\Console\BaseCommand;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Glaze\Build\SiteBuilder;
use Glaze\Config\BuildConfig;
use Glaze\Utility\ProjectRootResolver;
use RuntimeException;

/**
 * Build the static site from Djot content and Sugar templates.
 */
final class BuildCommand extends BaseCommand
{
    protected ?SiteBuilder $siteBuilder = null;

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
                'default' => false,
            ])
            ->addOption('drafts', [
                'help' => 'Include draft pages during build.',
                'boolean' => true,
                'default' => false,
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
        try {
            $projectRoot = ProjectRootResolver::resolve($this->normalizeRootOption($args->getOption('root')));
            $config = BuildConfig::fromProjectRoot(
                $projectRoot,
                (bool)$args->getOption('drafts'),
            );
            $writtenFiles = $this->siteBuilder()->build($config, (bool)$args->getOption('clean'));
        } catch (RuntimeException $runtimeException) {
            $io->err(sprintf('<error>%s</error>', $runtimeException->getMessage()));

            return self::CODE_ERROR;
        }

        foreach ($writtenFiles as $filePath) {
            $relativePath = $this->relativeToRoot($filePath, $config->projectRoot);
            $io->out(sprintf('<success>generated</success> %s', $relativePath));
        }

        $io->out(sprintf('Build complete: %d page(s).', count($writtenFiles)));

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
     * Create or reuse the site builder instance.
     */
    protected function siteBuilder(): SiteBuilder
    {
        if ($this->siteBuilder instanceof SiteBuilder) {
            return $this->siteBuilder;
        }

        $this->siteBuilder = new SiteBuilder();

        return $this->siteBuilder;
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
}
