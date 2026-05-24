<?php
declare(strict_types=1);

namespace Trumpet;

final class State
{
    private \PDO $db;

    public function __construct(string $path)
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create storage dir: $dir");
        }
        $this->db = new \PDO('sqlite:' . $path);
        $this->db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->db->exec('PRAGMA journal_mode = WAL');
        $this->init();
    }

    private function init(): void
    {
        $this->db->exec("
            CREATE TABLE IF NOT EXISTS rule_state (
                rule_name   TEXT PRIMARY KEY,
                last_run_at INTEGER NOT NULL DEFAULT 0
            );
            CREATE TABLE IF NOT EXISTS seen_items (
                rule_name   TEXT NOT NULL,
                source_name TEXT NOT NULL,
                item_guid   TEXT NOT NULL,
                posted_at   INTEGER NOT NULL,
                PRIMARY KEY (rule_name, source_name, item_guid)
            );
            CREATE INDEX IF NOT EXISTS idx_seen_posted_at
                ON seen_items (rule_name, posted_at);
        ");
    }

    public function lastRunAt(string $rule): int
    {
        $s = $this->db->prepare('SELECT last_run_at FROM rule_state WHERE rule_name = ?');
        $s->execute([$rule]);
        $v = $s->fetchColumn();
        return $v === false ? 0 : (int)$v;
    }

    public function markRun(string $rule, int $ts): void
    {
        $s = $this->db->prepare(
            'INSERT INTO rule_state (rule_name, last_run_at) VALUES (?, ?)
             ON CONFLICT(rule_name) DO UPDATE SET last_run_at = excluded.last_run_at'
        );
        $s->execute([$rule, $ts]);
    }

    /**
     * @param list<array{guid:string, ...}> $items
     * @return list<array{guid:string, ...}>
     */
    public function filterUnseen(string $rule, string $source, array $items): array
    {
        if (!$items) return [];
        $guids = array_map(fn($it) => $it['guid'], $items);
        $placeholders = implode(',', array_fill(0, count($guids), '?'));
        $sql = "SELECT item_guid FROM seen_items
                WHERE rule_name = ? AND source_name = ? AND item_guid IN ($placeholders)";
        $s = $this->db->prepare($sql);
        $s->execute([$rule, $source, ...$guids]);
        $seen = array_flip($s->fetchAll(\PDO::FETCH_COLUMN));
        return array_values(array_filter($items, fn($it) => !isset($seen[$it['guid']])));
    }

    public function recordPosted(string $rule, string $source, string $guid, int $ts): void
    {
        $s = $this->db->prepare(
            'INSERT OR IGNORE INTO seen_items (rule_name, source_name, item_guid, posted_at)
             VALUES (?, ?, ?, ?)'
        );
        $s->execute([$rule, $source, $guid, $ts]);
    }

    public function prune(int $olderThanDays = 90): int
    {
        $cutoff = time() - $olderThanDays * 86400;
        $s = $this->db->prepare('DELETE FROM seen_items WHERE posted_at < ?');
        $s->execute([$cutoff]);
        return $s->rowCount();
    }

    public function countSeen(string $rule): int
    {
        $s = $this->db->prepare('SELECT COUNT(*) FROM seen_items WHERE rule_name = ?');
        $s->execute([$rule]);
        return (int)$s->fetchColumn();
    }
}
