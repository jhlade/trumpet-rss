<?php
declare(strict_types=1);

use Trumpet\StatusFormatter;

function _item(array $over = []): array
{
    return array_merge([
        'guid' => 'g1',
        'title' => 'Hello world',
        'link' => 'https://example.com/a',
        'summary' => 'A nice article',
        'pub_date' => '2026-05-25T10:00:00+00:00',
        'timestamp' => 0,
    ], $over);
}

test('formatter: basic title+link', function () {
    assert_eq("Hello world\nhttps://example.com/a",
        StatusFormatter::render("{title}\n{link}", _item()));
});

test('formatter: all placeholders', function () {
    $out = StatusFormatter::render("{title} | {link} | {summary} | {pub_date} | {guid}", _item());
    assert_eq("Hello world | https://example.com/a | A nice article | 2026-05-25T10:00:00+00:00 | g1", $out);
});

test('formatter: leaves unknown placeholders untouched', function () {
    assert_eq("Hello world {unknown}",
        StatusFormatter::render("{title} {unknown}", _item()));
});

test('formatter: missing pub_date becomes empty', function () {
    $out = StatusFormatter::render("[{pub_date}]{title}", _item(['pub_date' => null]));
    assert_eq("[]Hello world", $out);
});

test('formatter: truncates over 500 chars with ellipsis', function () {
    $long = str_repeat('x', 600);
    $out = StatusFormatter::render('{title}', _item(['title' => $long]));
    $codepoints = preg_match_all('//u', $out) - 1;
    assert_eq(500, $codepoints, 'output should be exactly 500 code points');
    assert_true(str_ends_with($out, '…'), 'should end with ellipsis');
});

test('formatter: keeps short input verbatim', function () {
    $out = StatusFormatter::render('{title}', _item(['title' => 'short']));
    assert_eq('short', $out);
});

test('formatter: multibyte truncation counts code points not bytes', function () {
    $title = str_repeat('ě', 600);
    $out = StatusFormatter::render('{title}', _item(['title' => $title]));
    $codepoints = preg_match_all('//u', $out) - 1;
    assert_eq(500, $codepoints);
});
