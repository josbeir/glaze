<?php
declare(strict_types=1);

namespace Glaze\Tests\Helper;

use RuntimeException;

/**
 * Provides temporary-directory and fixture-copy helpers for tests.
 */
trait FilesystemTestTrait
{
    /**
     * Create a unique temporary directory for isolated test execution.
     */
    protected function createTempDirectory(): string
    {
        $path = sys_get_temp_dir() . '/glaze_test_' . uniqid('', true);
        if (!mkdir($path, 0755, true) && !is_dir($path)) {
            throw new RuntimeException(sprintf('Unable to create temporary directory "%s".', $path));
        }

        return $path;
    }

    /**
     * Copy a fixture directory into a fresh temp directory and return its path.
     *
     * @param string $fixtureRelativePath Fixture path relative to tests/fixtures.
     */
    protected function copyFixtureToTemp(string $fixtureRelativePath): string
    {
        $source = dirname(__DIR__) . '/fixtures/' . ltrim($fixtureRelativePath, '/');
        if (!is_dir($source)) {
            throw new RuntimeException(sprintf('Fixture directory not found: %s', $source));
        }

        $destination = $this->createTempDirectory();
        $this->copyDirectory($source, $destination);

        return $destination;
    }

    /**
     * Recursively copy one directory tree into another location.
     *
     * @param string $source Source directory.
     * @param string $destination Destination directory.
     */
    protected function copyDirectory(string $source, string $destination): void
    {
        $entries = scandir($source);
        if (!is_array($entries)) {
            throw new RuntimeException(sprintf('Unable to read fixture directory: %s', $source));
        }

        foreach ($entries as $entry) {
            if ($entry === '.') {
                continue;
            }

            if ($entry === '..') {
                continue;
            }

            $sourcePath = $source . DIRECTORY_SEPARATOR . $entry;
            $destinationPath = $destination . DIRECTORY_SEPARATOR . $entry;

            if (is_dir($sourcePath)) {
                if (!mkdir($destinationPath, 0755, true) && !is_dir($destinationPath)) {
                    throw new RuntimeException(sprintf('Unable to create fixture destination directory: %s', $destinationPath));
                }

                $this->copyDirectory($sourcePath, $destinationPath);
                continue;
            }

            if (!copy($sourcePath, $destinationPath)) {
                throw new RuntimeException(sprintf('Unable to copy fixture file "%s".', $sourcePath));
            }
        }
    }
}
