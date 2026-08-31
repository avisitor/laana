#!/bin/bash

# Mint an OpenSearch API token and print it to stdout.
#
# OpenSearch reveals a token's secret exactly once, at creation time, and it
# rejects a create for a name that is already active. So this revokes the
# active token of the same name, then issues a replacement.
#
# Send the result as:  Authorization: apikey <token>     (NOT "Bearer")
#
# Usage: bin/os-api-key.sh [token-name] [duration-seconds]

set -euo pipefail

ENV_FILE="${ENV_FILE:-/var/www/html/noiiolelo/.env}"
TOKEN_NAME="${1:-noiiolelo-app}"
DURATION="${2:-7776000}"   # 90 days == the cluster's max_duration_seconds

if [ ! -r "$ENV_FILE" ]; then
    echo "Error: cannot read $ENV_FILE" >&2
    exit 1
fi

# Cleared so an exported shell var cannot stand in for a missing env file entry.
unset OS_HOST OS_PORT OS_USER OS_PASS

set -a
. "$ENV_FILE"
set +a

for var in OS_HOST OS_PORT OS_USER OS_PASS; do
    if [ -z "${!var:-}" ]; then
        echo "Error: $var is not set in $ENV_FILE" >&2
        exit 1
    fi
done

API="https://$OS_HOST:$OS_PORT/_plugins/_security/api/apitokens"

# Credentials go through a config file on a pipe rather than argv, so the admin
# password is not exposed in `ps` output on this shared host. Non-2xx is failed
# here so callers only ever parse a real JSON body.
os_curl() {
    local response status

    response=$(curl -sk --max-time 15 -w '\n%{http_code}' \
        --config <(printf 'user = "%s:%s"\n' "$OS_USER" "$OS_PASS") "$@")
    status="${response##*$'\n'}"
    response="${response%$'\n'*}"

    if [ "$status" = "000" ]; then
        echo "Error: could not reach OpenSearch at $OS_HOST:$OS_PORT" >&2
        return 1
    fi

    if [ "$status" -lt 200 ] || [ "$status" -ge 300 ]; then
        echo "Error: OpenSearch returned HTTP $status: $response" >&2
        return 1
    fi

    printf '%s' "$response"
}

# Names must be unique among active tokens, so clear ours before recreating.
tokens=$(os_curl "$API")
existing=$(printf '%s' "$tokens" | python3 -c "
import sys, json
tokens = json.load(sys.stdin)
print(next((t['id'] for t in tokens
            if t['name'] == sys.argv[1] and 'revoked_at' not in t), ''))
" "$TOKEN_NAME")

if [ -n "$existing" ]; then
    os_curl -X DELETE "$API/$existing" > /dev/null
fi

# Read-only scope. Widen allowed_actions here if the consumer needs to write;
# a token inherits nothing from the admin user that created it.
payload=$(python3 -c "
import sys, json
print(json.dumps({
    'name': sys.argv[1],
    'duration_seconds': int(sys.argv[2]),
    'cluster_permissions': ['cluster_composite_ops_ro'],
    'index_permissions': [{
        'index_pattern': ['*'],
        'allowed_actions': ['read', 'indices:data/read/*', 'indices:monitor/*'],
    }],
}))
" "$TOKEN_NAME" "$DURATION")

created=$(os_curl -X POST "$API" -H 'Content-Type: application/json' -d "$payload")

printf '%s' "$created" | python3 -c "
import sys, json
body = json.load(sys.stdin)
if 'token' not in body:
    sys.exit('Error: token creation failed: ' + json.dumps(body))
print(body['token'])
"
