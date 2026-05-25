# 🎺🐘 Trumpet RSS

Mastodon RSS feed automator. Manages any number of Mastodon accounts and
RSS/Atom sources; their M:N pairing is driven by a YAML config. Inspired by
the minimal [mastodon-rss-bot](https://github.com/diveshlunker/mastodon-rss-bot).

The default deployment is a **single container** that runs both the HTTP
JSON API and a cron worker under `supervisord`. Bare-metal use is supported
too (see [Development without the container](#development-without-the-container)).

## Features

- One container = HTTP API + cron worker. One `compose up` deploys it.
- M:N rules: one account, any number of sources; different rules can post to
  different accounts with different visibility and template.
- State in SQLite (last run per rule, "seen" item guids) — persisted via
  bind-mounted `storage/` volume.
- Bootstrap of a newly added rule: on its first run, current feed contents
  are marked as seen and nothing is posted (no timeline flooding).
- JWT-protected JSON API; users from `TRUMPET_USERS` and/or LDAP / AD with
  group-membership gate.
- Tokens / secrets only from environment — the YAML holds only env-var names.
- No Composer. Sole runtime dependency is `dg/rss-php` as a git submodule.

## Requirements

- **Container deployment** (recommended): Podman or Docker with a Compose
  v2-compatible CLI (`podman compose` / `docker compose`).
- **Bare-metal / dev**: PHP 8.1+ with extensions `pdo_sqlite`, `curl`,
  `simplexml`, `mbstring` (optional but recommended), and `ldap` if you want
  LDAP auth. `git` to fetch the submodule.

## Quick start (container)

```bash
git clone <repo> trumpet-rss
cd trumpet-rss
git submodule update --init --recursive

cp config/config.example.yaml config/config.yaml
cp example.env .env
$EDITOR config/config.yaml .env      # set rules + Mastodon tokens + admin creds

docker compose up -d --build         # or: podman compose up -d --build
docker compose logs -f trumpet
```

Then verify:

```bash
curl -fsS localhost:8080/api/v1/health | jq

TOKEN=$(curl -fsS -X POST localhost:8080/api/v1/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"username":"admin","password":"admin"}' | jq -r .token)

curl -fsS -H "Authorization: Bearer $TOKEN" localhost:8080/api/v1/rules | jq
```

> **Note — bcrypt and compose.** `docker compose` / `podman compose` perform
> variable interpolation on `env_file`, so each `$` in a bcrypt hash inside
> `.env` must be doubled to `$$`. [example.env](example.env) ships in the
> escaped form by default (admin/admin works out of the box). If you instead
> use plain `podman run --env-file`, halve every `$$` back to `$` in
> `TRUMPET_USERS`.

### Volumes

| Path on host       | Inside container | Mode | Purpose                                 |
|--------------------|------------------|------|-----------------------------------------|
| `./config`         | `/app/config`    | ro   | `config.yaml` — your rules              |
| `./storage`        | `/app/storage`   | rw   | SQLite DB, `trumpet.log`, start sentinel |

`config.yaml` is reread on each request (and each cron tick) — no restart
needed after editing rules. `.env` changes require `docker compose up -d` to
take effect.

### Manual `podman run` (no compose)

```bash
podman build -t trumpet-rss:dev .
podman run --rm -d --name trumpet \
  -p 8080:8080 \
  --env-file .env \
  -v "$PWD/config:/app/config:Z" \
  -v "$PWD/storage:/app/storage:Z" \
  trumpet-rss:dev
podman logs -f trumpet
```

## Configuration (`config/config.yaml`)

Three sections: `accounts`, `sources`, `rules`. See
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
variable (in `.env` for compose, or in your systemd EnvironmentFile / shell
for bare-metal).

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

## Environment (`.env`)

All secrets live in `.env`. See [example.env](example.env) for the full
template — the important bits:

```dotenv
# Mastodon — one TRUMPET_TOKEN_* per account, matching its token_env
TRUMPET_TOKEN_MYACCOUNT=...

# API auth (required)
TRUMPET_JWT_SECRET=long-random-string
TRUMPET_JWT_TTL=28800
TRUMPET_USERS=admin:$$2y$$10$$bcrypt-hash      # use $$ inside compose env_file

# LDAP / AD (optional — leave TRUMPET_LDAP_URL empty to disable)
#TRUMPET_LDAP_URL=ldaps://dc1.corp.local:636
#... (see LDAP section below)

TRUMPET_LOG_LEVEL=info
TZ=Europe/Prague
```

Generate a bcrypt for `TRUMPET_USERS` without leaving the container:

```bash
docker compose exec trumpet php /app/bin/trumpet hash-password mysecret admin
# → admin:$2y$10$...    (double every $ before pasting into compose .env)
```

## HTTP JSON API

Mounted under `/api/v1/`. Bearer JWT required except `/health` and
`/auth/login`. All responses are JSON.

| Method | Path                                | Auth | Purpose                                    |
|--------|-------------------------------------|------|--------------------------------------------|
| GET    | `/api/v1/health`                    | no   | Status, version, container uptime          |
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

The token is a standard HS256 JWT — 8 h TTL by default. Rotate
`TRUMPET_JWT_SECRET` to invalidate every issued token at once.

### Verifying a new rule

After editing `config/config.yaml`, the API picks the change up immediately
(no restart needed). Recommended workflow:

```bash
# 1) Check the parser is happy and your rule appears as 'DUE'
docker compose exec trumpet php /app/bin/trumpet check

# 2) Dry-run: see exactly what would be posted
curl -fsS -X POST -H "Authorization: Bearer $TOKEN" \
  localhost:8080/api/v1/rules/<rule-name>/test | jq

# 3) Bootstrap so existing feed items don't flood the timeline
curl -fsS -X POST -H "Authorization: Bearer $TOKEN" \
  localhost:8080/api/v1/rules/<rule-name>/bootstrap | jq

# 4) From now on, the cron tick (every 5 min) will pick up new items
docker compose exec trumpet tail -f /app/storage/trumpet.log
```

### LDAP / Active Directory (optional)

When `TRUMPET_LDAP_URL` is set, the login endpoint tries `TRUMPET_USERS`
first (an emergency local admin) and falls back to LDAP. A user
authenticates successfully if:

1. the service account bind succeeds,
2. the user is found by the configured filter,
3. their password binds successfully, and
4. their `memberOf` contains at least one DN in `TRUMPET_LDAP_ADMIN_GROUPS`.

Relevant env vars (see [example.env](example.env)):

```dotenv
TRUMPET_LDAP_URL=ldaps://dc1.corp.local:636
TRUMPET_LDAP_STARTTLS=0
TRUMPET_LDAP_TLS_VERIFY=1
TRUMPET_LDAP_CA_FILE=                              # optional CA bundle path
TRUMPET_LDAP_BIND_DN=CN=trumpet-svc,OU=Service Accounts,DC=corp,DC=local
TRUMPET_LDAP_BIND_PASSWORD=...
TRUMPET_LDAP_BASE_DN=DC=corp,DC=local
TRUMPET_LDAP_USER_FILTER=(sAMAccountName={user})   # {user} is ldap-escaped
TRUMPET_LDAP_ADMIN_GROUPS=CN=TrumpetAdmins,OU=Groups,DC=corp,DC=local
```

Multiple admin group DNs are separated by `;` (since DNs themselves contain
commas). Group matching is case-insensitive on the full DN; nested groups
are **not** resolved — flatten via a dedicated security group.

Notes:

- Issued tokens carry only the username; once issued, a token stays valid
  until `TRUMPET_JWT_TTL` expires regardless of subsequent LDAP changes.
  Rotate `TRUMPET_JWT_SECRET` for immediate invalidation.
- Bind only; SASL/Kerberos and client-cert mTLS are out of scope.

## CLI

The same CLI runs both inside the container (for ad-hoc admin) and on a
host with PHP installed.

```bash
bin/trumpet check                       # validate config, print accounts/sources/rules state
bin/trumpet test <rule>                 # dry-run a rule: print rendered statuses, no HTTP
bin/trumpet bootstrap <rule>            # mark current feed contents as seen without posting
bin/trumpet run                         # process all due rules — the cron entrypoint
bin/trumpet prune [days=90]             # delete seen_items older than N days
bin/trumpet hash-password <pw> [user]   # bcrypt a password for TRUMPET_USERS
bin/trumpet serve [--host=H --port=P]   # start the HTTP API (built-in PHP server)
```

Inside the container, prefix with `docker compose exec trumpet php /app/bin/trumpet …`
(or `podman exec trumpet …`).

Useful flags: `-v` / `--verbose` for DEBUG logging, `--config=PATH` for an
alternate YAML.

Exit codes: `0` ok, `1` config error, `2` at least one rule failed (others
continue).

## State / inspection

The SQLite database lives at `/app/storage/trumpet.sqlite` inside the
container (= `./storage/trumpet.sqlite` on the host via the bind mount).
Two tables:

```
rule_state(rule_name PRIMARY KEY, last_run_at INTEGER)
seen_items(rule_name, source_name, item_guid, PRIMARY KEY (..), posted_at INTEGER)
```

Inspect from the container:

```bash
docker compose exec trumpet sqlite3 /app/storage/trumpet.sqlite \
  'SELECT * FROM rule_state;'
docker compose exec trumpet sqlite3 /app/storage/trumpet.sqlite \
  'SELECT rule_name, COUNT(*) FROM seen_items GROUP BY rule_name;'
```

Or from the host, if `sqlite3` is installed locally:

```bash
sqlite3 storage/trumpet.sqlite 'SELECT * FROM rule_state;'
```

The cron tick (`*/5 * * * *` — see [docker/crontab](docker/crontab)) appends
to `/app/storage/trumpet.log`. Tail it with:

```bash
docker compose exec trumpet tail -f /app/storage/trumpet.log
# or together with the API stdout:
docker compose logs -f trumpet
```

## First run of a new rule

A rule that has never run (`last_run_at = 0`) is **bootstrapped** on its
first `run`: all current items from its sources are marked as seen and
nothing is posted. The same effect can be triggered manually via
`POST /api/v1/rules/<name>/bootstrap` (or the CLI equivalent). This avoids
flooding the timeline when a new rule is added.

## Development without the container

For development you can run the same code directly on the host. You need
PHP 8.1+ with the extensions listed in [Requirements](#requirements).

```bash
git submodule update --init --recursive

# CLI on the host
TRUMPET_TOKEN_MYACCOUNT=dummy bin/trumpet check

# HTTP API on the host (built-in PHP server)
TRUMPET_JWT_SECRET=dev TRUMPET_USERS=admin:$(bin/trumpet hash-password admin) \
  bin/trumpet serve --host=127.0.0.1 --port=8080
```

If you want to run from cron on a bare host (no container), one crontab
line is enough:

```cron
*/5 * * * * /opt/trumpet-rss/bin/trumpet run >> /var/log/trumpet.log 2>&1
```

Provide tokens via the environment — **systemd timer** with
`EnvironmentFile=/etc/trumpet-rss/secrets.env` is the cleanest option; a
wrapper script that sources a secrets file before invoking `bin/trumpet run`
also works.

## Testing

Tests live under `tests/` and use a tiny self-contained runner — no PHPUnit
or Composer involved. Run the suite locally with:

```bash
php tests/run.php
```

Tests cover the pure-logic units (`Yaml`, `IntervalParser`, `StatusFormatter`,
`Config`, `Jwt`, `Auth`, `Ldap` helpers, `Api\Router`). `FeedFetcher`,
`MastodonClient`, live LDAP, and the live HTTP endpoints are not covered
automatically because they hit the network or require external services.

## Continuous Integration

GitHub Actions runs `php -l` over all sources and executes the test suite on
a PHP 8.1 → 8.4 matrix on every push and pull request, then builds the
container image and smoke-tests the HTTP API — see
[.github/workflows/ci.yml](.github/workflows/ci.yml).

## License

GNU GPL v3 — see [LICENSE](LICENSE).
