<?php
declare(strict_types=1);

namespace Glaze\Command;

use Cake\Console\CommandCollection;
use Cake\Console\CommandInterface;
use Cake\Console\CommandRunner;
use Cake\Console\ConsoleIo;
use Composer\InstalledVersions;
use Glaze\Support\Ansi;
use Throwable;

/**
 * Custom command runner that suppresses the default "No command provided"
 * error message, since the Glaze HelpCommand renders its own branded header.
 */
class GlazeCommandRunner extends CommandRunner
{
    /**
     * Cached application version for command headers.
     */
    protected static ?string $cachedVersion = null;

    /**
     * ASCII art lines for the Glaze logo displayed in version headers.
     *
     * @var list<string>
     */
    protected const LOGO_LINES = [
        '  ██████╗ ██╗      █████╗ ███████╗███████╗',
        ' ██╔════╝ ██║     ██╔══██╗╚══███╔╝██╔════╝',
        ' ██║  ███╗██║     ███████║  ███╔╝ █████╗  ',
        ' ██║   ██║██║     ██╔══██║ ███╔╝  ██╔══╝  ',
        ' ╚██████╔╝███████╗██║  ██║███████╗███████╗',
        '  ╚═════╝ ╚══════╝╚═╝  ╚═╝╚══════╝╚══════╝',
    ];

    /**
     * Starting RGB color for the logo gradient (warm amber).
     *
     * @var array{int, int, int}
     */
    protected const GRADIENT_FROM = [255, 160, 40];

    /**
     * Ending RGB color for the logo gradient (cool cyan).
     *
     * @var array{int, int, int}
     */
    protected const GRADIENT_TO = [60, 180, 255];

    /**
     * Resolve the command name, falling through to `help` silently
     * when no command is provided.
     *
     * @param \Cake\Console\CommandCollection $commands The command collection.
     * @param \Cake\Console\ConsoleIo $io Console IO service.
     * @param string|null $name The name from the CLI args.
     * @return string The resolved command name.
     * @throws \Cake\Console\Exception\MissingOptionException
     */
    protected function resolveName(CommandCollection $commands, ConsoleIo $io, ?string $name): string
    {
        if (!$name) {
            return 'help';
        }

        return parent::resolveName($commands, $io, $name);
    }

    /**
     * Execute a command after rendering the branded Glaze header when applicable.
     *
     * @param \Cake\Console\CommandInterface $command Command instance to run.
     * @param array<int, string> $argv Command arguments and options.
     * @param \Cake\Console\ConsoleIo $io Console IO service.
     */
    protected function runCommand(CommandInterface $command, array $argv, ConsoleIo $io): ?int
    {
        if ($this->shouldRenderVersionHeader($command, $argv)) {
            $this->renderVersionHeader($io);
        }

        return parent::runCommand($command, $argv, $io);
    }

    /**
     * Determine whether the command should receive the branded version header.
     *
     * @param \Cake\Console\CommandInterface $command Command instance.
     * @param array<int, string> $argv Raw command arguments and options.
     */
    protected function shouldRenderVersionHeader(CommandInterface $command, array $argv): bool
    {
        if ($this->hasQuietFlag($argv)) {
            return false;
        }

        if ($command instanceof ServeCommand) {
            return $this->hasVerboseFlag($argv);
        }

        return true;
    }

    /**
     * Check whether quiet mode is present in command arguments.
     *
     * @param array<int, string> $argv Raw command arguments.
     */
    protected function hasQuietFlag(array $argv): bool
    {
        return in_array('-q', $argv, true) || in_array('--quiet', $argv, true);
    }

    /**
     * Check whether verbose mode is present in command arguments.
     *
     * @param array<int, string> $argv Raw command arguments.
     */
    protected function hasVerboseFlag(array $argv): bool
    {
        return in_array('-v', $argv, true) || in_array('--verbose', $argv, true);
    }

    /**
     * Render a branded command header with gradient ASCII art.
     *
     * On interactive terminals the logo is rendered with a truecolor
     * gradient and a brief line-by-line reveal animation. On piped or
     * non-interactive output the header falls back to a plain text line.
     *
     * @param \Cake\Console\ConsoleIo $io Console IO service.
     * @param \Glaze\Support\Ansi|null $ansi Optional Ansi helper (for testing).
     */
    protected function renderVersionHeader(ConsoleIo $io, ?Ansi $ansi = null): void
    {
        $ansi ??= new Ansi();
        $version = $this->resolveAppVersion();

        if (!$ansi->isInteractive()) {
            $io->out(sprintf('Glaze version %s', $version));
            $io->hr();

            return;
        }

        $this->renderAnimatedHeader($ansi, $version);
    }

    /**
     * Render animated gradient logo with line-by-line reveal.
     *
     * @param \Glaze\Support\Ansi $ansi ANSI escape helper.
     * @param string $version Resolved application version string.
     */
    protected function renderAnimatedHeader(Ansi $ansi, string $version): void
    {
        $tagline = $ansi->dim(sprintf('  Static site generator · %s', $version));
        $logoLineCount = count(static::LOGO_LINES);

        $ansi->write($ansi->clearScreen() . "\n" . $ansi->hideCursor());

        foreach (static::LOGO_LINES as $line) {
            $ansi->write($ansi->dim($line) . "\n");
        }

        $ansi->write("\n" . $tagline . "\n");

        $ansi->write($ansi->moveUp($logoLineCount + 2));

        foreach (static::LOGO_LINES as $line) {
            usleep(50_000);
            $gradientLine = $ansi->gradient($line, static::GRADIENT_FROM, static::GRADIENT_TO);
            $ansi->write($ansi->clearLine() . $ansi->bold($gradientLine) . "\n");
        }

        $ansi->write("\n\n\n");
        $ansi->write($ansi->showCursor());
    }

    /**
     * Resolve current application version from Composer runtime metadata.
     */
    protected function resolveAppVersion(): string
    {
        if (static::$cachedVersion !== null) {
            return static::$cachedVersion;
        }

        $packageVersion = $this->resolvePackageVersion();
        if ($packageVersion !== null) {
            static::$cachedVersion = $packageVersion;

            return static::$cachedVersion;
        }

        $rootPackageVersion = $this->resolveRootPackageVersion();
        if ($rootPackageVersion !== null) {
            static::$cachedVersion = $rootPackageVersion;

            return static::$cachedVersion;
        }

        static::$cachedVersion = 'dev';

        return static::$cachedVersion;
    }

    /**
     * Resolve installed glaze package version from Composer metadata.
     */
    protected function resolvePackageVersion(): ?string
    {
        try {
            return $this->normalizeVersionCandidate(InstalledVersions::getPrettyVersion('josbeir/glaze'));
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Resolve root package version from Composer metadata.
     */
    protected function resolveRootPackageVersion(): ?string
    {
        try {
            $rootPackage = InstalledVersions::getRootPackage();

            return $this->normalizeVersionCandidate($rootPackage['pretty_version'] ?? null);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Normalize Composer version candidates and filter placeholders.
     *
     * @param mixed $version Raw version candidate.
     */
    protected function normalizeVersionCandidate(mixed $version): ?string
    {
        if (!is_string($version)) {
            return null;
        }

        $normalizedVersion = trim($version);
        if ($normalizedVersion === '') {
            return null;
        }

        if (str_contains($normalizedVersion, 'no-version-set')) {
            return null;
        }

        return $normalizedVersion;
    }
}
