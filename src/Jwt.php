<?php
declare(strict_types=1);

namespace Trumpet;

/**
 * Minimal HS256 JWT implementation. Only the alg we actually use.
 */
final class Jwt
{
    public static function sign(array $claims, string $secret): string
    {
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $h = self::b64uEncode(json_encode($header, JSON_UNESCAPED_SLASHES));
        $p = self::b64uEncode(json_encode($claims, JSON_UNESCAPED_SLASHES));
        $signing = "$h.$p";
        $sig = hash_hmac('sha256', $signing, $secret, true);
        return "$signing." . self::b64uEncode($sig);
    }

    /**
     * @return array  Decoded claims (associative).
     * @throws \RuntimeException on any verification failure.
     */
    public static function verify(string $token, string $secret, ?int $now = null): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new \RuntimeException('Malformed token');
        }
        [$h, $p, $s] = $parts;

        $header = json_decode((string)self::b64uDecode($h), true);
        if (!is_array($header) || ($header['alg'] ?? null) !== 'HS256') {
            throw new \RuntimeException('Unsupported algorithm');
        }

        $expected = hash_hmac('sha256', "$h.$p", $secret, true);
        $given = self::b64uDecode($s);
        if ($given === null || !hash_equals($expected, $given)) {
            throw new \RuntimeException('Bad signature');
        }

        $claims = json_decode((string)self::b64uDecode($p), true);
        if (!is_array($claims)) {
            throw new \RuntimeException('Bad payload');
        }

        $now ??= time();
        if (isset($claims['exp']) && $now >= (int)$claims['exp']) {
            throw new \RuntimeException('Token expired');
        }
        if (isset($claims['nbf']) && $now < (int)$claims['nbf']) {
            throw new \RuntimeException('Token not yet valid');
        }
        return $claims;
    }

    private static function b64uEncode(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    private static function b64uDecode(string $s): ?string
    {
        $pad = strlen($s) % 4;
        if ($pad) $s .= str_repeat('=', 4 - $pad);
        $bin = base64_decode(strtr($s, '-_', '+/'), true);
        return $bin === false ? null : $bin;
    }
}
