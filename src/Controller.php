<?php
declare(strict_types=1);

namespace Trumpet;

final class Controller
{
    public function __construct(
        private Config $config,
        private State $state,
        private FeedFetcher $fetcher,
        private MastodonClient $mastodon,
        private Logger $log,
        private bool $dryRun = false,
    ) {}

    /**
     * Process every enabled & due rule. Returns true if all succeeded (no failures).
     */
    public function run(?string $onlyRule = null): bool
    {
        $now = time();
        $allOk = true;

        foreach ($this->config->rules as $rule) {
            if ($onlyRule !== null && $rule['name'] !== $onlyRule) {
                continue;
            }
            if (!$rule['enabled']) {
                $this->log->debug("Rule '{$rule['name']}' disabled, skipping");
                continue;
            }

            $token = $this->config->tokenFor($rule['account']);
            if ($token === null && !$this->dryRun) {
                $envName = $this->config->accounts[$rule['account']]['token_env'];
                $this->log->warn("Rule '{$rule['name']}': missing env $envName, skipping");
                $allOk = false;
                continue;
            }

            $lastRun = $this->state->lastRunAt($rule['name']);
            $isBootstrap = $lastRun === 0;
            $due = $isBootstrap || ($now - $lastRun) >= $rule['interval_seconds'];

            if ($onlyRule === null && !$due) {
                $remaining = $rule['interval_seconds'] - ($now - $lastRun);
                $this->log->debug("Rule '{$rule['name']}' not due (in {$remaining}s)");
                continue;
            }

            $ok = $this->processRule($rule, $token, $now, $isBootstrap);
            $allOk = $allOk && $ok;
        }

        return $allOk;
    }

    /**
     * Bootstrap a rule: mark all current feed items as seen without posting.
     */
    public function bootstrap(string $ruleName): bool
    {
        $rule = $this->config->rule($ruleName);
        if ($rule === null) {
            $this->log->error("Unknown rule: $ruleName");
            return false;
        }
        $now = time();
        $count = 0;
        foreach ($rule['sources'] as $sn) {
            $items = $this->fetcher->fetch($this->config->sources[$sn]['url']);
            foreach ($items as $it) {
                $this->state->recordPosted($rule['name'], $sn, $it['guid'], $now);
                $count++;
            }
        }
        $this->state->markRun($rule['name'], $now);
        $this->log->info("Bootstrapped '$ruleName': $count items marked as seen");
        return true;
    }

    private function processRule(array $rule, ?string $token, int $now, bool $isBootstrap): bool
    {
        $this->log->info("Rule '{$rule['name']}': processing"
            . ($isBootstrap ? ' (bootstrap)' : '')
            . ($this->dryRun ? ' (dry-run)' : ''));

        $candidates = [];
        foreach ($rule['sources'] as $sn) {
            $url = $this->config->sources[$sn]['url'];
            $items = $this->fetcher->fetch($url);
            $unseen = $this->state->filterUnseen($rule['name'], $sn, $items);
            foreach ($unseen as $it) {
                $candidates[] = ['source' => $sn, 'item' => $it];
            }
        }

        usort($candidates, fn($a, $b) => $b['item']['timestamp'] <=> $a['item']['timestamp']);

        if ($isBootstrap && !$this->dryRun) {
            foreach ($candidates as $c) {
                $this->state->recordPosted($rule['name'], $c['source'], $c['item']['guid'], $now);
            }
            $this->state->markRun($rule['name'], $now);
            $this->log->info("Rule '{$rule['name']}': bootstrapped " . count($candidates) . " items, no posts sent");
            return true;
        }

        $toPost = array_slice($candidates, 0, $rule['max_per_run']);
        $this->log->info("Rule '{$rule['name']}': " . count($candidates) . " new, posting " . count($toPost));

        // Post chronologically (oldest first) so newer items appear last on the timeline.
        $toPost = array_reverse($toPost);

        $ok = true;
        foreach ($toPost as $c) {
            $status = StatusFormatter::render($rule['template'], $c['item']);
            if ($this->dryRun) {
                fwrite(STDOUT, "--- [{$rule['name']}] <{$c['source']}> guid={$c['item']['guid']}\n$status\n");
                continue;
            }
            $res = $this->mastodon->postStatus(
                $this->config->accounts[$rule['account']]['instance'],
                (string)$token,
                $status,
                $rule['visibility'],
                $rule['language'],
            );
            if (!$res['ok']) {
                $this->log->error("Rule '{$rule['name']}': post failed (HTTP {$res['http']}), stopping for this run");
                $ok = false;
                break;
            }
            $this->state->recordPosted($rule['name'], $c['source'], $c['item']['guid'], $now);
            $this->log->info("Rule '{$rule['name']}': posted {$c['item']['guid']}");
        }

        if ($ok && !$this->dryRun) {
            $this->state->markRun($rule['name'], $now);
        }
        return $ok;
    }
}
