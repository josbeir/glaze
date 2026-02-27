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
