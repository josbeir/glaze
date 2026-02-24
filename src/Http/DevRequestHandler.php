<?php
declare(strict_types=1);

namespace Glaze\Http;

use Cake\Http\Response;
use Glaze\Build\SiteBuilder;
use Glaze\Config\BuildConfig;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Handles live development HTTP requests for Glaze pages.
 */
final class DevRequestHandler
{
    protected SiteBuilder $siteBuilder;

    /**
     * Constructor.
     *
     * @param \Glaze\Config\BuildConfig $config Build configuration.
     * @param \Glaze\Build\SiteBuilder|null $siteBuilder Site builder service.
     */
    public function __construct(
        protected BuildConfig $config,
        ?SiteBuilder $siteBuilder = null,
    ) {
        $this->siteBuilder = $siteBuilder ?? new SiteBuilder();
    }

    /**
     * Check whether the request should be passed through to the static file server.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request Incoming request.
     */
    public function shouldPassthrough(ServerRequestInterface $request): bool
    {
        $requestPath = $this->requestPath($request);
        $publicPath = $this->config->outputPath() . DIRECTORY_SEPARATOR . ltrim($requestPath, '/');

        return is_file($publicPath);
    }

    /**
     * Handle a request and return an HTTP response.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request Incoming request.
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $requestPath = $this->requestPath($request);
        $html = $this->siteBuilder->renderRequest($this->config, $requestPath);

        if (!is_string($html)) {
            return (new Response(['charset' => 'UTF-8']))
                ->withStatus(404)
                ->withHeader('Content-Type', 'text/html; charset=UTF-8')
                ->withStringBody('<h1>404 Not Found</h1>');
        }

        return (new Response(['charset' => 'UTF-8']))
            ->withStatus(200)
            ->withHeader('Content-Type', 'text/html; charset=UTF-8')
            ->withStringBody($html);
    }

    /**
     * Normalize request path from URI.
     *
     * @param \Psr\Http\Message\ServerRequestInterface $request Incoming request.
     */
    protected function requestPath(ServerRequestInterface $request): string
    {
        $path = $request->getUri()->getPath();

        return $path !== '' ? $path : '/';
    }
}
