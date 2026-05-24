<?php
declare(strict_types=1);

namespace Trumpet\Api;

use Trumpet\Auth;
use Trumpet\Logger;

final class Router
{
    /** @var list<array{method:string, regex:string, params:list<string>, handler:string, auth:bool}> */
    private array $routes = [];

    public function __construct(
        private object $handlers,
        private Auth $auth,
        private Logger $log,
    ) {}

    public function add(string $method, string $pattern, string $handler, bool $auth = true): void
    {
        $params = [];
        $regex = preg_replace_callback('/\{([a-zA-Z_][a-zA-Z0-9_]*)\}/', function ($m) use (&$params) {
            $params[] = $m[1];
            return '([^/]+)';
        }, $pattern);
        $this->routes[] = [
            'method' => strtoupper($method),
            'regex' => '#^' . $regex . '$#',
            'params' => $params,
            'handler' => $handler,
            'auth' => $auth,
        ];
    }

    public function dispatch(Request $req): Response
    {
        $methodAllowed = false;
        foreach ($this->routes as $route) {
            if (!preg_match($route['regex'], $req->path, $m)) continue;
            if ($route['method'] !== $req->method) {
                $methodAllowed = true;
                continue;
            }
            if ($route['auth']) {
                $user = $this->auth->authenticate($req->header('authorization'));
                if ($user === null) {
                    return Response::error(401, 'unauthorized', 'Missing or invalid token');
                }
            }
            $params = [];
            foreach ($route['params'] as $i => $name) {
                $params[$name] = $m[$i + 1];
            }
            try {
                return $this->handlers->{$route['handler']}($req, $params);
            } catch (\Throwable $e) {
                $this->log->error("Handler {$route['handler']} threw: " . $e->getMessage());
                return Response::error(500, 'internal_error', $e->getMessage());
            }
        }
        if ($methodAllowed) {
            return Response::error(405, 'method_not_allowed', "Method {$req->method} not allowed for {$req->path}");
        }
        return Response::error(404, 'not_found', "No route for {$req->path}");
    }
}
