<?php
declare(strict_types=1);

use Trumpet\Yaml;

test('yaml: empty input', function () {
    assert_eq([], Yaml::parse(''));
});

test('yaml: simple mapping', function () {
    assert_eq(['a' => 1, 'b' => 'two', 'c' => true],
        Yaml::parse("a: 1\nb: two\nc: true\n"));
});

test('yaml: comments ignored', function () {
    assert_eq(['a' => 1],
        Yaml::parse("# header\na: 1   # trailing\n# tail\n"));
});

test('yaml: hash inside quoted string is not a comment', function () {
    assert_eq(['t' => 'foo # bar'],
        Yaml::parse('t: "foo # bar"'));
});

test('yaml: hash without preceding space is not a comment', function () {
    assert_eq(['t' => 'foo#bar'],
        Yaml::parse('t: foo#bar'));
});

test('yaml: nested mapping by indentation', function () {
    $r = Yaml::parse("outer:\n  a: 1\n  b: 2\n");
    assert_eq(['outer' => ['a' => 1, 'b' => 2]], $r);
});

test('yaml: block sequence of scalars', function () {
    $r = Yaml::parse("items:\n  - a\n  - b\n  - c\n");
    assert_eq(['items' => ['a', 'b', 'c']], $r);
});

test('yaml: block sequence of mappings', function () {
    $r = Yaml::parse("items:\n  - name: x\n    val: 1\n  - name: y\n    val: 2\n");
    assert_eq(['items' => [['name' => 'x', 'val' => 1], ['name' => 'y', 'val' => 2]]], $r);
});

test('yaml: inline flow sequence', function () {
    assert_eq(['xs' => ['a', 'b', 'c']],
        Yaml::parse("xs: [a, b, c]"));
});

test('yaml: inline flow sequence with mixed types', function () {
    assert_eq(['xs' => [1, 'two', true, null]],
        Yaml::parse("xs: [1, two, true, null]"));
});

test('yaml: double-quoted string with \\n escape', function () {
    assert_eq(['t' => "line1\nline2"],
        Yaml::parse('t: "line1\nline2"'));
});

test('yaml: single-quoted string preserves backslash', function () {
    assert_eq(['t' => 'line1\\nline2'],
        Yaml::parse("t: 'line1\\nline2'"));
});

test('yaml: single quote escape via doubling', function () {
    assert_eq(['t' => "it's"],
        Yaml::parse("t: 'it''s'"));
});

test('yaml: integer scalar', function () {
    assert_eq(['n' => 42, 'm' => -7],
        Yaml::parse("n: 42\nm: -7"));
});

test('yaml: boolean variants', function () {
    assert_eq(['a' => true, 'b' => false, 'c' => true, 'd' => false],
        Yaml::parse("a: true\nb: false\nc: yes\nd: no"));
});

test('yaml: null variants', function () {
    assert_eq(['a' => null, 'b' => null, 'c' => null],
        Yaml::parse("a: null\nb: ~\nc:"));
});

test('yaml: full trumpet-style config', function () {
    $src = <<<YAML
accounts:
  - name: myaccount
    instance: https://mastodon.social
    token_env: TRUMPET_TOKEN_MYACCOUNT
    default_language: en
sources:
  - name: news
    url: https://example.com/feed.xml
rules:
  - name: news-to-myaccount
    account: myaccount
    sources: [news]
    interval: 20m
    max_per_run: 3
    visibility: unlisted
    language: en
    template: "{title}\\n{link}"
    enabled: true
YAML;
    $r = Yaml::parse($src);
    assert_eq('myaccount', $r['accounts'][0]['name']);
    assert_eq('https://example.com/feed.xml', $r['sources'][0]['url']);
    assert_eq(['news'], $r['rules'][0]['sources']);
    assert_eq("{title}\n{link}", $r['rules'][0]['template']);
    assert_eq(true, $r['rules'][0]['enabled']);
    assert_eq(3, $r['rules'][0]['max_per_run']);
});

test('yaml: tab indentation rejected', function () {
    assert_throws(fn() => Yaml::parse("a:\n\tb: 1"), 'Tabs');
});

test('yaml: missing colon rejected', function () {
    assert_throws(fn() => Yaml::parse("just a line"), 'Expected');
});
