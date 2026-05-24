<?php
declare(strict_types=1);

use Trumpet\Api\Request;
use Trumpet\Api\Response;
use Trumpet\Api\Router;
use Trumpet\Auth;
use Trumpet\Logger;

function _req(string $method, string $path, ?string $auth = null): Request
{
    return new Request($method, $path, [], $auth !== null ? ['authorization' => $auth] : [], null);
}

// Stub Handlers that records calls and returns canned responses.
$stubFile = sys_get_temp_dir() . '/trumpet_stub_handlers_' . getmypid() . '.php';
file_put_contents($stubFile, <<<'PHP'
<?php
namespace Trumpet\Api;
class StubHandlers {
    public array $calls = [];
    public function ping(Request $req, array $params): Response {
        $this->calls[] = ['ping', $params];
        return Response::json(200, ['pong' => true, 'params' => $params]);
    }
    public function secret(Request $req, array $params): Response {
        $this->calls[] = ['secret', $params];
        return Response::json(200, ['ok' => true]);
    }
    public function explodes(Request $req, array $params): Response {
        throw new \RuntimeException('boom');
    }
}
PHP);
require $stubFile;
register_shutdown_function(fn() => @unlink($stubFile));

function _makeRouter(): array
{
    $hash = password_hash('pw', PASSWORD_BCRYPT);
    $auth = new Auth("admin:$hash", 'jwt-secret');
    $stub = new \Trumpet\Api\StubHandlers();
    $router = new Router($stub, $auth, new Logger(Logger::ERROR));
    $router->add('GET',  '/ping', 'ping', auth: false);
    $router->add('POST', '/things/{id}', 'ping', auth: false);
    $router->add('GET',  '/secret', 'secret', auth: true);
    $router->add('GET',  '/boom', 'explodes', auth: false);
    return [$router, $auth, $stub];
}

test('router: matches simple route', function () {
    [$router] = _makeRouter();
    $res = $router->dispatch(_req('GET', '/ping'));
    assert_eq(200, $res->status);
    assert_eq(['pong' => true, 'params' => []], $res->body);
});

test('router: extracts path params', function () {
    [$router, , $stub] = _makeRouter();
    $res = $router->dispatch(_req('POST', '/things/42'));
    assert_eq(200, $res->status);
    assert_eq('42', $stub->calls[0][1]['id']);
});

test('router: 404 for unknown path', function () {
    [$router] = _makeRouter();
    $res = $router->dispatch(_req('GET', '/nope'));
    assert_eq(404, $res->status);
});

test('router: 405 when path matches but method does not', function () {
    [$router] = _makeRouter();
    $res = $router->dispatch(_req('DELETE', '/ping'));
    assert_eq(405, $res->status);
});

test('router: 401 without auth header on protected route', function () {
    [$router] = _makeRouter();
    $res = $router->dispatch(_req('GET', '/secret'));
    assert_eq(401, $res->status);
});

test('router: 401 with bogus bearer', function () {
    [$router] = _makeRouter();
    $res = $router->dispatch(_req('GET', '/secret', 'Bearer garbage'));
    assert_eq(401, $res->status);
});

test('router: 200 with valid bearer', function () {
    [$router, $auth] = _makeRouter();
    $tok = $auth->login('admin', 'pw')['token'];
    $res = $router->dispatch(_req('GET', '/secret', "Bearer $tok"));
    assert_eq(200, $res->status);
});

test('router: handler exception → 500', function () {
    [$router] = _makeRouter();
    $res = $router->dispatch(_req('GET', '/boom'));
    assert_eq(500, $res->status);
    assert_eq('internal_error', $res->body['error']['code']);
});

test('request: parses JSON body', function () {
    $r = new Request('POST', '/x', [], ['content-type' => 'application/json'], '{"a":1,"b":"x"}');
    assert_eq(['a' => 1, 'b' => 'x'], $r->json());
});

test('request: rejects invalid JSON', function () {
    $r = new Request('POST', '/x', [], [], '{bad}');
    assert_throws(fn() => $r->json(), 'Invalid JSON');
});

test('request: header lookup is case-insensitive (already-lowered)', function () {
    $r = new Request('GET', '/x', [], ['authorization' => 'Bearer t'], null);
    assert_eq('Bearer t', $r->header('Authorization'));
});
