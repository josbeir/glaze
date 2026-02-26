<?php
declare(strict_types=1);

namespace Glaze\Command;

use Cake\Console\BaseCommand;
use Cake\Console\ConsoleIo;
use Composer\InstalledVersions;
use Throwable;

/**
 * Base command with shared CLI presentation helpers.
 */
abstract class AbstractGlazeCommand extends BaseCommand
{
    /**
     * Cached application version for command headers.
     */
    protected static ?string $cachedVersion = null;

    /**
     * Render a compact command header with the current Glaze version.
     *
     * @param \Cake\Console\ConsoleIo $io Console IO service.
     */
    protected function renderVersionHeader(ConsoleIo $io): void
    {
        $successStyle = $io->getStyle('success');
        $successStyle['bold'] = true;
        $io->setStyle('success', $successStyle);

                $io->out(sprintf('<info>Glaze</info> version <success>%s</success>', $this->resolveAppVersion()));
                $io->hr();
    }

    /**
     * Resolve current application version from Composer runtime metadata.
     */
    protected function resolveAppVersion(): string
    {
        if (static::$cachedVersion !== null) {
            return static::$cachedVersion;
        }

        try {
            $rootPackage = InstalledVersions::getRootPackage();
            $prettyVersion = $rootPackage['pretty_version'] ?? null;

            if (is_string($prettyVersion) && trim($prettyVersion) !== '') {
                static::$cachedVersion = trim($prettyVersion);

                return static::$cachedVersion;
            }
        } catch (Throwable) {
        }

        try {
            $packageVersion = InstalledVersions::getPrettyVersion('josbeir/glaze');

            if (is_string($packageVersion) && trim($packageVersion) !== '') {
                static::$cachedVersion = trim($packageVersion);

                return static::$cachedVersion;
            }
        } catch (Throwable) {
        }

        static::$cachedVersion = 'dev';

        return static::$cachedVersion;
    }
}
