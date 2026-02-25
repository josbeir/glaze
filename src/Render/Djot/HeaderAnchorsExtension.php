<?php
declare(strict_types=1);

namespace Glaze\Render\Djot;

use Djot\DjotConverter;
use Djot\Event\RenderEvent;
use Djot\Extension\ExtensionInterface;
use Djot\Node\Block\Heading;
use Djot\Node\Inline\Link;
use Djot\Node\Inline\Text;

/**
 * Injects configurable anchor links into rendered Djot headings.
 */
final class HeaderAnchorsExtension implements ExtensionInterface
{
    /**
     * Constructor.
     *
     * @param string $symbol Anchor symbol text.
     * @param string $position Link placement, either `before` or `after`.
     * @param string $cssClass CSS class applied to each heading anchor link.
     * @param string $ariaLabel Accessible label for heading anchor links.
     * @param array<int> $levels Heading levels that receive anchors.
     */
    public function __construct(
        protected string $symbol = '#',
        protected string $position = 'after',
        protected string $cssClass = 'header-anchor',
        protected string $ariaLabel = 'Anchor link',
        protected array $levels = [1, 2, 3, 4, 5, 6],
    ) {
    }

    /**
     * Register heading render hook.
     *
     * @param \Djot\DjotConverter $converter Djot converter instance.
     */
    public function register(DjotConverter $converter): void
    {
        $tracker = $converter->getHeadingIdTracker();

        $converter->on('render.heading', function (RenderEvent $event) use ($tracker): void {
            $node = $event->getNode();
            if (!$node instanceof Heading) {
                return;
            }

            if (!in_array($node->getLevel(), $this->levels, true)) {
                return;
            }

            $id = $tracker->getIdForHeading($node);
            if ($id === '') {
                return;
            }

            if (!$node->hasAttribute('id')) {
                $node->setAttribute('id', $id);
            }

            $anchorLink = new Link('#' . $id);
            $anchorLink->addClass($this->cssClass);
            $anchorLink->setAttribute('aria-label', $this->ariaLabel);
            $anchorLink->appendChild(new Text($this->symbol));

            if ($this->position === 'before') {
                $node->prependChild(new Text(' '));
                $node->prependChild($anchorLink);

                return;
            }

            $node->appendChild(new Text(' '));
            $node->appendChild($anchorLink);
        });
    }
}
