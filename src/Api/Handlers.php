<?php
declare(strict_types=1);

namespace Trumpet\Api;

use Trumpet\Auth;
use Trumpet\Config;
use Trumpet\Controller;
use Trumpet\FeedFetcher;
use Trumpet\Logger;
use Trumpet\MastodonClient;
use Trumpet\State;
use Trumpet\StatusFormatter;

final class Handlers
{
    private float $startedAt;

    public function __construct(
        private Config $config,
        private State $state,
        private Auth $auth,
        private FeedFetcher $fetcher,
        private MastodonClient $mastodon,
        private Logger $log,
        private string $version = '0.1.0',
    ) {
        $this->startedAt = microtime(true);
    }

    public function health(Request $req, array $params): Response
    {
        return Response::json(200, [
            'status' => 'ok',
            'version' => $this->version,
            'uptime_seconds' => (int)(microtime(true) - $this->startedAt),
        ]);
    }

    public function login(Request $req, array $params): Response
    {
        $body = $req->json();
        if (!is_array($body) || !isset($body['username'], $body['password'])) {
            return Response::error(400, 'bad_request', 'username and password required');
        }
        $res = $this->auth->login((string)$body['username'], (string)$body['password']);
        if ($res === null) {
            return Response::error(401, 'invalid_credentials', 'Bad username or password');
        }
        return Response::json(200, $res);
    }

    public function getConfig(Request $req, array $params): Response
    {
        $accounts = array_values(array_map(fn($a) => [
            'name' => $a['name'],
            'instance' => $a['instance'],
            'token_env' => $a['token_env'],
            'token_set' => $this->config->tokenFor($a['name']) !== null,
            'default_language' => $a['default_language'],
        ], $this->config->accounts));

        $sources = array_values($this->config->sources);

        $rules = array_map(fn($r) => [
            'name' => $r['name'],
            'account' => $r['account'],
            'sources' => $r['sources'],
            'interval' => $r['interval'],
            'interval_seconds' => $r['interval_seconds'],
            'max_per_run' => $r['max_per_run'],
            'visibility' => $r['visibility'],
            'language' => $r['language'],
            'template' => $r['template'],
            'enabled' => $r['enabled'],
        ], $this->config->rules);

        return Response::json(200, compact('accounts', 'sources', 'rules'));
    }

    public function listRules(Request $req, array $params): Response
    {
        $now = time();
        $out = array_map(function ($r) use ($now) {
            $last = $this->state->lastRunAt($r['name']);
            return [
                'name' => $r['name'],
                'account' => $r['account'],
                'sources' => $r['sources'],
                'interval' => $r['interval'],
                'max_per_run' => $r['max_per_run'],
                'visibility' => $r['visibility'],
                'enabled' => $r['enabled'],
                'last_run_at' => $last,
                'due' => $last === 0 || ($now - $last) >= $r['interval_seconds'],
                'seconds_until_due' => $last === 0 ? 0 : max(0, $r['interval_seconds'] - ($now - $last)),
                'seen_items_count' => $this->state->countSeen($r['name']),
            ];
        }, $this->config->rules);
        return Response::json(200, ['rules' => $out]);
    }

    public function getRule(Request $req, array $params): Response
    {
        $rule = $this->config->rule($params['name']);
        if ($rule === null) {
            return Response::error(404, 'not_found', "Unknown rule: {$params['name']}");
        }
        $now = time();
        $last = $this->state->lastRunAt($rule['name']);
        return Response::json(200, array_merge($rule, [
            'last_run_at' => $last,
            'due' => $last === 0 || ($now - $last) >= $rule['interval_seconds'],
            'seconds_until_due' => $last === 0 ? 0 : max(0, $rule['interval_seconds'] - ($now - $last)),
            'seen_items_count' => $this->state->countSeen($rule['name']),
        ]));
    }

    public function runRule(Request $req, array $params): Response
    {
        return $this->actOnRule($params['name'], dryRun: false);
    }

    public function testRule(Request $req, array $params): Response
    {
        return $this->actOnRule($params['name'], dryRun: true);
    }

    public function bootstrapRule(Request $req, array $params): Response
    {
        $name = $params['name'];
        if ($this->config->rule($name) === null) {
            return Response::error(404, 'not_found', "Unknown rule: $name");
        }
        $controller = $this->makeController(dryRun: false);
        ob_start();
        $ok = $controller->bootstrap($name);
        ob_end_clean();
        return Response::json(200, ['rule' => $name, 'bootstrapped' => $ok, 'seen_items_count' => $this->state->countSeen($name)]);
    }

    public function runAll(Request $req, array $params): Response
    {
        $controller = $this->makeController(dryRun: false);
        ob_start();
        $ok = $controller->run(null);
        ob_end_clean();
        return Response::json(200, ['ok' => $ok]);
    }

    public function prune(Request $req, array $params): Response
    {
        $body = $req->json();
        $days = is_array($body) && isset($body['days']) ? (int)$body['days'] : 90;
        if ($days < 1) {
            return Response::error(400, 'bad_request', 'days must be >= 1');
        }
        $deleted = $this->state->prune($days);
        return Response::json(200, ['deleted' => $deleted, 'days' => $days]);
    }

    public function seen(Request $req, array $params): Response
    {
        $rule = $req->query['rule'] ?? null;
        if (!is_string($rule) || $rule === '') {
            return Response::error(400, 'bad_request', 'query param `rule` required');
        }
        if ($this->config->rule($rule) === null) {
            return Response::error(404, 'not_found', "Unknown rule: $rule");
        }
        $limit = isset($req->query['limit']) ? max(1, min(500, (int)$req->query['limit'])) : 50;
        $rows = $this->state->recentSeen($rule, $limit);
        return Response::json(200, ['rule' => $rule, 'items' => $rows, 'limit' => $limit]);
    }

    private function actOnRule(string $name, bool $dryRun): Response
    {
        if ($this->config->rule($name) === null) {
            return Response::error(404, 'not_found', "Unknown rule: $name");
        }
        $controller = $this->makeController($dryRun);
        ob_start();
        $ok = $controller->run($name);
        $stdout = ob_get_clean();
        $lines = $stdout === '' ? [] : array_values(array_filter(explode("\n", rtrim($stdout, "\n")), fn($l) => $l !== ''));
        return Response::json(200, [
            'rule' => $name,
            'dry_run' => $dryRun,
            'ok' => $ok,
            'output' => $lines,
        ]);
    }

    private function makeController(bool $dryRun): Controller
    {
        return new Controller(
            $this->config, $this->state, $this->fetcher, $this->mastodon, $this->log, $dryRun
        );
    }
}
