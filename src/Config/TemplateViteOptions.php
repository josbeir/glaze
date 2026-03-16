<?php
declare(strict_types=1);

namespace Glaze\Config;

use Glaze\Utility\Path;

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
     * @param string $mode Vite resolver mode: `auto`, `dev`, or `prod`. In `auto`, Glaze resolves to `dev` only
     *                     for live renders where diagnostics are enabled; all other renders use `prod`.
     */
    public function __construct(
        public bool $buildEnabled = false,
        public bool $devEnabled = false,
        public string $assetBaseUrl = '/',
        public string $manifestPath = '',
        public string $devServerUrl = 'http://127.0.0.1:5173',
        public bool $injectClient = true,
        public ?string $defaultEntry = null,
        public string $mode = 'auto',
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

        if (!str_starts_with($manifestPath, '/')) {
            $manifestPath = $projectRoot . '/' . ltrim($manifestPath, '/\\');
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
            manifestPath: Path::normalize($manifestPath),
            devServerUrl: $devServerUrl,
            injectClient: is_bool($devVite['injectClient'] ?? null) ? $devVite['injectClient'] : true,
            defaultEntry: $defaultEntry,
            mode: is_string($buildVite['mode'] ?? null) && in_array($buildVite['mode'], ['auto', 'dev', 'prod'], true)
                ? $buildVite['mode']
                : 'auto',
        );
    }

    /**
     * Return a new instance with runtime environment overrides applied.
     *
     * Reads `GLAZE_VITE_ENABLED` and `GLAZE_VITE_URL` from the process environment and
     * overlays them on the current options. This is the only place in the codebase that
     * reads these variables; call it at the factory/construction layer, not inside renderers.
     *
     * - `GLAZE_VITE_ENABLED=1` forces devEnabled on; `0` forces it off.
     * - `GLAZE_VITE_URL` replaces devServerUrl when non-empty.
     */
    public function applyEnvironmentOverrides(): self
    {
        $devEnabled = $this->devEnabled;
        $enabledOverride = getenv('GLAZE_VITE_ENABLED');
        if ($enabledOverride === '1') {
            $devEnabled = true;
        } elseif ($enabledOverride === '0') {
            $devEnabled = false;
        }

        $devServerUrl = $this->devServerUrl;
        $runtimeUrl = getenv('GLAZE_VITE_URL');
        if (is_string($runtimeUrl) && $runtimeUrl !== '') {
            $devServerUrl = $runtimeUrl;
        }

        return new self(
            buildEnabled: $this->buildEnabled,
            devEnabled: $devEnabled,
            assetBaseUrl: $this->assetBaseUrl,
            manifestPath: $this->manifestPath,
            devServerUrl: $devServerUrl,
            injectClient: $this->injectClient,
            defaultEntry: $this->defaultEntry,
            mode: $this->mode,
        );
    }

    /**
     * Return a copy of this options object with a different Vite mode.
     *
     * @param string $mode Vite resolver mode: `auto`, `dev`, or `prod`.
     */
    public function withMode(string $mode): self
    {
        return new self(
            buildEnabled: $this->buildEnabled,
            devEnabled: $this->devEnabled,
            assetBaseUrl: $this->assetBaseUrl,
            manifestPath: $this->manifestPath,
            devServerUrl: $this->devServerUrl,
            injectClient: $this->injectClient,
            defaultEntry: $this->defaultEntry,
            mode: $mode,
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
