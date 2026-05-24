<?php
declare(strict_types=1);

namespace Trumpet;

final class IntervalParser
{
    public static function toSeconds(string $spec): int
    {
        $spec = trim($spec);
        if ($spec === '') {
            throw new \InvalidArgumentException("Empty interval");
        }
        if (!preg_match('/^(\d+)\s*([smhd]?)$/i', $spec, $m)) {
            throw new \InvalidArgumentException("Invalid interval: $spec (expected e.g. 30s, 20m, 2h, 1d)");
        }
        $n = (int)$m[1];
        $unit = strtolower($m[2] ?: 's');
        return match ($unit) {
            's' => $n,
            'm' => $n * 60,
            'h' => $n * 3600,
            'd' => $n * 86400,
        };
    }
}
