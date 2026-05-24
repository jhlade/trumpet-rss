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
bin/trumpet check                       # validate config, print status of accounts/sources/rules
bin/trumpet test <rule>                 # dry-run a rule: print rendered statuses, no HTTP
bin/trumpet bootstrap <rule>            # mark current feed contents as seen without posting
bin/trumpet run                         # process all due rules — intended for cron
bin/trumpet prune [days=90]             # delete seen_items older than N days
bin/trumpet hash-password <pw> [user]   # bcrypt a password for TRUMPET_USERS
bin/trumpet serve [--host=H --port=P]   # start the HTTP API (built-in PHP server)
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

## Container (Podman / Docker)

A single image runs both the HTTP API and the cron worker under `supervisord`.

```bash
cp example.env .env
$EDITOR .env                            # set JWT secret, users, tokens
podman build -t trumpet-rss:dev .
podman run --rm -d --name trumpet \
  -p 8080:8080 \
  --env-file .env \
  -v "$PWD/config:/app/config:Z" \
  -v "$PWD/storage:/app/storage:Z" \
  trumpet-rss:dev
podman logs -f trumpet
```

Volumes:

- `/app/config` — YAML config; can be mounted read-only.
- `/app/storage` — SQLite DB and log; persists state across restarts.

Generate a bcrypt for the `TRUMPET_USERS` entry without leaving the container:

```bash
podman run --rm trumpet-rss:dev php /app/bin/trumpet hash-password mysecret admin
# → admin:$2y$10$...
```

The cron job inside the image runs `bin/trumpet run` every 5 minutes — see
[docker/crontab](docker/crontab). Edit it before rebuild if you need a
different cadence.

## HTTP JSON API

Mounted under `/api/v1/`. Bearer JWT required except `/health` and
`/auth/login`. All responses are JSON.

| Method | Path                                | Auth | Purpose                                    |
|--------|-------------------------------------|------|--------------------------------------------|
| GET    | `/api/v1/health`                    | no   | Status, version, uptime                    |
| POST   | `/api/v1/auth/login`                | no   | `{username, password}` → `{token, expires_at}` |
| GET    | `/api/v1/config`                    | yes  | Accounts, sources, rules (no token values) |
| GET    | `/api/v1/rules`                     | yes  | Rules + state (last_run, due, seen count)  |
| GET    | `/api/v1/rules/{name}`              | yes  | Single rule detail                         |
| POST   | `/api/v1/rules/{name}/run`          | yes  | Process one rule synchronously             |
| POST   | `/api/v1/rules/{name}/test`         | yes  | Dry-run (no HTTP to Mastodon)              |
| POST   | `/api/v1/rules/{name}/bootstrap`    | yes  | Mark current feed as seen                  |
| POST   | `/api/v1/run`                       | yes  | Process all due rules                      |
| POST   | `/api/v1/prune`                     | yes  | `{days?: 90}` → `{deleted: N}`             |
| GET    | `/api/v1/state/seen?rule=X&limit=N` | yes  | Recent seen items for a rule               |

Errors use a uniform shape: `{"error": {"code": "...", "message": "..."}}`.

### Authentication

Set in your environment (or `.env`):

```dotenv
TRUMPET_JWT_SECRET=long-random-string
TRUMPET_JWT_TTL=28800
TRUMPET_USERS=admin:$2y$10$bcrypt-hash[,jane:$2y$10$another-hash]
```

Get a token, then use it:

```bash
TOKEN=$(curl -fsS -X POST localhost:8080/api/v1/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"username":"admin","password":"admin"}' | jq -r .token)

curl -fsS -H "Authorization: Bearer $TOKEN" localhost:8080/api/v1/rules | jq .
```

The token is a standard HS256 JWT — 8 h TTL by default. Rotate
`TRUMPET_JWT_SECRET` to invalidate every issued token at once.

## Testing

Tests live under `tests/` and use a tiny self-contained runner — no PHPUnit
or Composer involved. Run the suite locally with:

```bash
php tests/run.php
```

Tests cover the pure-logic units (`Yaml`, `IntervalParser`, `StatusFormatter`,
`Config`, `Jwt`, `Auth`, `Api\Router`). `FeedFetcher`, `MastodonClient`, and
the live HTTP endpoints are not covered automatically because they hit the
network or require a running server.

## Continuous Integration

GitHub Actions runs `php -l` over all sources and executes the test suite on
a PHP 8.1 → 8.4 matrix on every push and pull request, then builds the
container image and smoke-tests the HTTP API — see
[.github/workflows/ci.yml](.github/workflows/ci.yml).

## License

GNU GPL v3 — see [LICENSE](LICENSE).
