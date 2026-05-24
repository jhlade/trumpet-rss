<?php
declare(strict_types=1);

use Trumpet\Api\Handlers;
use Trumpet\Api\Request;
use Trumpet\Api\Response;
use Trumpet\Api\Router;
use Trumpet\Auth;
use Trumpet\Config;
use Trumpet\FeedFetcher;
use Trumpet\Logger;
use Trumpet\MastodonClient;
use Trumpet\State;

spl_autoload_register(function (string $class): void {
    if (!str_starts_with($class, 'Trumpet\\')) return;
    $rel = substr($class, strlen('Trumpet\\'));
    $path = __DIR__ . '/../src/' . str_replace('\\', '/', $rel) . '.php';
    if (is_file($path)) require $path;
});

// When invoked via `php -S ... index.php` for an actual asset, return false to let the server handle it.
$req = Request::fromGlobals();
$root = dirname(__DIR__);
$configPath = getenv('TRUMPET_CONFIG_PATH') ?: $root . '/config/config.yaml';
$dbPath = getenv('TRUMPET_DB_PATH') ?: $root . '/storage/trumpet.sqlite';
$logLevelName = strtolower((string)(getenv('TRUMPET_LOG_LEVEL') ?: 'info'));
$logLevel = match ($logLevelName) {
    'debug' => Logger::DEBUG,
    'warn', 'warning' => Logger::WARN,
    'error' => Logger::ERROR,
    default => Logger::INFO,
};

$log = new Logger($logLevel);

try {
    $config = Config::load($configPath);
    $state = new State($dbPath);
    $auth = Auth::fromEnv($log);
    $fetcher = new FeedFetcher($log);
    $mastodon = new MastodonClient($log);
    $handlers = new Handlers($config, $state, $auth, $fetcher, $mastodon, $log);
} catch (\Throwable $e) {
    Response::error(500, 'startup_failed', $e->getMessage())->send();
    return;
}

$router = new Router($handlers, $auth, $log);
$router->add('GET',  '/api/v1/health',                'health',        auth: false);
$router->add('POST', '/api/v1/auth/login',            'login',         auth: false);
$router->add('GET',  '/api/v1/config',                'getConfig');
$router->add('GET',  '/api/v1/rules',                 'listRules');
$router->add('GET',  '/api/v1/rules/{name}',          'getRule');
$router->add('POST', '/api/v1/rules/{name}/run',      'runRule');
$router->add('POST', '/api/v1/rules/{name}/test',     'testRule');
$router->add('POST', '/api/v1/rules/{name}/bootstrap','bootstrapRule');
$router->add('POST', '/api/v1/run',                   'runAll');
$router->add('POST', '/api/v1/prune',                 'prune');
$router->add('GET',  '/api/v1/state/seen',            'seen');

$router->dispatch($req)->send();
