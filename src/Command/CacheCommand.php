<?php
declare(strict_types=1);

namespace Glaze\Command;

use Cake\Console\Arguments;
use Cake\Console\BaseCommand;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Glaze\Config\BuildConfigFactory;
use Glaze\Config\CachePath;
use Glaze\Utility\ProjectRootResolver;
use Throwable;

/**
 * Clear the Sugar template and/or Glide image caches for a Glaze project.
 *
 * Without flags both caches are purged. Pass `--templates` or `--images`
 * to target a specific cache only.
 *
 * Example:
 *   glaze cc
 *   glaze cc --templates
 *   glaze cc --images
 */
final class CacheCommand extends BaseCommand
{
    /**
     * Constructor.
     *
     * @param \Glaze\Config\BuildConfigFactory $buildConfigFactory Build configuration factory.
     */
    public function __construct(protected BuildConfigFactory $buildConfigFactory)
    {
        parent::__construct();
    }

    /**
     * Return command description text.
     */
    public static function getDescription(): string
    {
        return 'Clear the compiled template and/or image caches.';
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
                'help' => 'Project root directory containing the cache.',
                'default' => null,
            ])
            ->addOption('templates', [
                'help' => 'Clear only the compiled Sugar template cache.',
                'boolean' => true,
                'default' => false,
            ])
            ->addOption('images', [
                'help' => 'Clear only the Glide image cache.',
                'boolean' => true,
                'default' => false,
            ]);

        return $parser;
    }

    /**
     * Execute cache clear command.
     *
     * @param \Cake\Console\Arguments $args Parsed command arguments.
     * @param \Cake\Console\ConsoleIo $io Console IO service.
     */
    public function execute(Arguments $args, ConsoleIo $io): int
    {
        try {
            $projectRoot = ProjectRootResolver::resolve($this->normalizeRootOption($args->getOption('root')));
            $config = $this->buildConfigFactory->fromProjectRoot($projectRoot);

            $templatesOnly = (bool)$args->getOption('templates');
            $imagesOnly = (bool)$args->getOption('images');
            $clearBoth = !$templatesOnly && !$imagesOnly;

            foreach (CachePath::cases() as $cachePath) {
                if (!$cachePath->shouldClear($clearBoth, $templatesOnly, $imagesOnly)) {
                    continue;
                }

                if ($cachePath->isFileTarget()) {
                    $this->clearFile($config->cachePath($cachePath), $cachePath->label(), $io);

                    continue;
                }

                $this->clearDirectory($config->cachePath($cachePath), $cachePath->label(), $io);
            }
        } catch (Throwable $throwable) {
            $io->error(sprintf('Cache clear failed: %s', $throwable->getMessage()));

            return self::CODE_ERROR;
        }

        return self::CODE_SUCCESS;
    }

    /**
     * Remove all files and subdirectories inside a cache directory.
     *
     * The directory itself is preserved so subsequent builds can write to it
     * without needing to recreate it.
     *
     * @param string $directory Absolute path to the cache directory.
     * @param string $label Human-readable label used in console output.
     * @param \Cake\Console\ConsoleIo $io Console IO service.
     */
    protected function clearDirectory(string $directory, string $label, ConsoleIo $io): void
    {
        if (!is_dir($directory)) {
            $io->out(sprintf(
                '<success>✓ skip</success> <info>[Cache/%s]</info> Directory does not exist, nothing to clear.',
                $label,
            ));

            return;
        }

        $this->emptyDirectory($directory);
        $io->out(sprintf('<success>✓ done</success> <info>[Cache/%s]</info> Cleared.', $label));
    }

    /**
     * Recursively remove all contents of a directory without removing the directory itself.
     *
     * @param string $directory Absolute directory path to empty.
     */
    protected function emptyDirectory(string $directory): void
    {
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
    }

    /**
     * Remove a single cache file when present.
     *
     * @param string $path Absolute file path.
     * @param string $label Human-readable label used in console output.
     * @param \Cake\Console\ConsoleIo $io Console IO service.
     */
    protected function clearFile(string $path, string $label, ConsoleIo $io): void
    {
        if (!is_file($path)) {
            $io->out(sprintf(
                '<success>✓ skip</success> <info>[Cache/%s]</info> File does not exist, nothing to clear.',
                $label,
            ));

            return;
        }

        unlink($path);
        $io->out(sprintf('<success>✓ done</success> <info>[Cache/%s]</info> Cleared.', $label));
    }

    /**
     * Recursively remove a directory and all its contents.
     *
     * @param string $directory Absolute directory path to remove.
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

    /**
     * Normalize an optional root option value, returning null for blank input.
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
