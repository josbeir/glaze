<?php
declare(strict_types=1);

use Cake\Http\MiddlewareQueue;
use Cake\Http\ResponseEmitter;
use Cake\Http\Runner;
use Cake\Http\ServerRequestFactory;
use Glaze\Application;
use Glaze\Config\BuildConfig;
use Glaze\Config\BuildConfigFactory;
use Glaze\Http\DevPageRequestHandler;
use Glaze\Http\Middleware\ContentAssetMiddleware;
use Glaze\Http\Middleware\ErrorHandlingMiddleware;
use Glaze\Http\Middleware\PublicAssetMiddleware;
use Glaze\Http\Middleware\StaticAssetMiddleware;

require dirname(__DIR__) . '/vendor/autoload.php';

$projectRoot = getenv('GLAZE_PROJECT_ROOT');
if (!is_string($projectRoot) || $projectRoot === '') {
    $projectRoot = dirname(__DIR__);
}

$includeDrafts = getenv('GLAZE_INCLUDE_DRAFTS') === '1';

$application = new Application();
$container = $application->getContainer();

/** @var \Glaze\Config\BuildConfigFactory $buildConfigFactory */
$buildConfigFactory = $container->get(BuildConfigFactory::class);
$config = $buildConfigFactory->fromProjectRoot($projectRoot, $includeDrafts);

$container->addShared(BuildConfig::class, $config);
/** @var \Glaze\Http\Middleware\PublicAssetMiddleware $publicAssetMiddleware */
$publicAssetMiddleware = $container->get(PublicAssetMiddleware::class);
/** @var \Glaze\Http\Middleware\StaticAssetMiddleware $staticAssetMiddleware */
$staticAssetMiddleware = $container->get(StaticAssetMiddleware::class);
/** @var \Glaze\Http\Middleware\ContentAssetMiddleware $contentAssetMiddleware */
$contentAssetMiddleware = $container->get(ContentAssetMiddleware::class);
/** @var \Glaze\Http\DevPageRequestHandler $devPageRequestHandler */
$devPageRequestHandler = $container->get(DevPageRequestHandler::class);

$requestMethod = $_SERVER['REQUEST_METHOD'] ?? null;
if (!is_string($requestMethod) || $requestMethod === '') {
    $requestMethod = 'GET';
}

$requestUri = $_SERVER['REQUEST_URI'] ?? null;
if (!is_string($requestUri) || $requestUri === '') {
    $requestUri = '/';
}

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
$queue->add($publicAssetMiddleware);
$queue->add($staticAssetMiddleware);
$queue->add($contentAssetMiddleware);

$response = (new Runner())->run($queue, $request, $devPageRequestHandler);

(new ResponseEmitter())->emit($response);

return true;
