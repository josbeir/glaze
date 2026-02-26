<?php
declare(strict_types=1);

namespace Glaze\Render\Sugar\Path;

use Glaze\Config\SiteConfig;
use Glaze\Support\ResourcePathRewriter;
use Sugar\Core\Compiler\Pipeline\Enum\PassPriority;
use Sugar\Core\Extension\ExtensionInterface;
use Sugar\Core\Extension\RegistrationContext;

/**
 * Registers static resource path rewriting for Sugar templates.
 */
final class ResourcePathSugarExtension implements ExtensionInterface
{
    /**
     * Constructor.
     *
     * @param \Glaze\Config\SiteConfig $siteConfig Site configuration.
     * @param \Glaze\Support\ResourcePathRewriter $resourcePathRewriter Shared path rewriter.
     */
    public function __construct(
        protected SiteConfig $siteConfig,
        protected ResourcePathRewriter $resourcePathRewriter,
    ) {
    }

    /**
     * @inheritDoc
     */
    public function register(RegistrationContext $context): void
    {
        $context->compilerPass(
            new StaticResourceAttributeRewritePass($this->siteConfig, $this->resourcePathRewriter),
            PassPriority::POST_DIRECTIVE_COMPILATION,
        );
    }
}
