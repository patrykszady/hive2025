#!/usr/bin/env bash
# Forge API helper — speaks the current API, falls back to legacy v1.
#
# Legacy v1 (https://forge.laravel.com/api/v1/...) is retired 2026-08-31.
# The CURRENT API differs in ways that are easy to get wrong, all verified
# against the live API on 2026-08-15:
#   1. NO version segment. Base is https://forge.laravel.com/api — an
#      /api/v2/... path 401s (not 404s), which looks exactly like a bad
#      token and sent me chasing the wrong problem.
#   2. Paths are scoped to an organization under /orgs/{slug} — note "orgs",
#      NOT "organizations", which also 401s rather than 404s.
#   3. Content-Type: application/json is REQUIRED on reads too; without it
#      even a valid token is rejected.
#   4. Tokens are scoped now. Deploy-script access needs site:manage-deploys
#      (plus server:view / site:view to resolve ids).
#   5. The deploy script lives under the DEPLOYMENTS collection:
#      /orgs/{slug}/servers/{id}/sites/{id}/deployments/script
#      and its payload is JSON:API shaped ({"data":{"attributes":{...}}}).
#
# Usage:
#   scripts/forge-api.sh whoami                  # show version + org slug in use
#   scripts/forge-api.sh get-deploy-script       # print the live deploy script
#   scripts/forge-api.sh set-deploy-script FILE  # replace it from FILE
#   scripts/forge-api.sh get-env                 # print the live .env (SECRETS)
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
            -H "Content-Type: application/json" --max-time 30 "$url"
    fi
}

# Pick the newest API this token can actually authenticate against, and for
# v2 resolve the org slug (its path segment name is discovered, not assumed).
resolve_api() {
    if [ -n "$TOKEN_V2" ]; then
        TOKEN="$TOKEN_V2"
        local code
        code="$(curl -sS -o /tmp/forge-orgs.json -w '%{http_code}' \
            -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
            -H "Content-Type: application/json" \
            --max-time 30 "https://forge.laravel.com/api/orgs")" || true

        if [ "$code" = "200" ]; then
            ORG_SLUG="${FORGE_ORG_SLUG:-$(python3 -c "
import json
d = json.load(open('/tmp/forge-orgs.json'))
rows = d.get('data', [])
print(rows[0]['attributes']['slug'] if rows else '')")}"
            if [ -n "$ORG_SLUG" ]; then
                API_VERSION=current
                BASE="https://forge.laravel.com/api/orgs/$ORG_SLUG"
                return 0
            fi
        fi
        echo "WARNING: FORGE_API_TOKEN_V2 is set but /api/orgs returned $code." >&2
    fi

    if [ -z "$TOKEN_V1" ]; then
        echo "ERROR: no Forge token found in .env" >&2; exit 1
    fi

    TOKEN="$TOKEN_V1"
    API_VERSION=legacy-v1
    ORG_SLUG="(n/a)"
    BASE="https://forge.laravel.com/api/v1"
    echo "NOTE: using LEGACY API v1 — RETIRED 2026-08-31. Generate a token at" >&2
    echo "      https://forge.laravel.com/profile/api and set FORGE_API_TOKEN_V2 in .env" >&2
}

resolve_api
SITE_PATH="$BASE/servers/$SERVER_ID/sites/$SITE_ID"

case "${1:-}" in
    whoami)
        echo "api=$API_VERSION org=$ORG_SLUG server=$SERVER_ID site=$SITE_ID"
        ;;
    get-deploy-script)
        if [ "$API_VERSION" = "current" ]; then
            api GET "$SITE_PATH/deployments/script" \
                | python3 -c "import json,sys; sys.stdout.write(json.load(sys.stdin)['data']['attributes']['content'])"
        else
            api GET "$SITE_PATH/deployment/script"
        fi
        ;;
    set-deploy-script)
        [ -f "${2:-}" ] || { echo "usage: $0 set-deploy-script FILE" >&2; exit 1; }
        python3 -c "import json,sys; print(json.dumps({'content': open(sys.argv[1]).read()}))" "$2" > /tmp/forge-payload.json
        if [ "$API_VERSION" = "current" ]; then
            api PUT "$SITE_PATH/deployments/script" /tmp/forge-payload.json
        else
            api PUT "$SITE_PATH/deployment/script" /tmp/forge-payload.json
        fi
        echo
        ;;
    get-env)
        # Prints the site's live environment file to stdout. Contains real
        # credentials — pipe it to a file, don't leave it in scrollback.
        if [ "$API_VERSION" = "current" ]; then
            api GET "$SITE_PATH/environment" | python3 -c "
import json, sys
raw = sys.stdin.read()
try:
    sys.stdout.write(json.loads(raw)['data']['attributes']['content'])
except (ValueError, KeyError, TypeError):
    sys.stdout.write(raw)"
        else
            api GET "$SITE_PATH/env"
        fi
        ;;
    *)
        sed -n '2,18p' "$0"; exit 1
        ;;
esac
