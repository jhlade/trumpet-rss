<?php
declare(strict_types=1);

namespace Trumpet;

final class Logger
{
    public const DEBUG = 0;
    public const INFO = 1;
    public const WARN = 2;
    public const ERROR = 3;

    private int $minLevel;

    public function __construct(int $minLevel = self::INFO)
    {
        $this->minLevel = $minLevel;
    }

    public function debug(string $msg): void { $this->log(self::DEBUG, $msg); }
    public function info(string $msg): void  { $this->log(self::INFO, $msg); }
    public function warn(string $msg): void  { $this->log(self::WARN, $msg); }
    public function error(string $msg): void { $this->log(self::ERROR, $msg); }

    private function log(int $level, string $msg): void
    {
        if ($level < $this->minLevel) return;
        $tag = ['DEBUG', 'INFO', 'WARN', 'ERROR'][$level];
        $ts = date('Y-m-d H:i:s');
        fwrite(STDERR, "[$ts] $tag $msg\n");
    }
}
