<?php
declare(strict_types=1);

use Cake\Http\ResponseEmitter;
use Cake\Http\MiddlewareQueue;
use Cake\Http\Runner;
use Cake\Http\ServerRequestFactory;
use Glaze\Config\BuildConfig;
use Glaze\Http\DevPageRequestHandler;
use Glaze\Http\Middleware\ContentAssetMiddleware;
use Glaze\Http\Middleware\PublicAssetMiddleware;

require dirname(__DIR__) . '/vendor/autoload.php';

$projectRoot = getenv('GLAZE_PROJECT_ROOT');
if (!is_string($projectRoot) || $projectRoot === '') {
    $projectRoot = dirname(__DIR__);
}

$includeDrafts = getenv('GLAZE_INCLUDE_DRAFTS') === '1';
$config = BuildConfig::fromProjectRoot($projectRoot, $includeDrafts);
$request = ServerRequestFactory::fromGlobals();
$queue = new MiddlewareQueue();
$queue->add(new PublicAssetMiddleware($config));
$queue->add(new ContentAssetMiddleware($config));

$response = (new Runner())->run($queue, $request, new DevPageRequestHandler($config));

(new ResponseEmitter())->emit($response);

return true;
