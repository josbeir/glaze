<?php
declare(strict_types=1);

namespace Glaze\Render\Sugar\Path;

use Glaze\Config\SiteConfig;
use Glaze\Support\ResourcePathRewriter;
use Sugar\Core\Ast\AttributeValue;
use Sugar\Core\Ast\ElementNode;
use Sugar\Core\Ast\Node;
use Sugar\Core\Compiler\Pipeline\AstPassInterface;
use Sugar\Core\Compiler\Pipeline\NodeAction;
use Sugar\Core\Compiler\Pipeline\PipelineContext;

/**
 * Rewrites static href/src attributes in Sugar element nodes.
 */
final class StaticResourceAttributeRewritePass implements AstPassInterface
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
    public function before(Node $node, PipelineContext $context): NodeAction
    {
        if (!$node instanceof ElementNode) {
            return NodeAction::none();
        }

        foreach ($node->attributes as $attribute) {
            if (!in_array(strtolower($attribute->name), ['href', 'src'], true)) {
                continue;
            }

            if (!$attribute->value->isStatic()) {
                continue;
            }

            $value = $attribute->value->static;
            if (!is_string($value)) {
                continue;
            }

            if ($value === '') {
                continue;
            }

            $rewritten = $this->resourcePathRewriter->rewriteTemplateResourcePath($value, $this->siteConfig);
            if ($rewritten === $value) {
                continue;
            }

            $attribute->value = AttributeValue::static($rewritten);
        }

        return NodeAction::none();
    }

    /**
     * @inheritDoc
     */
    public function after(Node $node, PipelineContext $context): NodeAction
    {
        return NodeAction::none();
    }
}
