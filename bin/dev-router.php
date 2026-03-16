<?php
declare(strict_types=1);

use Cake\Core\Configure;
use Cake\Http\MiddlewareQueue;
use Cake\Http\ResponseEmitter;
use Cake\Http\Runner;
use Cake\Http\ServerRequestFactory;
use Glaze\Application;
use Glaze\Config\ProjectConfigurationReader;

require dirname(__DIR__) . '/vendor/autoload.php';

error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
ini_set('log_errors', '1');
ini_set('error_log', 'php://stderr');

$projectRoot = getenv('GLAZE_PROJECT_ROOT');
if (!is_string($projectRoot) || $projectRoot === '') {
    $projectRoot = dirname(__DIR__);
}

$includeDrafts = getenv('GLAZE_INCLUDE_DRAFTS') === '1';
$staticMode = getenv('GLAZE_STATIC_MODE') === '1';

$application = new Application();
$application->bootstrap();

(new ProjectConfigurationReader())->read($projectRoot);
Configure::write('projectRoot', $projectRoot);
if ($includeDrafts) {
    Configure::write('build.drafts', true);
}

$fallbackHandler = $application->fallbackHandler($staticMode);

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

$queue = $application->middleware(new MiddlewareQueue(), $staticMode);

$response = (new Runner())->run($queue, $request, $fallbackHandler);

(new ResponseEmitter())->emit($response);

return true;
