<?php
declare(strict_types=1);

namespace Trumpet;

final class Auth
{
    /** @var array<string,string>  username => bcrypt hash */
    private array $users;

    private string $jwtSecret;
    private int $ttl;
    private ?Ldap $ldap;

    public function __construct(string $usersSpec, string $jwtSecret, int $ttl = 28800, ?Ldap $ldap = null)
    {
        $this->users = self::parseUsers($usersSpec);
        $this->jwtSecret = $jwtSecret;
        $this->ttl = $ttl;
        $this->ldap = $ldap;
    }

    public static function fromEnv(?Logger $log = null): self
    {
        $users = (string)(getenv('TRUMPET_USERS') ?: '');
        $secret = (string)(getenv('TRUMPET_JWT_SECRET') ?: '');
        $ttl = (int)(getenv('TRUMPET_JWT_TTL') ?: 28800);
        if ($secret === '') {
            throw new \RuntimeException('TRUMPET_JWT_SECRET is not set');
        }
        $ldap = Ldap::fromEnv($log);
        if ($users === '' && $ldap === null) {
            throw new \RuntimeException('Neither TRUMPET_USERS nor TRUMPET_LDAP_URL is set — no auth source configured');
        }
        return new self($users, $secret, $ttl, $ldap);
    }

    /**
     * @return array<string,string>
     */
    public static function parseUsers(string $spec): array
    {
        $out = [];
        $spec = trim($spec);
        if ($spec === '') return $out;
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
     * Try local TRUMPET_USERS first (emergency admin), then LDAP if configured.
     * @return array{token:string, expires_at:int}|null
     */
    public function login(string $username, string $password, ?int $now = null): ?array
    {
        $now ??= time();
        if ($this->localLogin($username, $password)) {
            return $this->issueToken($username, $now);
        }
        if ($this->ldap !== null && $this->ldap->authenticate($username, $password) !== null) {
            return $this->issueToken($username, $now);
        }
        return null;
    }

    private function localLogin(string $username, string $password): bool
    {
        // Always run password_verify to avoid user-enumeration timing differences.
        $hash = $this->users[$username] ?? '$2y$10$invalidinvalidinvalidinvalidinvalidinvalidinvalidinvalidi';
        return password_verify($password, $hash) && isset($this->users[$username]);
    }

    private function issueToken(string $username, int $now): array
    {
        $exp = $now + $this->ttl;
        $token = Jwt::sign(['sub' => $username, 'iat' => $now, 'exp' => $exp], $this->jwtSecret);
        return ['token' => $token, 'expires_at' => $exp];
    }

    /**
     * Validate a Bearer token and return the subject (username), or null.
     * Note: when LDAP is enabled, we cannot re-verify the LDAP user on every
     * request without a round-trip — so a non-expired, well-signed token is
     * accepted regardless of whether the subject is in TRUMPET_USERS.
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
        if (!is_string($sub) || $sub === '') return null;
        // If only local auth is configured, require the user to still exist.
        if ($this->ldap === null && !isset($this->users[$sub])) return null;
        return $sub;
    }
}
