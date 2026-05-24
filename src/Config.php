<?php
declare(strict_types=1);

namespace Trumpet;

final class Config
{
    private const VALID_VISIBILITY = ['public', 'unlisted', 'private', 'direct'];

    /** @var array<string, array{name:string, instance:string, token_env:string, default_language:?string}> */
    public array $accounts = [];

    /** @var array<string, array{name:string, url:string}> */
    public array $sources = [];

    /**
     * @var list<array{
     *   name:string, account:string, sources:list<string>, interval:string,
     *   interval_seconds:int, max_per_run:int, visibility:string, language:?string,
     *   template:string, enabled:bool
     * }>
     */
    public array $rules = [];

    public static function load(string $path): self
    {
        $data = Yaml::parseFile($path);
        $cfg = new self();

        foreach ((array)($data['accounts'] ?? []) as $a) {
            self::requireKeys($a, ['name', 'instance', 'token_env'], 'account');
            $name = (string)$a['name'];
            if (isset($cfg->accounts[$name])) {
                throw new \RuntimeException("Duplicate account name: $name");
            }
            $cfg->accounts[$name] = [
                'name' => $name,
                'instance' => rtrim((string)$a['instance'], '/'),
                'token_env' => (string)$a['token_env'],
                'default_language' => isset($a['default_language']) ? (string)$a['default_language'] : null,
            ];
        }

        foreach ((array)($data['sources'] ?? []) as $s) {
            self::requireKeys($s, ['name', 'url'], 'source');
            $name = (string)$s['name'];
            if (isset($cfg->sources[$name])) {
                throw new \RuntimeException("Duplicate source name: $name");
            }
            $cfg->sources[$name] = ['name' => $name, 'url' => (string)$s['url']];
        }

        foreach ((array)($data['rules'] ?? []) as $r) {
            self::requireKeys($r, ['name', 'account', 'sources', 'interval', 'max_per_run', 'visibility', 'template'], 'rule');
            $name = (string)$r['name'];
            $account = (string)$r['account'];
            if (!isset($cfg->accounts[$account])) {
                throw new \RuntimeException("Rule '$name' references unknown account: $account");
            }
            $sources = (array)$r['sources'];
            foreach ($sources as $sn) {
                if (!isset($cfg->sources[(string)$sn])) {
                    throw new \RuntimeException("Rule '$name' references unknown source: $sn");
                }
            }
            $visibility = (string)$r['visibility'];
            if (!in_array($visibility, self::VALID_VISIBILITY, true)) {
                throw new \RuntimeException("Rule '$name': invalid visibility '$visibility'");
            }
            $intervalSpec = (string)$r['interval'];
            $intervalSeconds = IntervalParser::toSeconds($intervalSpec);
            $maxPerRun = (int)$r['max_per_run'];
            if ($maxPerRun < 1) {
                throw new \RuntimeException("Rule '$name': max_per_run must be >= 1");
            }
            $language = isset($r['language'])
                ? (string)$r['language']
                : $cfg->accounts[$account]['default_language'];
            $enabled = !isset($r['enabled']) || (bool)$r['enabled'];

            $cfg->rules[] = [
                'name' => $name,
                'account' => $account,
                'sources' => array_map('strval', $sources),
                'interval' => $intervalSpec,
                'interval_seconds' => $intervalSeconds,
                'max_per_run' => $maxPerRun,
                'visibility' => $visibility,
                'language' => $language,
                'template' => (string)$r['template'],
                'enabled' => $enabled,
            ];
        }

        $names = array_map(fn($r) => $r['name'], $cfg->rules);
        if (count($names) !== count(array_unique($names))) {
            throw new \RuntimeException("Duplicate rule names");
        }

        return $cfg;
    }

    public function rule(string $name): ?array
    {
        foreach ($this->rules as $r) {
            if ($r['name'] === $name) return $r;
        }
        return null;
    }

    public function tokenFor(string $accountName): ?string
    {
        $acct = $this->accounts[$accountName] ?? null;
        if (!$acct) return null;
        $env = $acct['token_env'];
        $val = getenv($env);
        return ($val === false || $val === '') ? null : $val;
    }

    private static function requireKeys(mixed $a, array $keys, string $what): void
    {
        if (!is_array($a)) {
            throw new \RuntimeException("Invalid $what entry (not a mapping)");
        }
        foreach ($keys as $k) {
            if (!array_key_exists($k, $a)) {
                throw new \RuntimeException("Missing '$k' in $what");
            }
        }
    }
}
