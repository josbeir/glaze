<?php
declare(strict_types=1);

namespace Glaze\Scaffold;

use Cake\Chronos\Chronos;
use Glaze\Utility\Normalization;
use Nette\Neon\Neon;
use RuntimeException;

/**
 * Scaffolds new content pages under the content directory.
 */
final class PageScaffoldService
{
    /**
     * Create a new page and return the absolute created file path.
     *
     * @param string $contentPath Absolute content directory path.
     * @param \Glaze\Scaffold\PageScaffoldOptions $options Page scaffold options.
     */
    public function scaffold(string $contentPath, PageScaffoldOptions $options): string
    {
        $relativePath = $this->resolveTargetRelativePath(
            slug: $options->slug,
            titleSlug: $options->titleSlug,
            pathRule: $options->pathRule,
            date: $options->date,
            pathPrefix: $options->pathPrefix,
            asIndex: $options->asIndex,
        );
        $targetPath = $this->resolveTargetPath($contentPath, $relativePath);

        $this->guardTargetPath($targetPath, $options->force);
        $this->writePageFile($targetPath, $this->buildPageSource($options));

        return $targetPath;
    }

    /**
     * Normalize type path rules from normalized or legacy path configuration.
     *
     * @param array<mixed> $paths Path configuration.
     * @return array<array{match: string, createPattern: string|null}>
     */
    public function typePathRules(array $paths): array
    {
        $rules = [];

        foreach ($paths as $path) {
            if (is_string($path)) {
                $match = Normalization::pathFragment($path);
                if ($match === '') {
                    continue;
                }

                $rules[] = [
                    'match' => $match,
                    'createPattern' => null,
                ];

                continue;
            }

            if (!is_array($path)) {
                continue;
            }

            $match = Normalization::optionalPathFragment($path['match'] ?? null);
            if ($match === null) {
                continue;
            }

            $createPattern = Normalization::optionalPathFragment($path['createPattern'] ?? null);
            $rules[] = [
                'match' => $match,
                'createPattern' => $createPattern,
            ];
        }

        return $rules;
    }

    /**
     * Find a type path rule by match value.
     *
     * @param array<array{match: string, createPattern: string|null}> $rules Path rules.
     * @param string $match Match to find.
     * @return array{match: string, createPattern: string|null}|null
     */
    public function findPathRuleByMatch(array $rules, string $match): ?array
    {
        $normalizedMatch = trim(strtolower($match), '/');

        foreach ($rules as $rule) {
            if (strtolower($rule['match']) === $normalizedMatch) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * Resolve target relative path from slug and optional type mapping.
     *
     * @param string $slug Normalized slug.
     * @param string $titleSlug Title-derived slug.
     * @param array{match: string, createPattern: string|null}|null $pathRule Resolved type path rule.
     * @param string $date Normalized date value.
     * @param string|null $pathPrefix Optional generic path prefix.
     * @param bool $asIndex Whether to create folder/index.dj layout.
     */
    protected function resolveTargetRelativePath(
        string $slug,
        string $titleSlug,
        ?array $pathRule,
        string $date,
        ?string $pathPrefix,
        bool $asIndex,
    ): string {
        $segments = [];

        if (is_array($pathRule)) {
            $basePath = $this->resolveCreateBasePath($pathRule, $date);
            if ($basePath !== '') {
                $segments[] = $basePath;
            }
        } elseif (is_string($pathPrefix) && $pathPrefix !== '') {
            $segments[] = trim($pathPrefix, '/');
        }

        $segments[] = trim($asIndex ? $titleSlug : $slug, '/');
        $path = implode('/', array_filter($segments, static fn(string $segment): bool => $segment !== ''));

        if ($asIndex) {
            return trim($path, '/') . '/index.dj';
        }

        return trim($path, '/') . '.dj';
    }

    /**
     * Resolve effective creation base path from a path rule.
     *
     * @param array{match: string, createPattern: string|null} $pathRule Path rule.
     * @param string $date Normalized date value.
     */
    protected function resolveCreateBasePath(array $pathRule, string $date): string
    {
        $pattern = $pathRule['createPattern'] ?? $pathRule['match'];
        $resolved = preg_replace_callback(
            '/\{date(?::([^}]+))?\}/',
            static function (array $matches) use ($date): string {
                $format = $matches[1] ?? 'Y-m-d';

                return Chronos::parse($date)->format($format);
            },
            $pattern,
        );

        return is_string($resolved)
            ? Normalization::pathFragment($resolved)
            : Normalization::pathFragment($pattern);
    }

    /**
     * Build source text for the page file.
     *
     * @param \Glaze\Scaffold\PageScaffoldOptions $options Page scaffold options.
     */
    protected function buildPageSource(PageScaffoldOptions $options): string
    {
        $frontmatter = [
            'title' => $options->title,
            'date' => $options->date,
            'draft' => $options->draft,
        ];

        if ($options->type !== null) {
            $frontmatter['type'] = $options->type;
        }

        if (is_int($options->weight)) {
            $frontmatter['weight'] = $options->weight;
        }

        return "---\n"
            . Neon::encode($frontmatter, true)
            . "---\n"
            . '# ' . $options->title . "\n";
    }

    /**
     * Resolve absolute file path under content directory.
     *
     * @param string $contentPath Absolute content path.
     * @param string $relativePath Relative page path.
     */
    protected function resolveTargetPath(string $contentPath, string $relativePath): string
    {
        return Normalization::path(
            rtrim($contentPath, DIRECTORY_SEPARATOR)
            . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, trim($relativePath, '/')),
        );
    }

    /**
     * Ensure target file does not exist unless force overwrite is enabled.
     *
     * @param string $targetPath Absolute target file path.
     * @param bool $force Whether overwrite is allowed.
     */
    protected function guardTargetPath(string $targetPath, bool $force): void
    {
        if (!is_file($targetPath)) {
            return;
        }

        if ($force) {
            return;
        }

        throw new RuntimeException(sprintf('Target file already exists: %s (use --force to overwrite).', $targetPath));
    }

    /**
     * Write final page source to disk.
     *
     * @param string $targetPath Absolute target file path.
     * @param string $source Page source content.
     */
    protected function writePageFile(string $targetPath, string $source): void
    {
        $directory = dirname($targetPath);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create directory "%s".', $directory));
        }

        $written = file_put_contents($targetPath, $source);
        if ($written === false) {
            throw new RuntimeException(sprintf('Unable to write content file "%s".', $targetPath));
        }
    }
}
