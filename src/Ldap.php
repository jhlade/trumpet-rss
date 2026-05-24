<?php
declare(strict_types=1);

namespace Trumpet;

/**
 * Thin wrapper over ext-ldap. Search-and-rebind flow:
 *   1. bind as service account
 *   2. search for user, fetch DN + memberOf
 *   3. rebind as user with their password (verifies creds)
 *   4. check memberOf against allowed group DNs
 */
class Ldap
{
    public function __construct(
        public readonly string $url,
        public readonly string $bindDn,
        public readonly string $bindPassword,
        public readonly string $baseDn,
        public readonly string $userFilter,
        /** @var list<string> */ public readonly array $adminGroups,
        public readonly bool $startTls = false,
        public readonly bool $tlsVerify = true,
        public readonly ?string $caFile = null,
        private readonly ?Logger $log = null,
    ) {}

    public static function fromEnv(?Logger $log = null): ?self
    {
        $url = (string)(getenv('TRUMPET_LDAP_URL') ?: '');
        if ($url === '') return null;

        $bindDn = (string)(getenv('TRUMPET_LDAP_BIND_DN') ?: '');
        $bindPw = (string)(getenv('TRUMPET_LDAP_BIND_PASSWORD') ?: '');
        $baseDn = (string)(getenv('TRUMPET_LDAP_BASE_DN') ?: '');
        $filter = (string)(getenv('TRUMPET_LDAP_USER_FILTER') ?: '(sAMAccountName={user})');
        $groupsSpec = (string)(getenv('TRUMPET_LDAP_ADMIN_GROUPS') ?: '');
        $groups = self::parseGroupList($groupsSpec);
        $startTls = (string)(getenv('TRUMPET_LDAP_STARTTLS') ?: '0') === '1';
        $tlsVerify = (string)(getenv('TRUMPET_LDAP_TLS_VERIFY') ?: '1') !== '0';
        $caFile = (string)(getenv('TRUMPET_LDAP_CA_FILE') ?: '') ?: null;

        foreach (['bindDn' => $bindDn, 'baseDn' => $baseDn] as $name => $val) {
            if ($val === '') {
                throw new \RuntimeException("LDAP misconfigured: TRUMPET_LDAP_" . strtoupper($name) . " is empty");
            }
        }

        return new self($url, $bindDn, $bindPw, $baseDn, $filter, $groups, $startTls, $tlsVerify, $caFile, $log);
    }

    /**
     * Authenticate a user and check group membership.
     * @return string|null  user DN on success, null otherwise.
     */
    public function authenticate(string $username, string $password): ?string
    {
        if ($password === '') {
            $this->warn('empty password');
            return null;
        }
        if (!function_exists('ldap_connect')) {
            $this->warn('ext-ldap not loaded');
            return null;
        }

        $conn = $this->connect();
        if ($conn === null) return null;

        try {
            // Service bind
            if (!@ldap_bind($conn, $this->bindDn, $this->bindPassword)) {
                $this->warn('service bind failed: ' . ldap_error($conn));
                return null;
            }

            // Search for the user
            $escaped = ldap_escape($username, '', LDAP_ESCAPE_FILTER);
            $filter = str_replace('{user}', $escaped, $this->userFilter);
            $search = @ldap_search($conn, $this->baseDn, $filter, ['dn', 'memberOf']);
            if ($search === false) {
                $this->warn("search failed for filter $filter: " . ldap_error($conn));
                return null;
            }
            $entries = ldap_get_entries($conn, $search);
            if (!is_array($entries) || ($entries['count'] ?? 0) < 1) {
                $this->info("user '$username' not found in LDAP");
                return null;
            }
            if ((int)$entries['count'] > 1) {
                $this->warn("user '$username' matched " . $entries['count'] . " entries, refusing ambiguous login");
                return null;
            }
            $userDn = (string)$entries[0]['dn'];
            $memberOf = self::extractMemberOf($entries[0]);

            // Rebind as the user — proves the password
            if (!@ldap_bind($conn, $userDn, $password)) {
                $this->info("user '$username' bind failed (wrong password or disabled)");
                return null;
            }

            if (!self::userInAllowedGroups($memberOf, $this->adminGroups)) {
                $this->warn("user '$username' not in any admin group");
                return null;
            }
            return $userDn;
        } finally {
            @ldap_unbind($conn);
        }
    }

    /** @return \LDAP\Connection|null */
    private function connect()
    {
        $conn = @ldap_connect($this->url);
        if ($conn === false) {
            $this->warn("ldap_connect failed for $this->url");
            return null;
        }
        ldap_set_option($conn, LDAP_OPT_PROTOCOL_VERSION, 3);
        ldap_set_option($conn, LDAP_OPT_REFERRALS, 0);
        ldap_set_option($conn, LDAP_OPT_NETWORK_TIMEOUT, 5);
        $verify = $this->tlsVerify ? LDAP_OPT_X_TLS_DEMAND : LDAP_OPT_X_TLS_NEVER;
        ldap_set_option($conn, LDAP_OPT_X_TLS_REQUIRE_CERT, $verify);
        if ($this->caFile !== null && $this->caFile !== '') {
            ldap_set_option($conn, LDAP_OPT_X_TLS_CACERTFILE, $this->caFile);
        }
        if ($this->startTls) {
            if (!@ldap_start_tls($conn)) {
                $this->warn('ldap_start_tls failed: ' . ldap_error($conn));
                return null;
            }
        }
        return $conn;
    }

    /**
     * @param list<string> $memberOf  values as returned by LDAP
     * @param list<string> $allowed   configured allowed group DNs
     */
    public static function userInAllowedGroups(array $memberOf, array $allowed): bool
    {
        if (!$allowed) return false;
        $allowedNorm = array_map([self::class, 'normalizeDn'], $allowed);
        foreach ($memberOf as $g) {
            if (in_array(self::normalizeDn($g), $allowedNorm, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Normalize a DN for comparison: lowercase, strip whitespace around commas.
     * Not a fully RFC4514-compliant canonicalization — enough for typical AD DNs.
     */
    public static function normalizeDn(string $dn): string
    {
        $parts = preg_split('/\s*,\s*/', trim($dn)) ?: [];
        return strtolower(implode(',', $parts));
    }

    /**
     * Parse the admin-groups env var. DNs themselves contain commas, so the
     * separator is `;`. If no `;` is present and the value contains an `=`
     * (a DN attribute), the whole string is treated as a single DN.
     * @return list<string>
     */
    public static function parseGroupList(string $spec): array
    {
        $spec = trim($spec);
        if ($spec === '') return [];
        $parts = str_contains($spec, ';')
            ? explode(';', $spec)
            : (str_contains($spec, '=') ? [$spec] : explode(',', $spec));
        $out = [];
        foreach ($parts as $g) {
            $g = trim($g);
            if ($g !== '') $out[] = $g;
        }
        return $out;
    }

    /**
     * Extract memberOf attribute values from an ldap_get_entries row.
     * Handles both lowercase ('memberof') and missing attribute.
     * @return list<string>
     */
    public static function extractMemberOf(array $entry): array
    {
        $key = isset($entry['memberof']) ? 'memberof'
            : (isset($entry['memberOf']) ? 'memberOf' : null);
        if ($key === null) return [];
        $vals = $entry[$key];
        if (!is_array($vals)) return [];
        unset($vals['count']);
        return array_values(array_map('strval', $vals));
    }

    public static function tlsFromUrl(string $url): bool
    {
        return str_starts_with(strtolower(trim($url)), 'ldaps://');
    }

    private function info(string $msg): void { $this->log?->info("LDAP: $msg"); }
    private function warn(string $msg): void { $this->log?->warn("LDAP: $msg"); }
}
