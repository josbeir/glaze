<?php
declare(strict_types=1);

namespace Glaze\Build;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Publishes content assets to the static output directory.
 */
final class ContentAssetPublisher
{
    /**
     * Copy non-Djot assets from content tree into output tree.
     *
     * @param string $contentPath Absolute content directory.
     * @param string $outputPath Absolute output directory.
     * @return array<string> Copied asset file paths.
     */
    public function publish(string $contentPath, string $outputPath): array
    {
        return $this->publishDirectory(
            sourcePath: $contentPath,
            outputPath: $outputPath,
            shouldCopy: static fn(SplFileInfo $file): bool => strtolower($file->getExtension()) !== 'dj',
        );
    }

    /**
     * Copy files from static directory into output root.
     *
     * @param string $staticPath Absolute static directory.
     * @param string $outputPath Absolute output directory.
     * @return array<string> Copied asset file paths.
     */
    public function publishStatic(string $staticPath, string $outputPath): array
    {
        return $this->publishDirectory(
            sourcePath: $staticPath,
            outputPath: $outputPath,
            shouldCopy: static fn(SplFileInfo $file): bool => true,
        );
    }

    /**
     * Copy files from source tree to output tree preserving relative structure.
     *
     * @param string $sourcePath Absolute source directory.
     * @param string $outputPath Absolute output directory.
     * @param callable $shouldCopy File inclusion callback.
     * @return array<string> Copied file paths.
     */
    protected function publishDirectory(string $sourcePath, string $outputPath, callable $shouldCopy): array
    {
        if (!is_dir($sourcePath)) {
            return [];
        }

        $copiedFiles = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourcePath, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo) {
                continue;
            }

            if (!$file->isFile()) {
                continue;
            }

            if (!$shouldCopy($file)) {
                continue;
            }

            $fileSourcePath = $file->getPathname();
            $relativePath = $this->relativePath($sourcePath, $fileSourcePath);
            $destinationPath = $outputPath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

            $this->copyFile($fileSourcePath, $destinationPath);
            $copiedFiles[] = $destinationPath;
        }

        return $copiedFiles;
    }

    /**
     * Build source-relative path.
     *
     * @param string $rootPath Source root path.
     * @param string $sourcePath Source file path.
     */
    protected function relativePath(string $rootPath, string $sourcePath): string
    {
        $normalizedRootPath = rtrim(str_replace('\\', '/', $rootPath), '/');
        $normalizedSourcePath = str_replace('\\', '/', $sourcePath);

        return ltrim(substr($normalizedSourcePath, strlen($normalizedRootPath)), '/');
    }

    /**
     * Copy file while ensuring destination directory exists.
     *
     * @param string $sourcePath Source file path.
     * @param string $destinationPath Destination file path.
     */
    protected function copyFile(string $sourcePath, string $destinationPath): void
    {
        $directory = dirname($destinationPath);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create asset output directory "%s".', $directory));
        }

        if (!copy($sourcePath, $destinationPath)) {
            throw new RuntimeException(sprintf('Unable to copy asset "%s".', $sourcePath));
        }
    }
}
