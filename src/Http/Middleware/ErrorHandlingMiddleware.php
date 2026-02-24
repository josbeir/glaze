<?php
declare(strict_types=1);

namespace Glaze\Http\Middleware;

use Cake\Http\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Throwable;

/**
 * Catches runtime exceptions from live request handling and returns HTML responses.
 */
final class ErrorHandlingMiddleware implements MiddlewareInterface
{
    /**
     * Constructor.
     *
     * @param bool $debug Whether to include exception details in responses.
     */
    public function __construct(protected bool $debug = true)
    {
    }

    /**
     * @inheritDoc
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        try {
            return $handler->handle($request);
        } catch (Throwable $throwable) {
            return $this->errorResponse($throwable);
        }
    }

    /**
     * Build a 500 error response from a throwable.
     *
     * @param \Throwable $throwable Caught throwable.
     */
    protected function errorResponse(Throwable $throwable): ResponseInterface
    {
        if (!$this->debug) {
            return (new Response(['charset' => 'UTF-8']))
                ->withStatus(500)
                ->withHeader('Content-Type', 'text/html; charset=UTF-8')
                ->withStringBody('<h1>500 Internal Server Error</h1>');
        }

        $message = htmlspecialchars($throwable->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $trace = htmlspecialchars($throwable->getTraceAsString(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $bodyStyle = 'margin:0;padding:24px;background:#0f172a;color:#e2e8f0;'
            . 'font-family:ui-monospace,Menlo,Monaco,Consolas,monospace;';
        $headingStyle = 'margin:0 0 16px 0;font-size:22px;';
        $sectionStyle = 'margin:0 0 8px 0;font-size:14px;color:#93c5fd;';
        $messageStyle = 'margin:0 0 16px 0;background:#111827;color:#f9fafb;'
            . 'padding:12px;border-radius:8px;overflow:auto;line-height:1.4;';
        $traceStyle = 'margin:0;background:#111827;color:#f9fafb;padding:12px;'
            . 'border-radius:8px;overflow:auto;line-height:1.4;';

        $body = sprintf(
            '<!doctype html><html lang="en"><head><meta charset="utf-8" />'
            . '<title>500 Internal Server Error</title></head><body style="%s">'
            . '<h1 style="%s">500 Internal Server Error</h1>'
            . '<h2 style="%s">Debug Message</h2><pre style="%s">%s</pre>'
            . '<h2 style="%s">Stack Trace</h2><pre style="%s">%s</pre>'
            . '</body></html>',
            $bodyStyle,
            $headingStyle,
            $sectionStyle,
            $messageStyle,
            $message,
            $sectionStyle,
            $traceStyle,
            $trace,
        );

        return (new Response(['charset' => 'UTF-8']))
            ->withStatus(500)
            ->withHeader('Content-Type', 'text/html; charset=UTF-8')
            ->withStringBody($body);
    }
}
