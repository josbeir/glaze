<?php
declare(strict_types=1);

namespace Glaze\Image;

use Glaze\Utility\Normalization;

/**
 * Resolves effective Glide manipulations from presets and raw query parameters.
 */
final class ImagePresetResolver
{
    /**
     * Resolve image manipulation parameters.
     *
     * @param array<string, mixed> $queryParams Request query parameters.
     * @param array<string, array<string, string>> $presets Configured preset map.
     * @return array<string, string>
     */
    public function resolve(array $queryParams, array $presets): array
    {
        $presetName = $this->resolvePresetName($queryParams);
        $resolved = [];

        if ($presetName !== null && isset($presets[$presetName])) {
            $resolved = $presets[$presetName];
        }

        foreach ($queryParams as $key => $value) {
            if (!is_string($key)) {
                continue;
            }

            $normalizedKey = trim($key);
            if ($normalizedKey === '') {
                continue;
            }

            if ($normalizedKey === 'preset') {
                continue;
            }

            if ($normalizedKey === 'p' && Normalization::optionalString($value) !== null) {
                continue;
            }

            $normalizedValue = Normalization::optionalScalarString($value);
            if ($normalizedValue === null) {
                continue;
            }

            $resolved[$normalizedKey] = $normalizedValue;
        }

        return $resolved;
    }

    /**
     * Resolve preset name from supported query parameter keys.
     *
     * @param array<string, mixed> $queryParams Request query parameters.
     */
    protected function resolvePresetName(array $queryParams): ?string
    {
        $preset = Normalization::optionalString($queryParams['preset'] ?? null);
        if ($preset !== null) {
            return $preset;
        }

        return Normalization::optionalString($queryParams['p'] ?? null);
    }
}
