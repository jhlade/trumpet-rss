<?php
declare(strict_types=1);

use Trumpet\Auth;

test('auth: parseUsers single', function () {
    $u = Auth::parseUsers('admin:$2y$10$hash');
    assert_eq(['admin' => '$2y$10$hash'], $u);
});

test('auth: parseUsers multiple', function () {
    $u = Auth::parseUsers('a:hash1,b:hash2');
    assert_eq('hash1', $u['a']);
    assert_eq('hash2', $u['b']);
});

test('auth: parseUsers trims whitespace', function () {
    $u = Auth::parseUsers('  a:h1  ,  b:h2  ');
    assert_eq(['a' => 'h1', 'b' => 'h2'], $u);
});

test('auth: parseUsers rejects entry without colon', function () {
    assert_throws(fn() => Auth::parseUsers('justname'), "missing ':'");
});

test('auth: parseUsers rejects empty username', function () {
    assert_throws(fn() => Auth::parseUsers(':hash'), 'empty');
});

test('auth: login with correct password issues token', function () {
    $hash = password_hash('s3cret', PASSWORD_BCRYPT);
    $auth = new Auth("admin:$hash", 'jwt-secret');
    $res = $auth->login('admin', 's3cret');
    assert_true($res !== null, 'login should succeed');
    assert_true(isset($res['token']), 'response has token');
    assert_true($res['expires_at'] > time(), 'expires_at in future');
});

test('auth: login with wrong password returns null', function () {
    $hash = password_hash('right', PASSWORD_BCRYPT);
    $auth = new Auth("admin:$hash", 'jwt-secret');
    assert_eq(null, $auth->login('admin', 'wrong'));
});

test('auth: login with unknown user returns null', function () {
    $hash = password_hash('pw', PASSWORD_BCRYPT);
    $auth = new Auth("admin:$hash", 'jwt-secret');
    assert_eq(null, $auth->login('ghost', 'pw'));
});

test('auth: authenticate with valid bearer succeeds', function () {
    $hash = password_hash('pw', PASSWORD_BCRYPT);
    $auth = new Auth("admin:$hash", 'jwt-secret');
    $res = $auth->login('admin', 'pw');
    assert_eq('admin', $auth->authenticate('Bearer ' . $res['token']));
});

test('auth: authenticate with bad token fails', function () {
    $auth = new Auth('admin:$2y$10$invalid', 'jwt-secret');
    assert_eq(null, $auth->authenticate('Bearer not.a.token'));
});

test('auth: authenticate without header fails', function () {
    $auth = new Auth('admin:$2y$10$invalid', 'jwt-secret');
    assert_eq(null, $auth->authenticate(null));
});

test('auth: authenticate rejects non-Bearer scheme', function () {
    $auth = new Auth('admin:$2y$10$invalid', 'jwt-secret');
    assert_eq(null, $auth->authenticate('Basic YWRtaW46YWRtaW4='));
});

test('auth: token for user removed from TRUMPET_USERS is rejected', function () {
    $hash = password_hash('pw', PASSWORD_BCRYPT);
    $authIssue = new Auth("admin:$hash", 'jwt-secret');
    $res = $authIssue->login('admin', 'pw');
    // New Auth instance no longer knows the user.
    $authVerify = new Auth("other:$hash", 'jwt-secret');
    assert_eq(null, $authVerify->authenticate('Bearer ' . $res['token']));
});

test('auth: local user match short-circuits LDAP (LDAP not consulted)', function () {
    $hash = password_hash('pw', PASSWORD_BCRYPT);
    // Stub Ldap that would explode if authenticate() got called.
    $ldap = new class extends \Trumpet\Ldap {
        public function __construct() {
            parent::__construct(
                url: 'ldap://nope', bindDn: 'cn=svc', bindPassword: '',
                baseDn: 'dc=x', userFilter: '(uid={user})', adminGroups: ['cn=g,dc=x']
            );
        }
        public function authenticate(string $u, string $p): ?string {
            throw new \RuntimeException('LDAP should not be consulted when local matched');
        }
    };
    $auth = new Auth("admin:$hash", 'jwt-secret', 28800, $ldap);
    $res = $auth->login('admin', 'pw');
    assert_true($res !== null);
});

test('auth: LDAP fallback when no local user matches', function () {
    $ldap = new class extends \Trumpet\Ldap {
        public function __construct() {
            parent::__construct(
                url: 'ldap://nope', bindDn: 'cn=svc', bindPassword: '',
                baseDn: 'dc=x', userFilter: '(uid={user})', adminGroups: ['cn=g,dc=x']
            );
        }
        public function authenticate(string $u, string $p): ?string {
            return $u === 'jdoe' && $p === 'adpw' ? 'CN=jdoe,DC=x' : null;
        }
    };
    $auth = new Auth('', 'jwt-secret', 28800, $ldap);
    $res = $auth->login('jdoe', 'adpw');
    assert_true($res !== null, 'LDAP user should log in');
    assert_eq(null, $auth->login('jdoe', 'wrong'));
});

test('auth: with LDAP, token for user not in TRUMPET_USERS is still accepted', function () {
    $ldap = new class extends \Trumpet\Ldap {
        public function __construct() {
            parent::__construct(
                url: 'ldap://x', bindDn: 'cn=svc', bindPassword: '',
                baseDn: 'dc=x', userFilter: '(uid={user})', adminGroups: ['cn=g,dc=x']
            );
        }
        public function authenticate(string $u, string $p): ?string {
            return $u === 'jdoe' ? 'CN=jdoe,DC=x' : null;
        }
    };
    $auth = new Auth('', 'jwt-secret', 28800, $ldap);
    $res = $auth->login('jdoe', 'whatever');
    assert_eq('jdoe', $auth->authenticate('Bearer ' . $res['token']));
});
