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
        if (!is_dir($contentPath)) {
            return [];
        }

        $copiedFiles = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($contentPath, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY,
        );

        foreach ($iterator as $file) {
            if (!$file instanceof SplFileInfo) {
                continue;
            }

            if (!$file->isFile()) {
                continue;
            }

            if (strtolower($file->getExtension()) === 'dj') {
                continue;
            }

            $sourcePath = $file->getPathname();
            $relativePath = $this->relativePath($contentPath, $sourcePath);
            $destinationPath = $outputPath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);

            $this->copyFile($sourcePath, $destinationPath);
            $copiedFiles[] = $destinationPath;
        }

        return $copiedFiles;
    }

    /**
     * Build source-relative path.
     *
     * @param string $contentPath Content root path.
     * @param string $sourcePath Source file path.
     */
    protected function relativePath(string $contentPath, string $sourcePath): string
    {
        $normalizedContentPath = rtrim(str_replace('\\', '/', $contentPath), '/');
        $normalizedSourcePath = str_replace('\\', '/', $sourcePath);

        return ltrim(substr($normalizedSourcePath, strlen($normalizedContentPath)), '/');
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
