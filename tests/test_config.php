<?php
declare(strict_types=1);

use Trumpet\Config;

function _writeYaml(string $body): string
{
    $path = tempnam(sys_get_temp_dir(), 'trumpet-cfg-') . '.yaml';
    file_put_contents($path, $body);
    register_shutdown_function(fn() => @unlink($path));
    return $path;
}

function _minimalConfig(string $extra = ''): string
{
    return <<<YAML
accounts:
  - name: a
    instance: https://mastodon.social
    token_env: TRUMPET_TOKEN_A
sources:
  - name: s
    url: https://example.com/f.xml
rules:
  - name: r
    account: a
    sources: [s]
    interval: 1h
    max_per_run: 1
    visibility: public
    template: "{title}"
$extra
YAML;
}

test('config: loads minimal valid config', function () {
    $path = _writeYaml(_minimalConfig());
    $cfg = Config::load($path);
    assert_eq(1, count($cfg->accounts));
    assert_eq(1, count($cfg->sources));
    assert_eq(1, count($cfg->rules));
    assert_eq('a', $cfg->rules[0]['account']);
    assert_eq(3600, $cfg->rules[0]['interval_seconds']);
    assert_eq(true, $cfg->rules[0]['enabled'], 'enabled defaults to true');
});

test('config: trailing slash stripped from instance', function () {
    $body = str_replace('https://mastodon.social', 'https://mastodon.social/', _minimalConfig());
    $cfg = Config::load(_writeYaml($body));
    assert_eq('https://mastodon.social', $cfg->accounts['a']['instance']);
});

test('config: token resolved from env', function () {
    $path = _writeYaml(_minimalConfig());
    putenv('TRUMPET_TOKEN_A=secret123');
    $cfg = Config::load($path);
    assert_eq('secret123', $cfg->tokenFor('a'));
    putenv('TRUMPET_TOKEN_A');
});

test('config: missing token returns null', function () {
    $path = _writeYaml(_minimalConfig());
    putenv('TRUMPET_TOKEN_A');
    $cfg = Config::load($path);
    assert_eq(null, $cfg->tokenFor('a'));
});

test('config: unknown account in rule rejected', function () {
    $body = str_replace('account: a', 'account: ghost', _minimalConfig());
    assert_throws(fn() => Config::load(_writeYaml($body)), 'unknown account');
});

test('config: unknown source in rule rejected', function () {
    $body = str_replace('sources: [s]', 'sources: [ghost]', _minimalConfig());
    assert_throws(fn() => Config::load(_writeYaml($body)), 'unknown source');
});

test('config: invalid visibility rejected', function () {
    $body = str_replace('visibility: public', 'visibility: secret', _minimalConfig());
    assert_throws(fn() => Config::load(_writeYaml($body)), 'visibility');
});

test('config: invalid interval rejected', function () {
    $body = str_replace('interval: 1h', 'interval: garbage', _minimalConfig());
    assert_throws(fn() => Config::load(_writeYaml($body)), 'Invalid interval');
});

test('config: max_per_run < 1 rejected', function () {
    $body = str_replace('max_per_run: 1', 'max_per_run: 0', _minimalConfig());
    assert_throws(fn() => Config::load(_writeYaml($body)), 'max_per_run');
});

test('config: duplicate account name rejected', function () {
    $body = <<<YAML
accounts:
  - name: a
    instance: https://x
    token_env: T1
  - name: a
    instance: https://y
    token_env: T2
sources: []
rules: []
YAML;
    assert_throws(fn() => Config::load(_writeYaml($body)), 'Duplicate account');
});

test('config: duplicate rule name rejected', function () {
    $body = _minimalConfig() . "\n  - name: r\n    account: a\n    sources: [s]\n    interval: 1h\n    max_per_run: 1\n    visibility: public\n    template: x\n";
    assert_throws(fn() => Config::load(_writeYaml($body)), 'Duplicate rule');
});

test('config: rule language falls back to account default', function () {
    $body = "accounts:\n  - name: a\n    instance: https://x\n    token_env: T\n    default_language: en\nsources:\n  - {name: s, url: https://e/f}\nrules:\n  - name: r\n    account: a\n    sources: [s]\n    interval: 1h\n    max_per_run: 1\n    visibility: public\n    template: x\n";
    $cfg = Config::load(_writeYaml($body));
    assert_eq('en', $cfg->rules[0]['language']);
});

test('config: rule language overrides account default', function () {
    $body = "accounts:\n  - name: a\n    instance: https://x\n    token_env: T\n    default_language: en\nsources:\n  - {name: s, url: https://e/f}\nrules:\n  - name: r\n    account: a\n    sources: [s]\n    interval: 1h\n    max_per_run: 1\n    visibility: public\n    language: fr\n    template: x\n";
    $cfg = Config::load(_writeYaml($body));
    assert_eq('fr', $cfg->rules[0]['language']);
});

test('config: enabled false honored', function () {
    $body = _minimalConfig('    enabled: false');
    $cfg = Config::load(_writeYaml($body));
    assert_eq(false, $cfg->rules[0]['enabled']);
});

test('config: missing required key rejected', function () {
    $body = "accounts:\n  - name: a\n    instance: https://x\nsources: []\nrules: []\n";
    assert_throws(fn() => Config::load(_writeYaml($body)), 'token_env');
});

test('config: example config loads cleanly', function () {
    $cfg = Config::load(__DIR__ . '/../config/config.example.yaml');
    assert_eq(2, count($cfg->accounts));
    assert_eq(2, count($cfg->sources));
    assert_eq(2, count($cfg->rules));
});
