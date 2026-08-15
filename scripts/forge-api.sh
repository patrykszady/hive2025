#!/usr/bin/env bash
# Forge API helper — speaks v2, falls back to v1 until a v2 token exists.
#
# Forge API v1 is retired 2026-08-31. v2 differs in two ways that matter:
#   1. Tokens are NOT shared. A v1 token returns 401 on every v2 endpoint;
#      a new token must be generated at https://forge.laravel.com/profile/api
#   2. Every resource path is scoped to an ORGANIZATION slug, which must be
#      discovered once via the (unscoped) organizations endpoint.
#
# Usage:
#   scripts/forge-api.sh whoami                  # show version + org slug in use
#   scripts/forge-api.sh get-deploy-script       # print the live deploy script
#   scripts/forge-api.sh set-deploy-script FILE  # replace it from FILE
#
# Reads FORGE_API_TOKEN_V2 (preferred) or FORGE_API_TOKEN from .env.
set -euo pipefail

cd "$(dirname "$0")/.."

env_value() { grep -m1 "^$1=" .env 2>/dev/null | cut -d= -f2- | tr -d '"'"'"' ' || true; }

SERVER_ID="${FORGE_SERVER_ID:-878484}"
SITE_ID="${FORGE_SITE_ID:-2592255}"

TOKEN_V2="$(env_value FORGE_API_TOKEN_V2)"
TOKEN_V1="$(env_value FORGE_API_TOKEN)"

api() { # api <method> <url> [data-file]
    local method="$1" url="$2" file="${3:-}"
    if [ -n "$file" ]; then
        curl -sS -X "$method" -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
            -H "Content-Type: application/json" --max-time 30 -d @"$file" "$url"
    else
        curl -sS -X "$method" -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
            --max-time 30 "$url"
    fi
}

# Pick the newest API this token can actually authenticate against, and for
# v2 resolve the org slug (its path segment name is discovered, not assumed).
resolve_api() {
    if [ -n "$TOKEN_V2" ]; then
        TOKEN="$TOKEN_V2"
        for seg in organizations orgs; do
            local body code
            body="$(curl -sS -o /tmp/forge-orgs.json -w '%{http_code}' \
                -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
                --max-time 30 "https://forge.laravel.com/api/v2/$seg")" || true
            code="$body"
            if [ "$code" = "200" ]; then
                ORG_SLUG="${FORGE_ORG_SLUG:-$(python3 -c "
import json
d = json.load(open('/tmp/forge-orgs.json'))
rows = d.get('organizations', d.get('data', d if isinstance(d, list) else []))
print(rows[0]['slug'] if rows else '')")}"
                if [ -n "$ORG_SLUG" ]; then
                    API_VERSION=v2
                    BASE="https://forge.laravel.com/api/v2/$seg/$ORG_SLUG"
                    return 0
                fi
            fi
        done
        echo "WARNING: FORGE_API_TOKEN_V2 is set but no v2 endpoint accepted it." >&2
    fi

    if [ -z "$TOKEN_V1" ]; then
        echo "ERROR: no Forge token found in .env" >&2; exit 1
    fi

    TOKEN="$TOKEN_V1"
    API_VERSION=v1
    ORG_SLUG="(n/a)"
    BASE="https://forge.laravel.com/api/v1"
    echo "NOTE: using API v1 — RETIRED 2026-08-31. Generate a v2 token at" >&2
    echo "      https://forge.laravel.com/profile/api and set FORGE_API_TOKEN_V2 in .env" >&2
}

resolve_api
SITE_PATH="$BASE/servers/$SERVER_ID/sites/$SITE_ID"

case "${1:-}" in
    whoami)
        echo "api=$API_VERSION org=$ORG_SLUG server=$SERVER_ID site=$SITE_ID"
        ;;
    get-deploy-script)
        api GET "$SITE_PATH/deployment/script"
        ;;
    set-deploy-script)
        [ -f "${2:-}" ] || { echo "usage: $0 set-deploy-script FILE" >&2; exit 1; }
        python3 -c "import json,sys; print(json.dumps({'content': open(sys.argv[1]).read()}))" "$2" > /tmp/forge-payload.json
        api PUT "$SITE_PATH/deployment/script" /tmp/forge-payload.json
        echo
        ;;
    *)
        sed -n '2,18p' "$0"; exit 1
        ;;
esac
