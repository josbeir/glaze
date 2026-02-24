<?php
declare(strict_types=1);

namespace Glaze\Render\Djot;

use Djot\DjotConverter;
use Djot\Event\RenderEvent;
use Djot\Extension\ExtensionInterface;
use Djot\Node\Inline\Link;

/**
 * Rewrites internal Djot document links to extensionless destinations.
 */
final class InternalDjotLinkExtension implements ExtensionInterface
{
    /**
     * Register Djot render hooks.
     *
     * @param \Djot\DjotConverter $converter Djot converter instance.
     */
    public function register(DjotConverter $converter): void
    {
        $converter->on('render.link', function (RenderEvent $event): void {
            $node = $event->getNode();
            if (!$node instanceof Link) {
                return;
            }

            $destination = $node->getDestination();
            if (!is_string($destination) || trim($destination) === '') {
                return;
            }

            if ($this->isExternalDestination($destination)) {
                return;
            }

            $rewrittenDestination = $this->stripDjotExtension($destination);
            if ($rewrittenDestination === $destination) {
                return;
            }

            $node->setDestination($rewrittenDestination);
        });
    }

    /**
     * Detect whether the destination targets an external location.
     *
     * @param string $destination Link destination.
     */
    protected function isExternalDestination(string $destination): bool
    {
        if (str_starts_with($destination, '#')) {
            return true;
        }

        if (str_starts_with($destination, '//')) {
            return true;
        }

        return preg_match('/^[a-z][a-z0-9+.-]*:/i', $destination) === 1;
    }

    /**
     * Remove trailing .dj extension from destination path.
     *
     * @param string $destination Link destination.
     */
    protected function stripDjotExtension(string $destination): string
    {
        preg_match('/^([^?#]*)(.*)$/', $destination, $parts);
        $path = $parts[1] ?? $destination;
        $suffix = $parts[2] ?? '';

        if (!str_ends_with(strtolower($path), '.dj')) {
            return $destination;
        }

        $rewrittenPath = substr($path, 0, -3);

        return $rewrittenPath . $suffix;
    }
}
