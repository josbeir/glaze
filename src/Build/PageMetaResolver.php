<?php
declare(strict_types=1);

namespace Glaze\Build;

use Glaze\Config\SiteConfig;
use Glaze\Content\ContentPage;
use Glaze\Utility\Normalization;

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

        $pageMetaOverrides = Normalization::stringMap($page->meta['meta'] ?? null);
        if ($pageMetaOverrides !== []) {
            $effective = array_replace($effective, $pageMetaOverrides);
        }

        $pageDescription = Normalization::optionalString($page->meta['description'] ?? null);
        if ($pageDescription !== null) {
            $effective['description'] = $pageDescription;
        }

        return $effective;
    }
}
