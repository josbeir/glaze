<?php
declare(strict_types=1);

namespace Glaze\Command;

use Cake\Console\Arguments;
use Cake\Console\BaseCommand;
use Cake\Console\ConsoleIo;
use Cake\Console\ConsoleOptionParser;
use Glaze\Build\SiteBuilder;
use Glaze\Config\BuildConfig;
use InvalidArgumentException;
use RuntimeException;

/**
 * Serve generated static files using the PHP built-in web server.
 */
/** @phpstan-ignore-next-line */
final class ServeCommand extends BaseCommand
{
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
                'default' => getcwd() ?: '.',
            ])
            ->addOption('host', [
                'help' => 'Host interface to bind the server to.',
                'default' => '127.0.0.1',
            ])
            ->addOption('port', [
                'help' => 'Port to bind the server to.',
                'default' => 8080,
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
        $projectRoot = $this->normalizePath((string)$args->getOption('root'));
        if (!is_dir($projectRoot)) {
            $io->err(sprintf('<error>Project root not found: %s</error>', $projectRoot));

            return self::CODE_ERROR;
        }

        $isStaticMode = (bool)$args->getOption('static');
        $includeDrafts = !$isStaticMode || (bool)$args->getOption('drafts');
        $docRoot = $isStaticMode ? $projectRoot . DIRECTORY_SEPARATOR . 'public' : $projectRoot;

        if ((bool)$args->getOption('build')) {
            if (!$isStaticMode) {
                $io->err('<error>--build can only be used together with --static.</error>');

                return self::CODE_ERROR;
            }

            try {
                $writtenFiles = (new SiteBuilder())->build(
                    BuildConfig::fromProjectRoot($projectRoot, $includeDrafts),
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

        $host = (string)$args->getOption('host');
        $port = $this->normalizePort($args->getOption('port'));
        if ($port === null) {
            $io->err('<error>Invalid port. Use a number between 1 and 65535.</error>');

            return self::CODE_ERROR;
        }

        try {
            $command = $this->buildServerCommand(
                host: $host,
                port: $port,
                docRoot: $docRoot,
                projectRoot: $projectRoot,
                staticMode: $isStaticMode,
                includeDrafts: $includeDrafts,
            );
        } catch (InvalidArgumentException $invalidArgumentException) {
            $io->err(sprintf('<error>%s</error>', $invalidArgumentException->getMessage()));

            return self::CODE_ERROR;
        }

        $address = $host . ':' . $port;
        if ($isStaticMode) {
            $io->out(sprintf('Serving static output from %s at http://%s', $docRoot, $address));
        } else {
            $io->out(sprintf('Serving live templates/content from %s at http://%s', $projectRoot, $address));
        }

        $io->out('Press Ctrl+C to stop.');

        passthru($command, $exitCode);

        return $exitCode;
    }

    /**
     * Build the PHP built-in server command.
     *
     * @param string $host Host interface.
     * @param int $port Port number.
     * @param string $docRoot Document root for php -S.
     * @param string $projectRoot Project root directory.
     * @param bool $staticMode Whether static mode is enabled.
     * @param bool $includeDrafts Whether draft pages should be included.
     */
    protected function buildServerCommand(
        string $host,
        int $port,
        string $docRoot,
        string $projectRoot,
        bool $staticMode,
        bool $includeDrafts,
    ): string {
        $address = $host . ':' . $port;
        if ($staticMode) {
            return sprintf('php -S %s -t %s', escapeshellarg($address), escapeshellarg($docRoot));
        }

        $routerPath = $projectRoot . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'dev-router.php';
        if (!is_file($routerPath)) {
            throw new InvalidArgumentException(sprintf('Live router script not found: %s', $routerPath));
        }

        return sprintf(
            'GLAZE_PROJECT_ROOT=%s GLAZE_INCLUDE_DRAFTS=%s php -S %s -t %s %s',
            escapeshellarg($projectRoot),
            escapeshellarg($includeDrafts ? '1' : '0'),
            escapeshellarg($address),
            escapeshellarg($docRoot),
            escapeshellarg($routerPath),
        );
    }

    /**
     * Normalize path separators and remove trailing separators.
     *
     * @param string $path Input path.
     */
    protected function normalizePath(string $path): string
    {
        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);

        return rtrim($normalized, DIRECTORY_SEPARATOR);
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
