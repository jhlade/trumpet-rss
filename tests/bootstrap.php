<?php
declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    if (!str_starts_with($class, 'Trumpet\\')) return;
    $rel = substr($class, strlen('Trumpet\\'));
    $path = __DIR__ . '/../src/' . str_replace('\\', '/', $rel) . '.php';
    if (is_file($path)) require $path;
});

$GLOBALS['__tests'] = [];

function test(string $name, callable $fn): void
{
    $GLOBALS['__tests'][] = ['name' => $name, 'fn' => $fn];
}

function assert_eq(mixed $expected, mixed $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        $e = var_export($expected, true);
        $a = var_export($actual, true);
        throw new AssertionError(($msg !== '' ? "$msg: " : '') . "expected $e, got $a");
    }
}

function assert_equiv(mixed $expected, mixed $actual, string $msg = ''): void
{
    if ($expected != $actual) {
        $e = var_export($expected, true);
        $a = var_export($actual, true);
        throw new AssertionError(($msg !== '' ? "$msg: " : '') . "expected $e, got $a");
    }
}

function assert_true(bool $cond, string $msg = 'expected true'): void
{
    if (!$cond) throw new AssertionError($msg);
}

function assert_throws(callable $fn, ?string $msgContains = null): \Throwable
{
    try {
        $fn();
    } catch (\Throwable $e) {
        if ($msgContains !== null && !str_contains($e->getMessage(), $msgContains)) {
            throw new AssertionError("expected exception containing '$msgContains', got: " . $e->getMessage());
        }
        return $e;
    }
    throw new AssertionError("expected exception, none thrown");
}
