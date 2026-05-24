<?php
declare(strict_types=1);

use Trumpet\Jwt;

test('jwt: sign + verify roundtrip', function () {
    $token = Jwt::sign(['sub' => 'admin', 'exp' => time() + 60], 'secret');
    $claims = Jwt::verify($token, 'secret');
    assert_eq('admin', $claims['sub']);
});

test('jwt: bad signature rejected', function () {
    $token = Jwt::sign(['sub' => 'a', 'exp' => time() + 60], 'right');
    assert_throws(fn() => Jwt::verify($token, 'wrong'), 'signature');
});

test('jwt: expired token rejected', function () {
    $past = time() - 10;
    $token = Jwt::sign(['sub' => 'a', 'exp' => $past], 'secret');
    assert_throws(fn() => Jwt::verify($token, 'secret'), 'expired');
});

test('jwt: nbf in the future rejected', function () {
    $future = time() + 60;
    $token = Jwt::sign(['sub' => 'a', 'nbf' => $future, 'exp' => $future + 60], 'secret');
    assert_throws(fn() => Jwt::verify($token, 'secret'), 'not yet');
});

test('jwt: malformed token rejected', function () {
    assert_throws(fn() => Jwt::verify('not.a.token.extra', 'secret'), 'Malformed');
    assert_throws(fn() => Jwt::verify('only-one-part', 'secret'), 'Malformed');
});

test('jwt: tampered payload rejected', function () {
    $token = Jwt::sign(['sub' => 'admin', 'exp' => time() + 60], 'secret');
    [$h, $p, $s] = explode('.', $token);
    $newPayload = rtrim(strtr(base64_encode(json_encode(['sub' => 'attacker', 'exp' => time() + 60])), '+/', '-_'), '=');
    assert_throws(fn() => Jwt::verify("$h.$newPayload.$s", 'secret'), 'signature');
});

test('jwt: alg none not accepted', function () {
    $header = rtrim(strtr(base64_encode(json_encode(['alg' => 'none', 'typ' => 'JWT'])), '+/', '-_'), '=');
    $payload = rtrim(strtr(base64_encode(json_encode(['sub' => 'attacker', 'exp' => time() + 60])), '+/', '-_'), '=');
    assert_throws(fn() => Jwt::verify("$header.$payload.", 'secret'), 'algorithm');
});

test('jwt: missing exp accepted (no expiry check)', function () {
    $token = Jwt::sign(['sub' => 'a'], 'secret');
    $claims = Jwt::verify($token, 'secret');
    assert_eq('a', $claims['sub']);
});
