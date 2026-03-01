<?php
declare(strict_types=1);

namespace Glaze\Render;

use Djot\DjotConverter;
use Glaze\Build\Event\BuildEvent;
use Glaze\Build\Event\DjotConverterCreatedEvent;
use Glaze\Build\Event\EventDispatcher;
use Glaze\Config\BuildConfig;
use Glaze\Config\DjotOptions;
use Glaze\Config\SiteConfig;
use Glaze\Content\ContentPage;
use Glaze\Render\Djot\DjotConverterFactory;
use Glaze\Render\Djot\TocExtension;

/**
 * Converts Djot source documents to HTML.
 */
final class DjotRenderer
{
    /**
     * Constructor.
     *
     * @param \Glaze\Render\Djot\DjotConverterFactory $converterFactory Djot converter factory.
     */
    public function __construct(
        protected DjotConverterFactory $converterFactory,
    ) {
    }

    /**
     * Render Djot source to HTML.
     *
     * @param string $source Djot source content.
     * @param \Glaze\Config\DjotOptions $djot Djot renderer options.
     * @param \Glaze\Config\SiteConfig|null $siteConfig Site configuration used for internal path rewriting.
     * @param string|null $relativePagePath Relative source page path for content-relative links.
     * @param \Glaze\Build\Event\EventDispatcher|null $dispatcher Optional build event dispatcher.
     * @param \Glaze\Content\ContentPage|null $page Optional page currently being rendered.
     * @param \Glaze\Config\BuildConfig|null $config Optional active build configuration.
     */
    public function render(
        string $source,
        ?DjotOptions $djot = null,
        ?SiteConfig $siteConfig = null,
        ?string $relativePagePath = null,
        ?EventDispatcher $dispatcher = null,
        ?ContentPage $page = null,
        ?BuildConfig $config = null,
    ): string {
        return $this->renderWithToc(
            source: $source,
            djot: $djot,
            siteConfig: $siteConfig,
            relativePagePath: $relativePagePath,
            dispatcher: $dispatcher,
            page: $page,
            config: $config,
        )->html;
    }

    /**
     * Render Djot source to HTML and collect table-of-contents entries in a single pass.
     *
     * @param string $source Djot source content.
     * @param \Glaze\Config\DjotOptions $djot Djot renderer options.
     * @param \Glaze\Config\SiteConfig|null $siteConfig Site configuration used for internal path rewriting.
     * @param string|null $relativePagePath Relative source page path for content-relative links.
     * @param \Glaze\Build\Event\EventDispatcher|null $dispatcher Optional build event dispatcher.
     * @param \Glaze\Content\ContentPage|null $page Optional page currently being rendered.
     * @param \Glaze\Config\BuildConfig|null $config Optional active build configuration.
     */
    public function renderWithToc(
        string $source,
        ?DjotOptions $djot = null,
        ?SiteConfig $siteConfig = null,
        ?string $relativePagePath = null,
        ?EventDispatcher $dispatcher = null,
        ?ContentPage $page = null,
        ?BuildConfig $config = null,
    ): RenderResult {
        $toc = new TocExtension();
        $converter = $this->createConverter($djot ?? new DjotOptions(), $siteConfig, $relativePagePath);

        if (
            $dispatcher instanceof EventDispatcher
            && $page instanceof ContentPage
            && $config instanceof BuildConfig
        ) {
            $dispatcher->dispatch(
                BuildEvent::DjotConverterCreated,
                new DjotConverterCreatedEvent($converter, $page, $config),
            );
        }

        $converter->addExtension($toc);

        $html = $toc->injectToc($converter->convert($source));

        return new RenderResult(html: $html, toc: $toc->getEntries());
    }

    /**
     * Create a converter instance with configured extensions.
     *
     * @param \Glaze\Config\DjotOptions $djot Djot renderer options.
     * @param \Glaze\Config\SiteConfig|null $siteConfig Site configuration used for internal path rewriting.
     * @param string|null $relativePagePath Relative source page path for content-relative links.
     */
    protected function createConverter(
        DjotOptions $djot,
        ?SiteConfig $siteConfig = null,
        ?string $relativePagePath = null,
    ): DjotConverter {
        return $this->converterFactory->create($djot, $siteConfig, $relativePagePath);
    }
}
