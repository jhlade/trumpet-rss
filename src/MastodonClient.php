<?php
declare(strict_types=1);

namespace Trumpet;

final class MastodonClient
{
    public function __construct(private Logger $log) {}

    /**
     * @return array{ok:bool, http:int, body:?array, error:?string}
     */
    public function postStatus(
        string $instance,
        string $token,
        string $status,
        string $visibility,
        ?string $language = null,
    ): array {
        $url = rtrim($instance, '/') . '/api/v1/statuses';
        $payload = ['status' => $status, 'visibility' => $visibility];
        if ($language !== null && $language !== '') {
            $payload['language'] = $language;
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($payload),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $token,
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ],
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            return ['ok' => false, 'http' => 0, 'body' => null, 'error' => curl_error($ch)];
        }
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);

        $decoded = json_decode((string)$body, true);
        $ok = $http >= 200 && $http < 300;
        if (!$ok) {
            $this->log->warn("Mastodon POST $url returned HTTP $http: " . substr((string)$body, 0, 300));
        }
        return ['ok' => $ok, 'http' => $http, 'body' => is_array($decoded) ? $decoded : null, 'error' => $ok ? null : (string)$body];
    }
}
