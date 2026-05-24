<?php
declare(strict_types=1);

use Trumpet\IntervalParser;

test('interval: seconds default', fn() => assert_eq(45, IntervalParser::toSeconds('45')));
test('interval: seconds explicit', fn() => assert_eq(30, IntervalParser::toSeconds('30s')));
test('interval: minutes', fn() => assert_eq(1200, IntervalParser::toSeconds('20m')));
test('interval: hours', fn() => assert_eq(7200, IntervalParser::toSeconds('2h')));
test('interval: days', fn() => assert_eq(86400, IntervalParser::toSeconds('1d')));
test('interval: trims whitespace', fn() => assert_eq(60, IntervalParser::toSeconds('  1m  ')));
test('interval: case insensitive unit', fn() => assert_eq(60, IntervalParser::toSeconds('1M')));
test('interval: rejects empty', fn() => assert_throws(fn() => IntervalParser::toSeconds(''), 'Empty'));
test('interval: rejects garbage', fn() => assert_throws(fn() => IntervalParser::toSeconds('abc'), 'Invalid'));
test('interval: rejects negative', fn() => assert_throws(fn() => IntervalParser::toSeconds('-5m'), 'Invalid'));
test('interval: rejects unknown unit', fn() => assert_throws(fn() => IntervalParser::toSeconds('5y'), 'Invalid'));
