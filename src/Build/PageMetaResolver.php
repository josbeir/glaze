<?php
declare(strict_types=1);

namespace Glaze\Build;

use Glaze\Config\SiteConfig;
use Glaze\Content\ContentPage;

/**
 * Resolves effective page meta from site defaults and page-level overrides.
 */
final class PageMetaResolver
{
    /**
     * Resolve effective page meta.
     *
     * @param \Glaze\Content\ContentPage $page Page data.
     * @param \Glaze\Config\SiteConfig $siteConfig Site-wide configuration.
     * @return array<string, string>
     */
    public function resolve(ContentPage $page, SiteConfig $siteConfig): array
    {
        $effective = $siteConfig->metaDefaults;

        if (!isset($effective['description']) && $siteConfig->description !== null) {
            $effective['description'] = $siteConfig->description;
        }

        $pageMetaOverrides = $this->normalizeMetaMap($page->meta['meta'] ?? null);
        if ($pageMetaOverrides !== []) {
            $effective = array_replace($effective, $pageMetaOverrides);
        }

        $pageDescription = $this->normalizeString($page->meta['description'] ?? null);
        if ($pageDescription !== null) {
            $effective['description'] = $pageDescription;
        }

        return $effective;
    }

    /**
     * Normalize map-like meta values.
     *
     * @param mixed $value Raw input value.
     * @return array<string, string>
     */
    protected function normalizeMetaMap(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                continue;
            }

            $normalizedKey = trim($key);
            if ($normalizedKey === '') {
                continue;
            }

            if (!is_scalar($item)) {
                continue;
            }

            $normalized[$normalizedKey] = trim((string)$item);
        }

        return $normalized;
    }

    /**
     * Normalize optional string values.
     *
     * @param mixed $value Raw input value.
     */
    protected function normalizeString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }
}
