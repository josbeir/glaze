<?php
declare(strict_types=1);

namespace Glaze\Render\Djot;

use Djot\DjotConverter;
use Djot\Event\RenderEvent;
use Djot\Extension\ExtensionInterface;
use Djot\Node\Block\CodeBlock;
use Throwable;

/**
 * Djot extension that highlights fenced code blocks with Phiki.
 */
final class PhikiExtension implements ExtensionInterface
{
    /**
     * Constructor.
     *
     * @param \Glaze\Render\Djot\PhikiCodeBlockRenderer $codeBlockRenderer Code block renderer.
     */
    public function __construct(protected PhikiCodeBlockRenderer $codeBlockRenderer = new PhikiCodeBlockRenderer())
    {
    }

    /**
     * Register rendering hooks on the Djot converter.
     *
     * @param \Djot\DjotConverter $converter Djot converter instance.
     */
    public function register(DjotConverter $converter): void
    {
        $converter->on('render.code_block', function (RenderEvent $event): void {
            $node = $event->getNode();
            if (!$node instanceof CodeBlock) {
                return;
            }

            try {
                $event->setHtml($this->codeBlockRenderer->render($node));
            } catch (Throwable) {
                // Keep default Djot rendering if Phiki highlighting fails.
            }
        });
    }
}
