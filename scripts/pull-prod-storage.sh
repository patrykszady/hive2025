#!/usr/bin/env bash
# Pull production storage to local dev for testing.
#
# Usage: ./scripts/pull-prod-storage.sh [hive|gsc] [--delete] [--full]
#   First arg picks the site (default: hive).
#     hive  → /home/forge/hive.contractors  (this repo)
#     gsc   → /home/forge/gs.construction
#   --delete  Remove local files that no longer exist on the server.
#   --full    Sync the entire storage/ tree (logs, framework caches, etc.).
#             Default syncs only storage/app (user uploads & public files).

set -euo pipefail

REMOTE_HOST="${HIVE_PROD_HOST:-hive-prod}"

TARGET="hive"
EXTRA_FLAGS=()
SUBPATH="storage/app/"

for arg in "$@"; do
    case "$arg" in
        hive) TARGET="hive" ;;
        gsc|gs) TARGET="gsc" ;;
        --delete) EXTRA_FLAGS+=(--delete) ;;
        --full)   SUBPATH="storage/" ;;
        -h|--help)
            sed -n '2,11p' "$0"
            exit 0
            ;;
        *)
            echo "Unknown option: $arg" >&2
            exit 1
            ;;
    esac
done

case "$TARGET" in
    hive)
        REMOTE_PATH="${HIVE_PROD_PATH:-/home/forge/hive.contractors}"
        LOCAL_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
        ;;
    gsc)
        REMOTE_PATH="${GSC_PROD_PATH:-/home/forge/gs.construction}"
        # Sync into a sibling folder so we don't clobber this repo's storage.
        LOCAL_ROOT="${GSC_LOCAL_ROOT:-$HOME/web/gs.construction}"
        if [ ! -d "$LOCAL_ROOT" ]; then
            echo "Local path $LOCAL_ROOT does not exist." >&2
            echo "Set GSC_LOCAL_ROOT to your local gs.construction checkout, or create that directory." >&2
            exit 1
        fi
        ;;
esac

cd "$LOCAL_ROOT"
mkdir -p "$SUBPATH"

echo "→ Pulling ${REMOTE_HOST}:${REMOTE_PATH}/${SUBPATH}"
echo "  into ${LOCAL_ROOT}/${SUBPATH}"

rsync -avz --human-readable --progress \
    --exclude='framework/cache/data/*' \
    --exclude='framework/sessions/*' \
    --exclude='framework/views/*' \
    --exclude='framework/testing/*' \
    --exclude='logs/*.log' \
    --exclude='debugbar/*' \
    --exclude='clockwork/*' \
    "${EXTRA_FLAGS[@]}" \
    "${REMOTE_HOST}:${REMOTE_PATH}/${SUBPATH}" \
    "./${SUBPATH}"

echo "✓ Done."
