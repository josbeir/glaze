<?php
declare(strict_types=1);

namespace Glaze\Scaffold;

use Nette\Neon\Neon;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Creates a new Glaze project directory with starter content and templates.
 */
final class ProjectScaffoldService
{
    /**
     * Create a new project scaffold on disk.
     *
     * @param \Glaze\Scaffold\ScaffoldOptions $options Scaffold options.
     */
    public function scaffold(ScaffoldOptions $options): void
    {
        $this->guardTargetDirectory($options->targetDirectory, $options->force);
        $this->ensureDirectory($options->targetDirectory);
        $this->copySkeleton($options->targetDirectory);
        $this->writeFile(
            $options->targetDirectory . DIRECTORY_SEPARATOR . 'glaze.neon',
            $this->buildProjectConfig($options),
        );
    }

    /**
     * Guard target directory state before scaffold writes.
     *
     * @param string $targetDirectory Target project directory.
     * @param bool $force Whether writes can overwrite existing files.
     */
    protected function guardTargetDirectory(string $targetDirectory, bool $force): void
    {
        if (!is_dir($targetDirectory)) {
            return;
        }

        $entries = scandir($targetDirectory);
        if (!is_array($entries)) {
            throw new RuntimeException(sprintf('Unable to inspect target directory "%s".', $targetDirectory));
        }

        $visibleEntries = array_values(array_filter(
            $entries,
            static fn(string $entry): bool => $entry !== '.' && $entry !== '..',
        ));

        if ($visibleEntries === [] || $force) {
            return;
        }

        throw new RuntimeException(sprintf(
            'Target directory "%s" is not empty. Use --force to continue.',
            $targetDirectory,
        ));
    }

    /**
     * Ensure a directory exists.
     *
     * @param string $directory Directory path.
     */
    protected function ensureDirectory(string $directory): void
    {
        if (is_dir($directory)) {
            return;
        }

        if (!mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create directory "%s".', $directory));
        }
    }

    /**
     * Write a file to disk.
     *
     * @param string $path File path.
     * @param string $content File content.
     */
    protected function writeFile(string $path, string $content): void
    {
        $written = file_put_contents($path, $content);
        if ($written === false) {
            throw new RuntimeException(sprintf('Unable to write file "%s".', $path));
        }
    }

    /**
     * Copy skeleton project files from package into target path.
     *
     * @param string $targetDirectory Target directory path.
     */
    protected function copySkeleton(string $targetDirectory): void
    {
        $this->copyDirectory($this->skeletonSourcePath(), $targetDirectory);
    }

    /**
     * Resolve skeleton source path from package root.
     */
    protected function skeletonSourcePath(): string
    {
        $root = dirname(__DIR__, 2);
        $sourceDirectory = $root . DIRECTORY_SEPARATOR . 'skeleton';

        if (!is_dir($sourceDirectory)) {
            throw new RuntimeException('Skeleton directory does not exist.');
        }

        return $sourceDirectory;
    }

    /**
     * Recursively copy a directory to destination.
     *
     * @param string $sourceDirectory Source directory path.
     * @param string $targetDirectory Target directory path.
     */
    protected function copyDirectory(string $sourceDirectory, string $targetDirectory): void
    {
        $this->ensureDirectory($targetDirectory);

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($sourceDirectory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $entry) {
            if (!$entry instanceof SplFileInfo) {
                continue;
            }

            $relativePath = $iterator->getSubPathName();
            $destinationPath = $targetDirectory . DIRECTORY_SEPARATOR . $relativePath;

            if ($entry->isDir()) {
                $this->ensureDirectory($destinationPath);

                continue;
            }

            if (!$entry->isFile()) {
                continue;
            }

            if (!copy($entry->getPathname(), $destinationPath)) {
                throw new RuntimeException(sprintf('Unable to copy file "%s".', $entry->getPathname()));
            }
        }
    }

    /**
     * Build project configuration file.
     *
     * @param \Glaze\Scaffold\ScaffoldOptions $options Scaffold options.
     */
    protected function buildProjectConfig(ScaffoldOptions $options): string
    {
        $siteConfig = [
            'title' => $options->siteTitle,
        ];

        if (trim($options->description) !== '') {
            $siteConfig['description'] = $options->description;
        }

        if (is_string($options->baseUrl) && trim($options->baseUrl) !== '') {
            $siteConfig['baseUrl'] = trim($options->baseUrl);
        }

        if (is_string($options->basePath) && trim($options->basePath) !== '') {
            $siteConfig['basePath'] = trim($options->basePath);
        }

        $projectConfig = [
            'pageTemplate' => $options->pageTemplate,
            'site' => $siteConfig,
            'taxonomies' => $options->taxonomies,
        ];

        return Neon::encode($projectConfig, true)
            . PHP_EOL
            . $this->commentedOptionsTemplate();
    }

    /**
     * Build commented reference block with available configuration options.
     */
    protected function commentedOptionsTemplate(): string
    {
        return <<<NEON
# --- Available options (uncomment and adjust as needed) ---
# pageTemplate: page
#
# images:
#   driver: gd
#   presets:
#     thumb:
#       w: 320
#       h: 180
#       fit: crop
#
# site:
#   title: My Site
#   description: Default site description
#   baseUrl: https://example.com
#   basePath: /blog
#   metaDefaults:
#     robots: index,follow
#
# taxonomies:
#   - tags
#   - categories
#
# contentTypes:
#   blog:
#     paths:
#       - blog
#       - match: blog/archive
#         createPattern: 'blog/archive/{date:Y/m}'
#     defaults:
#       template: blog
#
# staticDir: static
#
# codeHighlighting:
#   enabled: true
#   theme: github-dark
#   withGutter: false
#
# devServer:
#   php:
#     host: 127.0.0.1
#     port: 8080
#   vite:
#     enabled: false
#     host: 127.0.0.1
#     port: 5173
#     command: "npm run dev -- --host {host} --port {port} --strictPort"
NEON;
    }
}
