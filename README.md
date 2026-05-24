# trumpet-rss

Mastodon RSS feed automator. Manages any number of Mastodon accounts and
RSS/Atom sources; their M:N pairing is driven by a YAML config. Inspired by
the minimal [mastodon-rss-bot](https://github.com/diveshlunker/mastodon-rss-bot).

## Features

- Single CLI driven by a single cron entry — an internal scheduler decides
  which rules are due.
- M:N rules: one account, any number of sources; different rules can post to
  different accounts with different visibility and template.
- State in SQLite (last run per rule, "seen" item guids).
- Bootstrap of a newly added rule: on its first run, current feed contents
  are marked as seen and nothing is posted (no timeline flooding).
- Tokens only from the environment — the YAML holds only the env var name.
- No Composer. Dependencies: `dg/rss-php` as a git submodule.

## Requirements

- PHP 8.1+ with extensions `pdo_sqlite`, `curl`, `simplexml`
  (and ideally `mbstring`).
- `sqlite3` CLI (optional, for inspecting state).
- `git` (to initialize the submodule).

## Installation

```bash
git clone <repo> trumpet-rss
cd trumpet-rss
git submodule update --init --recursive

cp config/config.example.yaml config/config.yaml
$EDITOR config/config.yaml
```

## Configuration

`config/config.yaml` has three sections: `accounts`, `sources`, `rules`. See
[config/config.example.yaml](config/config.example.yaml).

### Accounts

```yaml
accounts:
  - name: myaccount                    # internal identifier
    instance: https://mastodon.social
    token_env: TRUMPET_TOKEN_MYACCOUNT # name of the env var holding the access token
    default_language: en               # optional
```

Obtain an access token in Mastodon: *Preferences → Development → New
application* with `write:statuses` scope. Set the token in the named env
variable (see [Cron](#cron) below).

### Sources

```yaml
sources:
  - name: news
    url: https://example.com/feed.xml
```

### Rules

```yaml
rules:
  - name: news-to-myaccount
    account: myaccount
    sources: [news, blog]       # one or more
    interval: 20m               # s | m | h | d
    max_per_run: 3              # max number of newest items per run
    visibility: unlisted        # public | unlisted | private | direct
    language: en                # optional (defaults to account.default_language)
    template: "{title}\n{link}" # placeholders: {title} {link} {summary} {pub_date} {guid}
    enabled: true               # optional, default true
```

Each status is truncated to 500 characters (Mastodon's limit) with an ellipsis.

## CLI

```bash
bin/trumpet check              # validate config, print status of accounts/sources/rules
bin/trumpet test <rule>        # dry-run a rule: print rendered statuses, no HTTP
bin/trumpet bootstrap <rule>   # mark current feed contents as seen without posting
bin/trumpet run                # process all due rules — intended for cron
bin/trumpet prune [days=90]    # delete seen_items older than N days
```

Useful flags:

- `-v` / `--verbose` — DEBUG logging to stderr.
- `--config=PATH` — alternative path to the YAML.

Exit codes: `0` ok, `1` config error, `2` at least one rule failed (others
continue).

## First run of a new rule

A rule that has never run (`last_run_at = 0`) is **bootstrapped** on its
first `run`: all current items from its sources are marked as seen and
nothing is posted. The same effect can be triggered manually via
`bin/trumpet bootstrap <rule>`. This avoids flooding the timeline when a new
rule is added.

## Cron

A single crontab line invokes `run`, e.g. every 5 minutes:

```cron
*/5 * * * * /opt/trumpet-rss/bin/trumpet run >> /var/log/trumpet.log 2>&1
```

Provide tokens via the environment. Options:

- **systemd timer** with `EnvironmentFile=/etc/trumpet-rss/secrets.env`
  (lines like `TRUMPET_TOKEN_MYACCOUNT=...`).
- A wrapper script invoked from cron that first `source`s a secrets file.
- Inline in crontab (least recommended):
  `TRUMPET_TOKEN_MYACCOUNT=... /opt/.../bin/trumpet run`.

## State in SQLite

The database lives in `storage/trumpet.sqlite` (auto-created). Two tables:

```
rule_state(rule_name PRIMARY KEY, last_run_at INTEGER)
seen_items(rule_name, source_name, item_guid, PRIMARY KEY (..), posted_at INTEGER)
```

Manual inspection:

```bash
sqlite3 storage/trumpet.sqlite 'SELECT * FROM rule_state;'
sqlite3 storage/trumpet.sqlite 'SELECT rule_name, COUNT(*) FROM seen_items GROUP BY rule_name;'
```

## License

GNU GPL v3 — see [LICENSE](LICENSE).
