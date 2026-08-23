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
#   scripts/forge-api.sh set-env-key KEY VALUE   # add/replace one .env key
#   scripts/forge-api.sh get-nginx               # print the live nginx config
#   scripts/forge-api.sh set-nginx FILE          # replace it (checked + auto-rollback)
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
    set-env-key)
        # set-env-key KEY VALUE — add or replace one key in the live .env,
        # leaving the other ~160 lines untouched.
        #
        # Deliberately key-at-a-time rather than a whole-file PUT: the site's
        # environment is the only copy of a lot of credentials, and an upload
        # built from a stale local fetch would silently revert anything changed
        # in the Forge UI in between.
        KEY="${2:?usage: set-env-key KEY VALUE}"
        VALUE="${3?usage: set-env-key KEY VALUE}"

        CURRENT=$(mktemp); trap 'rm -f "$CURRENT"' EXIT
        "$0" get-env > "$CURRENT"

        if [ ! -s "$CURRENT" ]; then
            echo "Refusing to write: fetched an empty environment." >&2
            exit 1
        fi

        UPDATED=$(mktemp); trap 'rm -f "$CURRENT" "$UPDATED"' EXIT
        KEY="$KEY" VALUE="$VALUE" python3 - "$CURRENT" > "$UPDATED" <<'PYEOF'
import os, sys

key, value = os.environ['KEY'], os.environ['VALUE']
lines = open(sys.argv[1]).read().splitlines()
line = f'{key}={value}'

for i, existing in enumerate(lines):
    if existing.split('=', 1)[0].strip() == key:
        lines[i] = line
        break
else:
    lines.append(line)

sys.stdout.write('\n'.join(lines) + '\n')
PYEOF

        # {"environment": ...} — NOT the JSON:API {"data":{"attributes":…}} shape
        # the deployments/script endpoint uses. Mixing them up gets a 422, which
        # the first version of this command threw away with >/dev/null and then
        # reported success anyway. The response is checked now, and the write is
        # confirmed by reading the environment back rather than trusting the
        # status code: a 202 means "accepted", not "applied".
        #
        # Legacy v1 wants {"content": ...} instead — the current-API shape 422s
        # there, so the payload is built per branch.
        if [ "$API_VERSION" = "current" ]; then ENV_FIELD=environment; else ENV_FIELD=content; fi
        ENV_FIELD="$ENV_FIELD" python3 -c "
import json, os, sys
content = open(sys.argv[1]).read()
print(json.dumps({os.environ['ENV_FIELD']: content}))
" "$UPDATED" > /tmp/forge-env-payload.json

        RESP=$(mktemp)
        if [ "$API_VERSION" = "current" ]; then
            CODE=$(curl -sS -X PUT -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
                -H "Content-Type: application/json" --max-time 30 \
                -d @/tmp/forge-env-payload.json -o "$RESP" -w '%{http_code}' "$SITE_PATH/environment")
        else
            CODE=$(curl -sS -X PUT -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
                -H "Content-Type: application/json" --max-time 30 \
                -d @/tmp/forge-env-payload.json -o "$RESP" -w '%{http_code}' "$SITE_PATH/env")
        fi
        rm -f /tmp/forge-env-payload.json

        case "$CODE" in
            2*) ;;
            *)  echo "FAILED (HTTP $CODE): $(head -c 300 "$RESP")" >&2; rm -f "$RESP"; exit 1 ;;
        esac
        rm -f "$RESP"

        sleep 3  # the PUT is applied asynchronously; give it a moment before verifying
        # Into a variable, then an exact whole-line match. A pipe into `grep -q`
        # dies of EPIPE under pipefail once the env outgrows the pipe buffer,
        # and matching only "^KEY=" would call an update "verified" when the API
        # kept the OLD value — the line exists either way.
        READBACK=$("$0" get-env)
        if grep -qxF -- "$KEY=$VALUE" <<<"$READBACK"; then
            echo "set $KEY (verified, $(wc -l < "$UPDATED") lines total)"
        else
            echo "FAILED: HTTP $CODE accepted the write but $KEY does not hold the new value on read-back" >&2
            exit 1
        fi
        ;;
    get-nginx)
        # Prints the site's live nginx config to stdout.
        if [ "$API_VERSION" = "current" ]; then
            api GET "$SITE_PATH/nginx" | python3 -c "
import json, sys
raw = sys.stdin.read()
try:
    sys.stdout.write(json.loads(raw)['data']['attributes']['content'])
except (ValueError, KeyError, TypeError):
    sys.stdout.write(raw)"
        else
            api GET "$SITE_PATH/nginx" 
        fi
        ;;
    set-nginx)
        # set-nginx FILE — replace the site's nginx config.
        #
        # This is the most dangerous write in this script: a bad config takes
        # hive.contractors offline for everyone. Forge runs `nginx -t` and
        # refuses invalid syntax, but syntax is not the risk — a VALID config
        # that dropped the php location, the FORGE CONFIG includes, or the
        # server_name would pass nginx -t and still break the site. So the
        # structural checks below run first, the current config is saved, and
        # the site is fetched afterwards to confirm it still serves. If it does
        # not, the backup is restored automatically.
        FILE="${2:?usage: set-nginx FILE}"
        [ -s "$FILE" ] || { echo "Refusing: $FILE is empty or missing." >&2; exit 1; }

        for needle in \
            'server_name hive.contractors' \
            'root /home/forge/hive.contractors/public' \
            'fastcgi_pass unix:/var/run/php/' \
            'FORGE CONFIG'
        do
            grep -qF -- "$needle" "$FILE" || {
                echo "Refusing: $FILE is missing \"$needle\" — that is not a complete site config." >&2
                exit 1
            }
        done

        BACKUP="/tmp/forge-nginx-backup-$(date -u +%Y%m%d%H%M%S).conf"
        "$0" get-nginx > "$BACKUP"
        [ -s "$BACKUP" ] || { echo "Refusing: could not read the current config to back it up." >&2; exit 1; }
        echo "backed up current config -> $BACKUP ($(wc -l < "$BACKUP") lines)"

        # Payload shape is not the same across Forge endpoints — the environment
        # endpoint wants {"environment": ...} while GET returns data.attributes.
        # Try the documented shapes in order rather than guessing once.
        RESP=$(mktemp)
        CODE=""
        for field in config content nginx_config nginx; do
            field="$field" python3 -c "
import json, os, sys
print(json.dumps({os.environ['field']: open(sys.argv[1]).read()}))
" "$FILE" > /tmp/forge-nginx-payload.json
            CODE=$(curl -sS -X PUT -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
                -H "Content-Type: application/json" --max-time 60 \
                -d @/tmp/forge-nginx-payload.json -o "$RESP" -w '%{http_code}' "$SITE_PATH/nginx")
            case "$CODE" in
                2*) echo "accepted with field \"$field\" (HTTP $CODE)"; break ;;
                422) continue ;;
                *) break ;;
            esac
        done
        rm -f /tmp/forge-nginx-payload.json

        case "$CODE" in
            2*) ;;
            *)  echo "FAILED (HTTP $CODE): $(head -c 300 "$RESP")" >&2
                echo "The live config was NOT changed. Backup remains at $BACKUP" >&2
                rm -f "$RESP"; exit 1 ;;
        esac
        rm -f "$RESP"

        echo "waiting for nginx to reload…"
        sleep 8

        # Does the site still serve? This is the check that matters.
        LIVE=$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 https://hive.contractors/login || echo 000)
        case "$LIVE" in
            200|302) echo "site still serving (HTTP $LIVE) — config applied" ;;
            *)  echo "SITE IS NOT SERVING (HTTP $LIVE) — restoring the backup" >&2
                python3 -c "
import json, sys
print(json.dumps({'content': open(sys.argv[1]).read()}))
" "$BACKUP" > /tmp/forge-nginx-rollback.json
                curl -sS -X PUT -H "Authorization: Bearer $TOKEN" -H "Accept: application/json" \
                    -H "Content-Type: application/json" --max-time 60 \
                    -d @/tmp/forge-nginx-rollback.json -o /dev/null "$SITE_PATH/nginx"
                rm -f /tmp/forge-nginx-rollback.json
                sleep 8
                echo "rolled back; site now: $(curl -s -o /dev/null -w '%{http_code}' --max-time 20 https://hive.contractors/login)" >&2
                exit 1 ;;
        esac
        ;;
    *)
        sed -n '2,18p' "$0"; exit 1
        ;;
esac
