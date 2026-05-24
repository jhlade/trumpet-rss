<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

foreach (glob(__DIR__ . '/test_*.php') as $file) {
    require $file;
}

$pass = 0;
$fail = 0;
$failures = [];

foreach ($GLOBALS['__tests'] as $t) {
    try {
        ($t['fn'])();
        echo "  ok   {$t['name']}\n";
        $pass++;
    } catch (\Throwable $e) {
        echo "  FAIL {$t['name']}\n";
        $fail++;
        $failures[] = ['name' => $t['name'], 'err' => $e];
    }
}

echo "\n";
echo str_repeat('-', 60) . "\n";
echo "Tests: " . ($pass + $fail) . " | Passed: $pass | Failed: $fail\n";

if ($fail > 0) {
    echo "\nFailures:\n";
    foreach ($failures as $f) {
        echo "  * {$f['name']}\n    " . get_class($f['err']) . ': ' . $f['err']->getMessage() . "\n";
    }
    exit(1);
}
exit(0);
