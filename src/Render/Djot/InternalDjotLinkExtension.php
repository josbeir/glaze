<?php
declare(strict_types=1);

namespace Glaze\Render\Djot;

use Djot\DjotConverter;
use Djot\Event\RenderEvent;
use Djot\Extension\ExtensionInterface;
use Djot\Node\Inline\Image;
use Djot\Node\Inline\Link;
use Glaze\Config\SiteConfig;
use Glaze\Support\ResourcePathRewriter;

/**
 * Rewrites internal Djot document links to extensionless destinations.
 */
final class InternalDjotLinkExtension implements ExtensionInterface
{
    /**
     * Shared resource path rewriter.
     */
    protected ResourcePathRewriter $resourcePathRewriter;

    /**
     * Constructor.
     *
     * @param \Glaze\Support\ResourcePathRewriter $resourcePathRewriter Shared path rewriter service.
     * @param \Glaze\Config\SiteConfig|null $siteConfig Site configuration.
     * @param string|null $relativePagePath Relative source page path.
     */
    public function __construct(
        ResourcePathRewriter $resourcePathRewriter,
        protected ?SiteConfig $siteConfig = null,
        protected ?string $relativePagePath = null,
    ) {
        $this->resourcePathRewriter = $resourcePathRewriter;
    }

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

            if ($this->resourcePathRewriter->isExternalResourcePath($destination)) {
                return;
            }

            $rewrittenDestination = $this->stripDjotExtension($destination);
            if ($this->siteConfig instanceof SiteConfig && $this->relativePagePath !== null) {
                $rewrittenDestination = $this->resourcePathRewriter->rewriteDjotResourcePath(
                    $rewrittenDestination,
                    $this->relativePagePath,
                    $this->siteConfig,
                );
            }

            if ($rewrittenDestination === $destination) {
                return;
            }

            $node->setDestination($rewrittenDestination);
        });

        $converter->on('render.image', function (RenderEvent $event): void {
            $node = $event->getNode();
            if (!$node instanceof Image) {
                return;
            }

            $source = $node->getSource();
            if ($source === '') {
                return;
            }

            if ($this->resourcePathRewriter->isExternalResourcePath($source)) {
                return;
            }

            if (!$this->siteConfig instanceof SiteConfig || $this->relativePagePath === null) {
                return;
            }

            $rewrittenSource = $this->resourcePathRewriter->rewriteDjotResourcePath(
                $source,
                $this->relativePagePath,
                $this->siteConfig,
            );
            if ($rewrittenSource === $source) {
                return;
            }

            $alt = htmlspecialchars($node->getAlt(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $title = $node->getTitle();
            $attrs = $this->renderNodeAttributes($node->getAttributes());
            $html = '<img alt="' . $alt . '" src="'
                . htmlspecialchars($rewrittenSource, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
                . '"';
            if (is_string($title) && $title !== '') {
                $html .= ' title="' . htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
            }

            $event->setHtml($html . $attrs . '>');
        });
    }

    /**
     * Render Djot node attributes into an HTML attribute string.
     *
     * @param array<string, string> $attributes Node attributes.
     */
    protected function renderNodeAttributes(array $attributes): string
    {
        if ($attributes === []) {
            return '';
        }

        $parts = [];
        foreach ($attributes as $name => $value) {
            if (!is_string($name)) {
                continue;
            }

            $normalizedName = trim($name);
            if ($normalizedName === '') {
                continue;
            }

            $normalizedValue = is_string($value) ? $value : (string)$value;
            $parts[] = sprintf(
                ' %s="%s"',
                htmlspecialchars($normalizedName, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
                htmlspecialchars($normalizedValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            );
        }

        return implode('', $parts);
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
