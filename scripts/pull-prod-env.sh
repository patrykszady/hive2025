#!/usr/bin/env bash
# Bring the central admin's connection credentials from production's .env into
# this machine's, so the dev admin's Platforms and SEO screens show what
# production has (Google sign-in, Search Console, Bing, Clarity, PageSpeed,
# DataForSEO, Meta, the linked Business Profile).
#
# An ALLOWLIST, unlike gs.construction's and jpeterson-design's copies of this
# script (which take everything but a keep-local list). This app holds live
# texting, banking, mailbox and payment credentials, and dev runs the scheduler
# over production's customers after every db:pull-production — with those
# values in place, dev would send real texts and read real mailboxes. Only the
# keys below ever come across; add one here when a new admin connection needs
# it, never a wildcard.
#
#   bash scripts/pull-prod-env.sh --dry-run    # list what would change
#   bash scripts/pull-prod-env.sh              # apply, after a backup
set -euo pipefail

REMOTE_HOST="${HIVE_PROD_HOST:-hive-prod}"
REMOTE_PATH="${HIVE_PROD_PATH:-/home/forge/hive.contractors}"
LOCAL_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ENV_FILE="$LOCAL_ROOT/.env"
DRY_RUN=0
[ "${1:-}" = "--dry-run" ] && DRY_RUN=1

# The ONLY keys taken from production.
ALLOW="GOOGLE_OAUTH_CLIENT_ID GOOGLE_OAUTH_CLIENT_SECRET GOOGLE_OAUTH_PROJECT_ID
GOOGLE_BUSINESS_PROFILE_ACCOUNT_ID GOOGLE_BUSINESS_PROFILE_LOCATION_ID GOOGLE_BUSINESS_PROFILE_PLACE_ID
GSC_CREDENTIALS GSC_PROPERTY
BING_WMT_KEY BING_WMT_SITE_URL
CLARITY_PROJECT_ID CLARITY_API_TOKEN
PAGESPEED_API_KEY
DATAFORSEO_LOGIN DATAFORSEO_PASSWORD
META_APP_ID META_APP_SECRET META_ENABLED"

[ -f "$ENV_FILE" ] || { echo "No .env at $ENV_FILE"; exit 1; }

REMOTE_ENV="$(mktemp)"; MERGED="$(mktemp)"
trap 'rm -f "$REMOTE_ENV" "$MERGED"' EXIT
ssh -o ConnectTimeout=10 -o BatchMode=yes "$REMOTE_HOST" "cat $REMOTE_PATH/.env" > "$REMOTE_ENV"
[ -s "$REMOTE_ENV" ] || { echo "Could not read production's .env from $REMOTE_HOST:$REMOTE_PATH"; exit 1; }

ALLOW="$ALLOW" ENV_FILE="$ENV_FILE" REMOTE_ENV="$REMOTE_ENV" DRY_RUN="$DRY_RUN" \
python3 - "$MERGED" <<'PY'
import os, sys, hashlib

allow = set(os.environ['ALLOW'].split())

def read(p):
    out = []
    for line in open(p):
        s = line.rstrip('\n')
        if s.strip() and not s.lstrip().startswith('#') and '=' in s:
            out.append((s.split('=', 1)[0].strip(), s))
        else:
            out.append((None, s))
    return out

local, remote = read(os.environ['ENV_FILE']), read(os.environ['REMOTE_ENV'])
lmap = {k: v for k, v in local if k}
rmap = {k: v for k, v in remote if k and k in allow}

def val(line): return line.split('=', 1)[1].strip(' "\'')

changed, added = [], []
out = []
for k, line in local:                       # keep local file order and comments
    if k in rmap:
        if val(line) != val(rmap[k]): changed.append(k)
        out.append(rmap[k])
    else:
        out.append(line)
missing = [k for k in sorted(rmap) if k not in lmap]
if missing:
    out.append('')
    out.append('# From production by scripts/pull-prod-env.sh (admin connection credentials only).')
    for k in missing:
        out.append(rmap[k]); added.append(k)

print(f"  {len(changed)} values updated from production:")
for k in sorted(changed): print(f"     {k}")
print(f"  {len(added)} keys added:")
for k in added: print(f"     {k}")
absent = sorted(allow - set(rmap))
print(f"  {len(absent)} allowed keys production does not set: {' '.join(absent) if absent else '-'}")

if os.environ['DRY_RUN'] != '1':
    open(sys.argv[1], 'w').write('\n'.join(out) + '\n')
PY

if [ "$DRY_RUN" = "1" ]; then
    echo; echo "Dry run — nothing written."
    exit 0
fi

BACKUP="$ENV_FILE.backup-$(date +%Y%m%d-%H%M%S)"
cp "$ENV_FILE" "$BACKUP"
cp "$MERGED" "$ENV_FILE"
echo; echo "✓ .env updated. Previous file kept at $(basename "$BACKUP")."
echo "  Run 'php artisan config:clear', and restart this app's dev server (artisan serve keeps its start-time .env)."
