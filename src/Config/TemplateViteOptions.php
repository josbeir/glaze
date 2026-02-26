<?php
declare(strict_types=1);

namespace Glaze\Config;

use Glaze\Utility\Normalization;

/**
 * Immutable typed options for Sugar Vite template integration.
 *
 * Constructed directly from the raw `build.vite` and `devServer.vite`
 * configuration blocks read from glaze.neon. Neon's parser preserves
 * native PHP types, so no intermediate normalisation step is required.
 *
 * Example:
 *   $options = TemplateViteOptions::fromProjectConfig(
 *       $config['build']['vite'] ?? [],
 *       $config['devServer']['vite'] ?? [],
 *       $projectRoot,
 *   );
 */
final readonly class TemplateViteOptions
{
    /**
     * Constructor.
     *
     * @param bool $buildEnabled Whether Vite is enabled in build mode.
     * @param bool $devEnabled Whether Vite is enabled in dev mode.
     * @param string $assetBaseUrl Public base URL for emitted assets. Defaults to '/' — Sugar Vite appends the full
     *                              manifest file path (e.g. 'assets/app.js') to this URL, so the directory segment
     *                              should not be included here.
     * @param string $manifestPath Absolute path to the Vite manifest.
     * @param string $devServerUrl Dev server origin URL.
     * @param bool $injectClient Whether `@vite/client` is auto-injected in dev mode.
     * @param string|null $defaultEntry Default entry used when directive is boolean.
     */
    public function __construct(
        public bool $buildEnabled = false,
        public bool $devEnabled = false,
        public string $assetBaseUrl = '/',
        public string $manifestPath = '',
        public string $devServerUrl = 'http://127.0.0.1:5173',
        public bool $injectClient = true,
        public ?string $defaultEntry = null,
    ) {
    }

    /**
     * Build typed options directly from the `build.vite` and `devServer.vite` config blocks.
     *
     * The manifest path is resolved relative to the project root when not absolute.
     * The dev server URL is derived from explicit `url`, or assembled from `host`/`port`.
     *
     * @param array<string, mixed> $buildVite Raw `build.vite` configuration map.
     * @param array<string, mixed> $devVite Raw `devServer.vite` configuration map.
     * @param string $projectRoot Absolute project root path.
     */
    public static function fromProjectConfig(array $buildVite, array $devVite, string $projectRoot): self
    {
        $assetBaseUrl = is_string($buildVite['assetBaseUrl'] ?? null) && trim($buildVite['assetBaseUrl']) !== ''
            ? $buildVite['assetBaseUrl']
            : '/';

        $manifestPath = is_string($buildVite['manifestPath'] ?? null) && trim($buildVite['manifestPath']) !== ''
            ? trim($buildVite['manifestPath'])
            : 'public/.vite/manifest.json';

        if (!str_starts_with($manifestPath, '/') && !str_starts_with($manifestPath, DIRECTORY_SEPARATOR)) {
            $manifestPath = $projectRoot . DIRECTORY_SEPARATOR . ltrim($manifestPath, '/\\');
        }

        $devServerUrl = is_string($devVite['url'] ?? null) && trim($devVite['url']) !== ''
            ? trim($devVite['url'])
            : self::buildDevServerUrl($devVite);

        $defaultEntry =
            (is_string($devVite['defaultEntry'] ?? null) && trim($devVite['defaultEntry']) !== ''
                ? trim($devVite['defaultEntry'])
                : null)
            ?? (is_string($buildVite['defaultEntry'] ?? null) && trim($buildVite['defaultEntry']) !== ''
                ? trim($buildVite['defaultEntry'])
                : null);

        return new self(
            buildEnabled: ($buildVite['enabled'] ?? false) === true,
            devEnabled: ($devVite['enabled'] ?? false) === true,
            assetBaseUrl: rtrim($assetBaseUrl, '/') . '/',
            manifestPath: Normalization::path($manifestPath),
            devServerUrl: $devServerUrl,
            injectClient: is_bool($devVite['injectClient'] ?? null) ? $devVite['injectClient'] : true,
            defaultEntry: $defaultEntry,
        );
    }

    /**
     * Assemble a dev server URL from `host` and `port` configuration values.
     *
     * Falls back to `127.0.0.1:5173` when either value is absent or invalid.
     *
     * @param array<string, mixed> $devVite Raw `devServer.vite` configuration map.
     */
    private static function buildDevServerUrl(array $devVite): string
    {
        $host = is_string($devVite['host'] ?? null) && trim($devVite['host']) !== ''
            ? trim($devVite['host'])
            : '127.0.0.1';

        $port = is_int($devVite['port'] ?? null) ? $devVite['port'] : 5173;
        if ($port < 1 || $port > 65535) {
            $port = 5173;
        }

        return sprintf('http://%s:%d', $host, $port);
    }
}
