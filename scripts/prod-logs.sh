#!/usr/bin/env bash
#
# Read production's logs from a dev machine, over SSH.
#
#   scripts/prod-logs.sh                      # list available logs, newest first
#   scripts/prod-logs.sh menards              # last 50 lines of today's menards log
#   scripts/prod-logs.sh menards -f           # follow it live
#   scripts/prod-logs.sh menards -n 200       # last 200 lines
#   scripts/prod-logs.sh menards -g Imperva   # only lines matching a pattern
#   scripts/prod-logs.sh laravel -n 100
#   scripts/prod-logs.sh --pull menards       # copy today's file here to read offline
#
# A channel name is resolved to today's dated file (menards -> menards-<date>.log)
# and falls back to the undated name (laravel -> laravel.log), so the caller does
# not have to know which convention a given channel uses.
#
# Reaches the server through the `hive-prod` ssh host. Nothing is written on the
# far side: every mode here is read-only.
set -euo pipefail

HOST="${PROD_SSH_HOST:-hive-prod}"
REMOTE_DIR="${PROD_LOG_DIR:-~/hive.contractors/storage/logs}"

CHANNEL="${1:-}"
shift || true

FOLLOW=0
LINES=50
GREP=""
PULL=0

if [ "$CHANNEL" = "--pull" ]; then
    PULL=1
    CHANNEL="${1:?usage: --pull CHANNEL}"
    shift || true
fi

while [ $# -gt 0 ]; do
    case "$1" in
        -f|--follow) FOLLOW=1; shift ;;
        -n|--lines)  LINES="${2:?-n needs a number}"; shift 2 ;;
        -g|--grep)   GREP="${2:?-g needs a pattern}"; shift 2 ;;
        *) echo "unknown option: $1" >&2; exit 1 ;;
    esac
done

if [ -z "$CHANNEL" ]; then
    echo "Logs on production (newest first):"
    ssh "$HOST" "cd $REMOTE_DIR && ls -lt *.log 2>/dev/null | head -25 | awk '{printf \"  %-40s %8s  %s %s %s\n\", \$9, \$5, \$6, \$7, \$8}'"
    echo
    echo "Then: scripts/prod-logs.sh <channel> [-f] [-n N] [-g PATTERN]"
    exit 0
fi

# Resolve the channel to a real filename ON THE SERVER, where the date is the
# server's (UTC) rather than this machine's — a dev box an hour behind would
# otherwise ask for a file that does not exist yet.
RESOLVE="cd $REMOTE_DIR && for f in ${CHANNEL}-\$(date -u +%Y-%m-%d).log ${CHANNEL}.log ${CHANNEL}-\$(date +%Y-%m-%d).log; do [ -f \"\$f\" ] && { echo \"\$f\"; break; }; done"
FILE="$(ssh "$HOST" "$RESOLVE" || true)"

if [ -z "$FILE" ]; then
    echo "No log matching '$CHANNEL' on production. Available:" >&2
    ssh "$HOST" "cd $REMOTE_DIR && ls -1 *.log 2>/dev/null | head -25" >&2
    exit 1
fi

if [ "$PULL" = 1 ]; then
    DEST="storage/logs/prod-$FILE"
    mkdir -p storage/logs
    scp "$HOST:$REMOTE_DIR/$FILE" "$DEST"
    echo "$DEST"
    exit 0
fi

CMD="cd $REMOTE_DIR && "
if [ "$FOLLOW" = 1 ]; then
    CMD+="tail -n $LINES -f '$FILE'"
else
    CMD+="tail -n $LINES '$FILE'"
fi
[ -n "$GREP" ] && CMD+=" | grep --line-buffered -i -- '$GREP'"

echo "── production: $FILE ──" >&2
# -t only when there is a real terminal: it lets Ctrl-C reach the remote tail
# instead of orphaning it, but asking for a pty without one makes ssh warn on
# every piped call ("Pseudo-terminal will not be allocated…").
if [ -t 0 ] && [ -t 1 ]; then
    exec ssh -t "$HOST" "$CMD"
fi
exec ssh "$HOST" "$CMD"
