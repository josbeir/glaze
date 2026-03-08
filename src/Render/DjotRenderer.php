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
use Glaze\Render\Djot\SourceIncludeProcessor;
use Glaze\Render\Djot\TocExtension;

/**
 * Converts Djot source documents to HTML, including TOC collection and source preprocessing.
 *
 * Source include directives (`<!--@include: path-->`) are expanded before the Djot
 * converter runs, allowing partial files to be spliced into any document.
 *
 * Example:
 *   $result = $renderer->render($source, $djotOptions, $siteConfig, 'guide/index.dj', config: $buildConfig);
 *   echo $result->html;
 */
final class DjotRenderer
{
    /**
     * Constructor.
     *
     * @param \Glaze\Render\Djot\DjotConverterFactory $converterFactory Djot converter factory.
     * @param \Glaze\Render\Djot\SourceIncludeProcessor $sourceIncludeProcessor Source include preprocessor.
     */
    public function __construct(
        protected DjotConverterFactory $converterFactory,
        protected SourceIncludeProcessor $sourceIncludeProcessor = new SourceIncludeProcessor(),
    ) {
    }

    /**
     * Render Djot source to HTML and collect table-of-contents entries in a single pass.
     *
     * When both `$relativePagePath` and `$config` are provided, source include directives
     * are expanded before the Djot converter runs.
     *
     * @param string $source Djot source content.
     * @param \Glaze\Config\DjotOptions|null $djot Djot renderer options.
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
    ): RenderResult {
        if ($relativePagePath !== null && $config instanceof BuildConfig) {
            $baseDir = dirname($config->contentPath() . '/' . $relativePagePath);
            $source = $this->sourceIncludeProcessor->process($source, $baseDir, $config->contentPath());
        }

        $toc = new TocExtension();
        $converter = $this->createConverter($djot ?? new DjotOptions(), $siteConfig, $relativePagePath, $config);

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
     * @param \Glaze\Config\BuildConfig|null $config Optional active build configuration.
     */
    protected function createConverter(
        DjotOptions $djot,
        ?SiteConfig $siteConfig = null,
        ?string $relativePagePath = null,
        ?BuildConfig $config = null,
    ): DjotConverter {
        return $this->converterFactory->create($djot, $siteConfig, $relativePagePath, $config);
    }
}
