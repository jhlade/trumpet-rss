#!/bin/sh
set -eu

# Validate the bare minimum env vars before we hand off to supervisord.
: "${TRUMPET_JWT_SECRET:?TRUMPET_JWT_SECRET must be set}"
: "${TRUMPET_USERS:?TRUMPET_USERS must be set (use \`bin/trumpet hash-password\`)}"

# Cron does not inherit container ENV — snapshot it for the cron job to source.
# Only TRUMPET_* and TZ are exported; secrets stay confined to /etc/trumpet.env (root-only).
{
    env | grep -E '^(TRUMPET_|TZ=)' | while IFS= read -r line; do
        # Escape single quotes inside the value so `sh -c '. file'` works.
        key=${line%%=*}
        value=${line#*=}
        escaped=$(printf '%s' "$value" | sed "s/'/'\\\\''/g")
        printf "export %s='%s'\n" "$key" "$escaped"
    done
} > /etc/trumpet.env
chmod 600 /etc/trumpet.env

# Record container start time so /api/v1/health can report real uptime.
mkdir -p /app/storage
date +%s > /app/storage/.started_at

exec "$@"
