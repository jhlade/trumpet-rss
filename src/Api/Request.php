<?php
declare(strict_types=1);

namespace Trumpet\Api;

final class Request
{
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $headers,
        public readonly ?string $rawBody,
    ) {}

    public static function fromGlobals(): self
    {
        $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $uri = (string)($_SERVER['REQUEST_URI'] ?? '/');
        $parts = explode('?', $uri, 2);
        $path = $parts[0];
        $headers = self::collectHeaders();
        $body = file_get_contents('php://input');
        return new self($method, $path, $_GET, $headers, $body === false ? null : $body);
    }

    public function header(string $name): ?string
    {
        $name = strtolower($name);
        return $this->headers[$name] ?? null;
    }

    public function json(): mixed
    {
        if ($this->rawBody === null || $this->rawBody === '') return null;
        $decoded = json_decode($this->rawBody, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException('Invalid JSON body: ' . json_last_error_msg());
        }
        return $decoded;
    }

    /** @return array<string,string> lowercase header name => value */
    private static function collectHeaders(): array
    {
        $out = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($k, 5)));
                $out[$name] = (string)$v;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE']))   $out['content-type']   = (string)$_SERVER['CONTENT_TYPE'];
        if (isset($_SERVER['CONTENT_LENGTH'])) $out['content-length'] = (string)$_SERVER['CONTENT_LENGTH'];
        return $out;
    }
}
