<?php
declare(strict_types=1);

namespace Glaze\Render\Djot;

use Djot\DjotConverter;
use Djot\Extension\AutolinkExtension;
use Djot\Extension\DefaultAttributesExtension;
use Djot\Extension\ExternalLinksExtension;
use Djot\Extension\HeadingPermalinksExtension;
use Djot\Extension\MentionsExtension;
use Djot\Extension\SemanticSpanExtension;
use Djot\Extension\SmartQuotesExtension;
use Glaze\Config\DjotOptions;
use Glaze\Config\SiteConfig;
use Glaze\Support\ResourcePathRewriter;

/**
 * Factory for fully configured Djot converter instances.
 */
final class DjotConverterFactory
{
    /**
     * Constructor.
     *
     * @param \Glaze\Support\ResourcePathRewriter $resourcePathRewriter Shared resource path rewriter.
     * @param \Glaze\Render\Djot\PhikiThemeResolver $phikiThemeResolver Resolver for Phiki single- and multi-theme options.
     */
    public function __construct(
        protected ResourcePathRewriter $resourcePathRewriter,
        protected PhikiThemeResolver $phikiThemeResolver,
    ) {
    }

    /**
     * Create a converter instance with configured extensions.
     *
     * @param \Glaze\Config\DjotOptions $djot Djot renderer options.
     * @param \Glaze\Config\SiteConfig|null $siteConfig Site configuration used for internal path rewriting.
     * @param string|null $relativePagePath Relative source page path for content-relative links.
     */
    public function create(
        DjotOptions $djot,
        ?SiteConfig $siteConfig = null,
        ?string $relativePagePath = null,
    ): DjotConverter {
        $converter = new DjotConverter();
        $converter->addExtension(new InternalDjotLinkExtension(
            resourcePathRewriter: $this->resourcePathRewriter,
            siteConfig: $siteConfig,
            relativePagePath: $relativePagePath,
        ));

        $phikiCodeBlockRenderer = null;
        if ($djot->codeHighlightingEnabled) {
            $phikiCodeBlockRenderer = new PhikiCodeBlockRenderer(
                theme: $this->phikiThemeResolver->resolve($djot),
                withGutter: $djot->codeHighlightingWithGutter,
            );
        }

        foreach ($this->buildOptionalExtensionRegistrations($djot, $phikiCodeBlockRenderer) as $registration) {
            if (!$registration['enabled']) {
                continue;
            }

            $converter->addExtension($registration['extension']);
        }

        $converter->addExtension(new CodeGroupExtension($phikiCodeBlockRenderer));

        return $converter;
    }

    /**
     * Build optional extension registrations from Djot options.
     *
     * @param \Glaze\Config\DjotOptions $djot Djot renderer options.
     * @param \Glaze\Render\Djot\PhikiCodeBlockRenderer|null $phikiCodeBlockRenderer Optional Phiki code block renderer.
     * @return list<array{enabled: bool, extension: \Djot\Extension\ExtensionInterface}>
     */
    protected function buildOptionalExtensionRegistrations(
        DjotOptions $djot,
        ?PhikiCodeBlockRenderer $phikiCodeBlockRenderer,
    ): array {
        return [
            [
                'enabled' => $djot->headerAnchorsEnabled && class_exists(HeadingPermalinksExtension::class),
                'extension' => new HeadingPermalinksExtension(
                    symbol: $djot->headerAnchorsSymbol,
                    position: $djot->headerAnchorsPosition,
                    cssClass: $djot->headerAnchorsCssClass,
                    ariaLabel: $djot->headerAnchorsAriaLabel,
                    levels: $djot->headerAnchorsLevels,
                ),
            ],
            [
                'enabled' => $djot->autolinkEnabled && class_exists(AutolinkExtension::class),
                'extension' => new AutolinkExtension(
                    allowedSchemes: $djot->autolinkAllowedSchemes,
                ),
            ],
            [
                'enabled' => $djot->externalLinksEnabled && class_exists(ExternalLinksExtension::class),
                'extension' => new ExternalLinksExtension(
                    internalHosts: $djot->externalLinksInternalHosts,
                    target: $djot->externalLinksTarget,
                    rel: $djot->externalLinksRel,
                    nofollow: $djot->externalLinksNofollow,
                ),
            ],
            [
                'enabled' => $djot->smartQuotesEnabled && class_exists(SmartQuotesExtension::class),
                'extension' => new SmartQuotesExtension(
                    locale: $djot->smartQuotesLocale,
                    openDoubleQuote: $djot->smartQuotesOpenDouble,
                    closeDoubleQuote: $djot->smartQuotesCloseDouble,
                    openSingleQuote: $djot->smartQuotesOpenSingle,
                    closeSingleQuote: $djot->smartQuotesCloseSingle,
                ),
            ],
            [
                'enabled' => $djot->mentionsEnabled && class_exists(MentionsExtension::class),
                'extension' => new MentionsExtension(
                    urlTemplate: $djot->mentionsUrlTemplate,
                    cssClass: $djot->mentionsCssClass,
                ),
            ],
            [
                'enabled' => $djot->semanticSpanEnabled && class_exists(SemanticSpanExtension::class),
                'extension' => new SemanticSpanExtension(),
            ],
            [
                'enabled' => $djot->defaultAttributesEnabled && class_exists(DefaultAttributesExtension::class),
                'extension' => new DefaultAttributesExtension(
                    defaults: $djot->defaultAttributesDefaults,
                ),
            ],
            [
                'enabled' => $phikiCodeBlockRenderer instanceof PhikiCodeBlockRenderer,
                'extension' => new PhikiExtension($phikiCodeBlockRenderer ?? new PhikiCodeBlockRenderer()),
            ],
        ];
    }
}
