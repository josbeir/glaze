<?php
declare(strict_types=1);

namespace Glaze\Support;

use Glaze\Config\BuildConfig;
use Glaze\Image\GlideImageTransformer;
use RuntimeException;

/**
 * Rewrites build-time HTML image sources to published Glide assets.
 */
final class BuildGlideHtmlRewriter
{
    /**
     * Constructor.
     *
     * @param \Glaze\Image\GlideImageTransformer $glideImageTransformer Glide image transformer service.
     * @param \Glaze\Support\ResourcePathRewriter $resourcePathRewriter Shared path rewriter service.
     */
    public function __construct(
        protected GlideImageTransformer $glideImageTransformer,
        protected ResourcePathRewriter $resourcePathRewriter,
    ) {
    }

    /**
     * Rewrite build-time image URLs with query params to static transformed files.
     *
     * Handles `img[src]`, `img[srcset]`, `source[src]`, and `source[srcset]`.
     *
     * @param string $html Rendered HTML.
     * @param \Glaze\Config\BuildConfig $config Build configuration.
     */
    public function rewrite(string $html, BuildConfig $config): string
    {
        $rewritten = preg_replace_callback(
            '/<(img|source)\b[^>]*>/i',
            fn(array $matches): string => $this->rewriteTagAttributes($matches[0], $config),
            $html,
        );

        return is_string($rewritten) ? $rewritten : $html;
    }

    /**
     * Rewrite supported source attributes inside a single HTML tag.
     *
     * @param string $tag Html tag source.
     * @param \Glaze\Config\BuildConfig $config Build configuration.
     */
    protected function rewriteTagAttributes(string $tag, BuildConfig $config): string
    {
        $tag = $this->rewriteAttributeValue(
            $tag,
            'src',
            fn(string $value): string => $this->rewriteSingleSource($value, $config),
        );

        return $this->rewriteAttributeValue(
            $tag,
            'srcset',
            fn(string $value): string => $this->rewriteSrcset($value, $config),
        );
    }

    /**
     * Rewrite a single attribute value in a tag when present.
     *
     * @param string $tag Html tag source.
     * @param string $attribute Attribute name to rewrite.
     * @param callable(string):string $rewriter Value rewriter callback.
     */
    protected function rewriteAttributeValue(string $tag, string $attribute, callable $rewriter): string
    {
        $pattern = sprintf(
            '/(\b%s\s*=\s*)("[^"]*"|\'[^\']*\'|[^\s>]+)/i',
            preg_quote($attribute, '/'),
        );

        $rewritten = preg_replace_callback(
            $pattern,
            static function (array $matches) use ($rewriter): string {
                $prefix = $matches[1];
                $rawValue = $matches[2];
                $quote = '';
                $value = $rawValue;

                if (str_starts_with($rawValue, '"') && str_ends_with($rawValue, '"')) {
                    $quote = '"';
                    $value = substr($rawValue, 1, -1);
                } elseif (str_starts_with($rawValue, "'") && str_ends_with($rawValue, "'")) {
                    $quote = "'";
                    $value = substr($rawValue, 1, -1);
                }

                $rewrittenValue = $rewriter($value);
                if ($rewrittenValue === $value) {
                    return $matches[0];
                }

                if ($quote === '' && preg_match('/\s/', $rewrittenValue) === 1) {
                    $quote = '"';
                }

                return $prefix . $quote . $rewrittenValue . $quote;
            },
            $tag,
            1,
        );

        return is_string($rewritten) ? $rewritten : $tag;
    }

    /**
     * Rewrite a single source URL when it contains Glide manipulation query parameters.
     *
     * @param string $source Source URL.
     * @param \Glaze\Config\BuildConfig $config Build configuration.
     */
    protected function rewriteSingleSource(string $source, BuildConfig $config): string
    {
        if ($source === '' || $this->resourcePathRewriter->isExternalResourcePath($source)) {
            return $source;
        }

        $parts = parse_url($source);
        if (!is_array($parts)) {
            return $source;
        }

        $path = $parts['path'] ?? null;
        if (!is_string($path) || $path === '') {
            return $source;
        }

        $queryString = $parts['query'] ?? null;
        if (!is_string($queryString) || trim($queryString) === '') {
            return $source;
        }

        parse_str($queryString, $queryParams);
        $normalizedQueryParams = [];
        foreach ($queryParams as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $normalizedQueryParams[$key] = $value;
        }

        $sourcePath = $this->resourcePathRewriter->stripBasePathFromPath($path, $config->site);
        $transformedPath = $this->glideImageTransformer->createTransformedPath(
            rootPath: $config->contentPath(),
            requestPath: $sourcePath,
            queryParams: $normalizedQueryParams,
            presets: $config->imagePresets,
            cachePath: $config->glideCachePath(),
            options: $config->imageOptions,
        );
        if (!is_string($transformedPath)) {
            return $source;
        }

        return $this->publishBuildGlideAsset(
            transformedPath: $transformedPath,
            sourcePath: $sourcePath,
            queryString: $queryString,
            config: $config,
        );
    }

    /**
     * Rewrite `srcset` candidate URLs while preserving descriptors.
     *
     * @param string $srcset Srcset attribute value.
     * @param \Glaze\Config\BuildConfig $config Build configuration.
     */
    protected function rewriteSrcset(string $srcset, BuildConfig $config): string
    {
        if (trim($srcset) === '') {
            return $srcset;
        }

        if (stripos($srcset, 'data:') !== false) {
            return $srcset;
        }

        $candidates = $this->splitSrcsetCandidates($srcset);
        if ($candidates === []) {
            return $srcset;
        }

        $hasChanges = false;
        $rewrittenCandidates = [];
        foreach ($candidates as $candidate) {
            $trimmedCandidate = trim($candidate);
            if ($trimmedCandidate === '') {
                continue;
            }

            preg_match('/^(\S+)(\s+.+)?$/', $trimmedCandidate, $parts);
            $url = $parts[1] ?? $trimmedCandidate;
            $descriptor = $parts[2] ?? '';

            $rewrittenUrl = $this->rewriteSingleSource($url, $config);
            if ($rewrittenUrl !== $url) {
                $hasChanges = true;
            }

            $rewrittenCandidates[] = $rewrittenUrl . $descriptor;
        }

        if (!$hasChanges) {
            return $srcset;
        }

        return implode(', ', $rewrittenCandidates);
    }

    /**
     * Split a srcset value into candidate segments.
     *
     * @param string $srcset Srcset attribute value.
     * @return array<string>
     */
    protected function splitSrcsetCandidates(string $srcset): array
    {
        $length = strlen($srcset);
        if ($length === 0) {
            return [];
        }

        $candidates = [];
        $buffer = '';
        $quote = null;
        $parenthesisDepth = 0;

        for ($index = 0; $index < $length; $index++) {
            $character = $srcset[$index];

            if ($quote !== null) {
                if ($character === $quote) {
                    $quote = null;
                }

                $buffer .= $character;
                continue;
            }

            if ($character === '"' || $character === "'") {
                $quote = $character;
                $buffer .= $character;
                continue;
            }

            if ($character === '(') {
                $parenthesisDepth++;
                $buffer .= $character;
                continue;
            }

            if ($character === ')' && $parenthesisDepth > 0) {
                $parenthesisDepth--;
                $buffer .= $character;
                continue;
            }

            if ($character === ',' && $parenthesisDepth === 0) {
                $candidates[] = $buffer;
                $buffer = '';
                continue;
            }

            $buffer .= $character;
        }

        if ($buffer !== '') {
            $candidates[] = $buffer;
        }

        return $candidates;
    }

    /**
     * Publish transformed Glide output to static build directory.
     *
     * @param string $transformedPath Absolute transformed image path.
     * @param string $sourcePath Internal source image path.
     * @param string $queryString Original query string.
     * @param \Glaze\Config\BuildConfig $config Build configuration.
     */
    protected function publishBuildGlideAsset(
        string $transformedPath,
        string $sourcePath,
        string $queryString,
        BuildConfig $config,
    ): string {
        $extension = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
        $hashedName = hash('xxh3', $sourcePath . '?' . $queryString);
        $fileName = $extension === '' ? $hashedName : $hashedName . '.' . $extension;
        $relativePath = '_glide/' . $fileName;

        $destination = $config->outputPath()
            . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        $directory = dirname($destination);
        if (!is_dir($directory) && !mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create Glide output directory "%s".', $directory));
        }

        if (!is_file($destination) && !copy($transformedPath, $destination)) {
            throw new RuntimeException(sprintf('Unable to copy transformed image to "%s".', $destination));
        }

        return $this->resourcePathRewriter->applyBasePathToPath('/' . $relativePath, $config->site);
    }
}
