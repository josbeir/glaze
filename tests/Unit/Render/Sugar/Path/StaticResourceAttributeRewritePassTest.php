<?php
declare(strict_types=1);

namespace Glaze\Tests\Unit\Render\Sugar\Path;

use Glaze\Config\SiteConfig;
use Glaze\Render\Sugar\Path\StaticResourceAttributeRewritePass;
use Glaze\Support\ResourcePathRewriter;
use PHPUnit\Framework\TestCase;
use Sugar\Core\Ast\AttributeNode;
use Sugar\Core\Ast\AttributeValue;
use Sugar\Core\Ast\ElementNode;
use Sugar\Core\Ast\Node;
use Sugar\Core\Ast\OutputNode;
use Sugar\Core\Ast\TextNode;
use Sugar\Core\Compiler\CompilationContext;
use Sugar\Core\Compiler\Pipeline\NodeAction;
use Sugar\Core\Compiler\Pipeline\PipelineContext;
use Sugar\Core\Escape\Enum\OutputContext;

/**
 * Tests for static resource attribute rewriting pass.
 */
final class StaticResourceAttributeRewritePassTest extends TestCase
{
    /**
     * Ensure non-element nodes are ignored.
     */
    public function testBeforeSkipsNonElementNode(): void
    {
        $pass = $this->createPass();
        $node = new TextNode('hello', 1, 1);

        $action = $pass->before($node, $this->pipelineContext($node));

        $this->assertInstanceOf(NodeAction::class, $action);
        $this->assertNull($action->replaceWith);
        $this->assertFalse($action->skipChildren);
        $this->assertFalse($action->restartPass);
    }

    /**
     * Ensure static href/src attributes are rewritten with configured base path.
     */
    public function testBeforeRewritesStaticHrefAndSrcAttributes(): void
    {
        $pass = $this->createPass('/docs');
        $node = new ElementNode(
            tag: 'link',
            attributes: [
                new AttributeNode('href', AttributeValue::static('/assets/site.css'), 1, 1),
                new AttributeNode('src', AttributeValue::static('/images/logo.png'), 1, 1),
                new AttributeNode('data-src', AttributeValue::static('/images/raw.png'), 1, 1),
            ],
            children: [],
            selfClosing: true,
            line: 1,
            column: 1,
        );

        $pass->before($node, $this->pipelineContext($node));

        $this->assertSame('/docs/assets/site.css', $node->attributes[0]->value->static);
        $this->assertSame('/docs/images/logo.png', $node->attributes[1]->value->static);
        $this->assertSame('/images/raw.png', $node->attributes[2]->value->static);
    }

    /**
     * Ensure pass skips external, empty, and dynamic href/src values.
     */
    public function testBeforeSkipsExternalEmptyAndDynamicAttributeValues(): void
    {
        $pass = $this->createPass('/docs');
        $node = new ElementNode(
            tag: 'img',
            attributes: [
                new AttributeNode('src', AttributeValue::static('https://example.com/image.png'), 1, 1),
                new AttributeNode('href', AttributeValue::static(''), 1, 1),
                new AttributeNode('src', AttributeValue::output(new OutputNode('$img', true, OutputContext::URL, 1, 1)), 1, 1),
            ],
            children: [],
            selfClosing: true,
            line: 1,
            column: 1,
        );

        $pass->before($node, $this->pipelineContext($node));

        $this->assertSame('https://example.com/image.png', $node->attributes[0]->value->static);
        $this->assertSame('', $node->attributes[1]->value->static);
        $this->assertTrue($node->attributes[2]->value->isOutput());
    }

    /**
     * Ensure after hook is a no-op action.
     */
    public function testAfterReturnsNoOpAction(): void
    {
        $pass = $this->createPass('/docs');
        $node = new TextNode('ok', 1, 1);

        $action = $pass->after($node, $this->pipelineContext($node));

        $this->assertInstanceOf(NodeAction::class, $action);
        $this->assertNull($action->replaceWith);
        $this->assertFalse($action->skipChildren);
        $this->assertFalse($action->restartPass);
    }

    /**
     * Create pass under test.
     *
     * @param string|null $basePath Base path value.
     */
    protected function createPass(?string $basePath = null): StaticResourceAttributeRewritePass
    {
        return new StaticResourceAttributeRewritePass(
            siteConfig: new SiteConfig(basePath: $basePath),
            resourcePathRewriter: new ResourcePathRewriter(),
        );
    }

    /**
     * Create pipeline context instance for pass tests.
     *
     * @param \Sugar\Core\Ast\Node|null $parent Parent node.
     */
    protected function pipelineContext(?Node $parent = null): PipelineContext
    {
        return new PipelineContext(
            new CompilationContext('/tmp/template.sugar.php', '<a/>', false),
            $parent,
            0,
        );
    }
}
