<?php
declare(strict_types=1);

namespace Trumpet\Api;

final class Response
{
    public function __construct(
        public readonly int $status,
        public readonly mixed $body,
        public readonly array $headers = [],
    ) {}

    public static function json(int $status, mixed $body, array $headers = []): self
    {
        return new self($status, $body, array_merge(['Content-Type' => 'application/json'], $headers));
    }

    public static function error(int $status, string $code, string $message): self
    {
        return self::json($status, ['error' => ['code' => $code, 'message' => $message]]);
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $k => $v) {
                header("$k: $v");
            }
        }
        if ($this->body === null) return;
        echo json_encode($this->body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    }
}
