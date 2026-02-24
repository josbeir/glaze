<?php
declare(strict_types=1);

use Cake\Http\ResponseEmitter;
use Cake\Http\MiddlewareQueue;
use Cake\Http\Runner;
use Cake\Http\ServerRequestFactory;
use Glaze\Config\BuildConfig;
use Glaze\Http\DevPageRequestHandler;
use Glaze\Http\Middleware\ContentAssetMiddleware;
use Glaze\Http\Middleware\ErrorHandlingMiddleware;
use Glaze\Http\Middleware\PublicAssetMiddleware;

require dirname(__DIR__) . '/vendor/autoload.php';

$projectRoot = getenv('GLAZE_PROJECT_ROOT');
if (!is_string($projectRoot) || $projectRoot === '') {
    $projectRoot = dirname(__DIR__);
}

$includeDrafts = getenv('GLAZE_INCLUDE_DRAFTS') === '1';
$config = BuildConfig::fromProjectRoot($projectRoot, $includeDrafts);

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$requestPath = parse_url($requestUri, PHP_URL_PATH);
$requestPath = is_string($requestPath) && $requestPath !== '' ? $requestPath : '/';
$request = (new ServerRequestFactory())->createServerRequest($requestMethod, $requestPath, $_SERVER);

$query = parse_url($requestUri, PHP_URL_QUERY);
if (is_string($query) && $query !== '') {
    parse_str($query, $queryParams);
    $request = $request->withQueryParams($queryParams);
}

$queue = new MiddlewareQueue();
$queue->add(new ErrorHandlingMiddleware(true));
$queue->add(new PublicAssetMiddleware($config));
$queue->add(new ContentAssetMiddleware($config));

$response = (new Runner())->run($queue, $request, new DevPageRequestHandler($config));

(new ResponseEmitter())->emit($response);

return true;
