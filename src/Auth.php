<?php
declare(strict_types=1);

namespace Trumpet;

final class Auth
{
    /** @var array<string,string>  username => bcrypt hash */
    private array $users;

    private string $jwtSecret;
    private int $ttl;

    public function __construct(string $usersSpec, string $jwtSecret, int $ttl = 28800)
    {
        $this->users = self::parseUsers($usersSpec);
        $this->jwtSecret = $jwtSecret;
        $this->ttl = $ttl;
    }

    public static function fromEnv(): self
    {
        $users = (string)(getenv('TRUMPET_USERS') ?: '');
        $secret = (string)(getenv('TRUMPET_JWT_SECRET') ?: '');
        $ttl = (int)(getenv('TRUMPET_JWT_TTL') ?: 28800);
        if ($secret === '') {
            throw new \RuntimeException('TRUMPET_JWT_SECRET is not set');
        }
        if ($users === '') {
            throw new \RuntimeException('TRUMPET_USERS is not set');
        }
        return new self($users, $secret, $ttl);
    }

    /**
     * @return array<string,string>
     */
    public static function parseUsers(string $spec): array
    {
        $out = [];
        foreach (explode(',', $spec) as $pair) {
            $pair = trim($pair);
            if ($pair === '') continue;
            $sep = strpos($pair, ':');
            if ($sep === false) {
                throw new \RuntimeException("Invalid TRUMPET_USERS entry: missing ':'");
            }
            $user = trim(substr($pair, 0, $sep));
            $hash = substr($pair, $sep + 1);
            if ($user === '' || $hash === '') {
                throw new \RuntimeException("Invalid TRUMPET_USERS entry: empty username or hash");
            }
            $out[$user] = $hash;
        }
        return $out;
    }

    /**
     * Verify password and issue token. Returns null on bad credentials.
     * @return array{token:string, expires_at:int}|null
     */
    public function login(string $username, string $password, ?int $now = null): ?array
    {
        $now ??= time();
        // Always run password_verify to avoid user-enumeration timing differences.
        $hash = $this->users[$username] ?? '$2y$10$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalidi';
        $ok = password_verify($password, $hash) && isset($this->users[$username]);
        if (!$ok) {
            return null;
        }
        $exp = $now + $this->ttl;
        $token = Jwt::sign(['sub' => $username, 'iat' => $now, 'exp' => $exp], $this->jwtSecret);
        return ['token' => $token, 'expires_at' => $exp];
    }

    /**
     * Validate a Bearer token and return the subject (username), or null.
     */
    public function authenticate(?string $authHeader, ?int $now = null): ?string
    {
        if ($authHeader === null) return null;
        if (!preg_match('/^Bearer\s+(.+)$/i', $authHeader, $m)) return null;
        try {
            $claims = Jwt::verify(trim($m[1]), $this->jwtSecret, $now);
        } catch (\Throwable) {
            return null;
        }
        $sub = $claims['sub'] ?? null;
        return is_string($sub) && isset($this->users[$sub]) ? $sub : null;
    }
}
