#!/usr/bin/env bash
# ─────────────────────────────────────────────────────────────────────────────
# Lightweight, root-free origin monitor to catch the conditions behind
# Cloudflare 525 (TLS handshake failed) on hive.contractors.
#
# It samples, every INTERVAL seconds:
#   - deltas of TCP counters that indicate handshake-stage packet loss / accept
#     queue overflow (the classic edge->origin 525 cause):
#       ListenDrops, ListenOverflows, TCPReqQFullDrop, TCPSynRetrans, RetransSegs
#   - a local TLS handshake probe to 127.0.0.1:443 (SNI hive.contractors): time + ok/fail
#   - 1-min load average and current ESTABLISHED :443 socket count
#
# Every interval is written to the log as a CSV line. Any interval where a drop
# counter increments OR the handshake fails/exceeds SLOW_MS is ALSO appended to
# the alerts file and tagged ALERT — those are the lines to correlate against the
# timestamps of 525s in the Cloudflare dashboard.
#
# No sudo, no service changes, read-only. Auto-stops after MAX_RUNTIME.
# Usage:   nohup ./525-origin-monitor.sh >/dev/null 2>&1 &
# Stop:    kill <pid>     (pid is printed at start and saved to $PIDFILE)
# ─────────────────────────────────────────────────────────────────────────────
set -u

INTERVAL="${INTERVAL:-10}"            # seconds between samples
SLOW_MS="${SLOW_MS:-500}"             # handshake slower than this => ALERT
MAX_RUNTIME="${MAX_RUNTIME:-172800}"  # 48h hard stop
HOST="${HOST:-hive.contractors}"
PORT="${PORT:-443}"
LOG="${LOG:-$HOME/525-monitor.log}"
ALERTS="${ALERTS:-$HOME/525-monitor.alerts.log}"
PIDFILE="${PIDFILE:-$HOME/525-monitor.pid}"

echo $$ > "$PIDFILE"
START_EPOCH=$(date +%s)

# Read a single counter value from /proc/net/snmp or /proc/net/netstat by
# section (Tcp / TcpExt) and column name.
read_counter() {
  local section="$1" name="$2" file
  case "$section" in
    Tcp)    file=/proc/net/snmp ;;
    TcpExt) file=/proc/net/netstat ;;
  esac
  awk -v sec="$section" -v key="$name" '
    $1==sec":" {
      if (hdr=="") { for (i=2;i<=NF;i++) col[$i]=i; hdr=$0; next }
      else { print $(col[key]); exit }
    }' "$file" 2>/dev/null
}

snapshot() {
  LD=$(read_counter TcpExt ListenDrops)
  LO=$(read_counter TcpExt ListenOverflows)
  RQ=$(read_counter TcpExt TCPReqQFullDrop)
  SR=$(read_counter TcpExt TCPSynRetrans)
  RS=$(read_counter Tcp RetransSegs)
  LD=${LD:-0}; LO=${LO:-0}; RQ=${RQ:-0}; SR=${SR:-0}; RS=${RS:-0}
}

handshake_probe() {
  local t0 t1
  t0=$(date +%s%3N)
  if timeout 5 openssl s_client -connect 127.0.0.1:${PORT} -servername "$HOST" \
        </dev/null >/dev/null 2>&1; then
    t1=$(date +%s%3N)
    HS_OK=1; HS_MS=$(( t1 - t0 ))
  else
    t1=$(date +%s%3N)
    HS_OK=0; HS_MS=$(( t1 - t0 ))
  fi
}

est443() {
  ss -tan state established "( sport = :${PORT} )" 2>/dev/null | awk 'NR>1' | wc -l
}

# CSV header (only if file is new/empty)
if [ ! -s "$LOG" ]; then
  echo "ts,dListenDrops,dListenOverflows,dReqQFullDrop,dSynRetrans,dRetransSegs,hs_ok,hs_ms,load1,est443,flag" >> "$LOG"
fi

snapshot
pLD=$LD; pLO=$LO; pRQ=$RQ; pSR=$SR; pRS=$RS

while :; do
  now=$(date +%s)
  [ $(( now - START_EPOCH )) -ge "$MAX_RUNTIME" ] && { echo "$(date -Is) monitor auto-stopped (MAX_RUNTIME)" >> "$LOG"; break; }

  snapshot
  dLD=$(( LD - pLD )); dLO=$(( LO - pLO )); dRQ=$(( RQ - pRQ )); dSR=$(( SR - pSR )); dRS=$(( RS - pRS ))
  pLD=$LD; pLO=$LO; pRQ=$RQ; pSR=$SR; pRS=$RS

  handshake_probe
  load1=$(cut -d' ' -f1 /proc/loadavg)
  conns=$(est443)
  ts=$(date -Is)

  flag=""
  if [ "$dLD" -gt 0 ] || [ "$dLO" -gt 0 ] || [ "$dRQ" -gt 0 ]; then flag="ALERT:queue-drop"; fi
  if [ "$HS_OK" -ne 1 ]; then flag="${flag:+$flag,}ALERT:handshake-fail"; fi
  if [ "$HS_MS" -gt "$SLOW_MS" ]; then flag="${flag:+$flag,}ALERT:handshake-slow"; fi

  line="$ts,$dLD,$dLO,$dRQ,$dSR,$dRS,$HS_OK,$HS_MS,$load1,$conns,${flag:-ok}"
  echo "$line" >> "$LOG"
  [ -n "$flag" ] && echo "$line" >> "$ALERTS"

  sleep "$INTERVAL"
done
