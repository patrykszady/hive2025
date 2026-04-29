#!/bin/bash

# Unified startup script for all four Laravel apps
# GSC (8003), Breck (8002), Hive2025 (8000), Test (8005)
#
# Run once from ANY terminal (VS Code or CMD). Both share the same server.
# Re-running shows status of already-running services instead of duplicating.

cd "$(dirname "$0")"

# Load local development env overrides (not committed secrets).
for DEV_ENV_FILE in ".env.dev" ".env.dev.local"; do
  if [ -f "$DEV_ENV_FILE" ]; then
    set -a
    # shellcheck disable=SC1090
    . "$DEV_ENV_FILE"
    set +a
  fi
done

LOG_DIR="storage/logs/dev"
LOCK_FILE="/tmp/hive-dev-server.lock"
mkdir -p "$LOG_DIR"

# ── Flags ────────────────────────────────────────────────────────────────────
FORCE=false
STATUS_ONLY=false
for arg in "$@"; do
  case "$arg" in
    --force|-f)  FORCE=true ;;
    --status|-s) STATUS_ONLY=true ;;
  esac
done

# ── Status helper ────────────────────────────────────────────────────────────
show_status() {
  echo ""
  echo "Services status:"
  for PORT_LABEL in "8000:Hive2025" "8002:Breck" "8003:GSC" "8005:Test" "5173:Vite"; do
    PORT="${PORT_LABEL%%:*}"
    LABEL="${PORT_LABEL##*:}"
    if lsof -Pi :"$PORT" -sTCP:LISTEN -t >/dev/null 2>&1; then
      echo "  ✅ $LABEL (port $PORT) — running"
    else
      echo "  ⬚  $LABEL (port $PORT) — not running"
    fi
  done
  pgrep -x meilisearch >/dev/null 2>&1 && echo "  ✅ Meilisearch — running" || echo "  ⬚  Meilisearch — not running"
  pgrep -f "artisan horizon" >/dev/null 2>&1 && echo "  ✅ Horizon — running" || echo "  ⬚  Horizon — not running"
  pgrep -f "artisan reverb:start" >/dev/null 2>&1 && echo "  ✅ Reverb — running" || echo "  ⬚  Reverb — not running"
  pgrep -f "cloudflared tunnel" >/dev/null 2>&1 && echo "  ✅ Cloudflare tunnel — running" || echo "  ⬚  Cloudflare tunnel — not running"
  echo ""
}

if [ "$STATUS_ONLY" = true ]; then
  show_status
  exit 0
fi

# ── Lock file: prevent duplicate full startups ──────────────────────────────
# The lock file stores the PID of the main Laravel server.
# If that process is still alive, we consider the dev env running.
if [ "$FORCE" = false ] && [ -f "$LOCK_FILE" ]; then
  # Check if the main Hive server (port 8000) is actually running
  if lsof -Pi :8000 -sTCP:LISTEN -t >/dev/null 2>&1; then
    echo "════════════════════════════════════════════════════════════════"
    echo "ℹ️  Dev environment already running"
    echo "════════════════════════════════════════════════════════════════"
    show_status
    echo "To force restart: ./start-dev.sh --force"
    exit 0
  else
    # Stale lock file (services not actually running), remove it
    rm -f "$LOCK_FILE"
  fi
fi

# Remove stale lock on force restart
[ "$FORCE" = true ] && rm -f "$LOCK_FILE"

# On --force, kill existing cloudflared so it restarts fresh below
if [ "$FORCE" = true ] && pgrep -f "cloudflared tunnel" >/dev/null 2>&1; then
  echo "🔄 Stopping existing Cloudflare tunnel (force restart)..."
  pkill -f "cloudflared tunnel" 2>/dev/null || true
  sleep 0.5
fi

echo "🚀 Starting all Laravel applications..."
echo ""

# Helper to show status
status() {
  if [ "$1" -eq 0 ]; then
    echo "✅ $2"
  else
    echo "❌ $3"
  fi
}

# ==============================================================================
# SHARED SERVICES (Redis, Meilisearch)
# ==============================================================================
echo "════════════════════════════════════════════════════════════════"
echo "🔧 Starting Shared Services"
echo "════════════════════════════════════════════════════════════════"

# Clear Laravel caches
echo "🧹 Clearing Laravel caches..."
php artisan config:clear --no-interaction >"$LOG_DIR/config_clear.log" 2>&1
status $? "Config cache cleared" "Config cache clear failed (see $LOG_DIR/config_clear.log)"

# MySQL
echo "🔄 Starting MySQL..."
sudo service mysql start >/dev/null 2>&1
status $? "MySQL started (or already running)" "Failed to start MySQL"

# Redis
echo "🔄 Starting Redis..."
sudo service redis-server start >/dev/null 2>&1
status $? "Redis started (or already running)" "Failed to start Redis"

# Meilisearch
MEILI_HOST=$(grep -E '^MEILISEARCH_HOST=' .env 2>/dev/null | cut -d '=' -f2- | tr -d '\r' | xargs)
MEILI_KEY=$(grep -E '^MEILISEARCH_KEY=' .env 2>/dev/null | sed -E "s/^MEILISEARCH_KEY=['\"]?([^'\"]*)['\"]?/\1/" | tr -d '\r' | xargs)
MEILI_BIN="$(command -v meilisearch 2>/dev/null)"
if [ -z "$MEILI_BIN" ] && [ -x "$HOME/.local/bin/meilisearch" ]; then
  MEILI_BIN="$HOME/.local/bin/meilisearch"
fi
MEILI_HOST=${MEILI_HOST:-http://127.0.0.1:7700}
MEILI_HTTP_ADDR=$(echo "$MEILI_HOST" | sed -E 's#^https?://##')

if pgrep -x meilisearch >/dev/null 2>&1; then
  echo "ℹ️  Meilisearch already running → $MEILI_HOST"
else
  echo "🔄 Starting Meilisearch at $MEILI_HOST..."
  if [ -z "$MEILI_BIN" ]; then
    echo "❌ Meilisearch binary not found in PATH or ~/.local/bin/meilisearch"
  else
    if [ -n "$MEILI_KEY" ]; then
      nohup "$MEILI_BIN" --http-addr "$MEILI_HTTP_ADDR" --master-key "$MEILI_KEY" >"$LOG_DIR/meilisearch.log" 2>&1 &
    else
      nohup "$MEILI_BIN" --http-addr "$MEILI_HTTP_ADDR" >"$LOG_DIR/meilisearch.log" 2>&1 &
    fi
    MEILI_PID=$!
    sleep 1.2
    if curl -fsS "$MEILI_HOST/health" >/dev/null 2>&1; then
      echo "✅ Meilisearch healthy (pid: $MEILI_PID) → $MEILI_HOST"
    else
      echo "❌ Meilisearch not healthy at $MEILI_HOST → check $LOG_DIR/meilisearch.log"
    fi
  fi
fi

echo ""

# ==============================================================================
# GSC (Port 8003)
# ==============================================================================
GSC_DIR="/home/patryk/web/gsc"
if [ ! -d "$GSC_DIR" ]; then
  echo "❌ gsc directory not found at $GSC_DIR"
else
  echo "════════════════════════════════════════════════════════════════"
  echo "📦 Starting GSC (Port 8003)"
  echo "════════════════════════════════════════════════════════════════"
  if lsof -Pi :8003 -sTCP:LISTEN -t >/dev/null 2>&1; then
    GSC_PID=$(lsof -Pi :8003 -sTCP:LISTEN -t)
    echo "✅ GSC server already running (pid: $GSC_PID)"
  else
    (cd "$GSC_DIR" && nohup php artisan serve --host=127.0.0.1 --port=8003 --no-interaction > storage/logs/serve.log 2>&1 &)
    sleep 0.7
    if lsof -Pi :8003 -sTCP:LISTEN -t >/dev/null 2>&1; then
      echo "✅ GSC Laravel server started on port 8003"
    else
      echo "❌ GSC Laravel server failed to start"
    fi
  fi
fi

echo ""

# ==============================================================================
# BRECK (Port 8002)
# ==============================================================================
BRECK_DIR="/home/patryk/web/breck"
if [ ! -d "$BRECK_DIR" ]; then
  echo "❌ breck directory not found at $BRECK_DIR"
else
  echo "════════════════════════════════════════════════════════════════"
  echo "📦 Starting BRECK (Port 8002)"
  echo "════════════════════════════════════════════════════════════════"
  if lsof -Pi :8002 -sTCP:LISTEN -t >/dev/null 2>&1; then
    BRECK_PID=$(lsof -Pi :8002 -sTCP:LISTEN -t)
    echo "✅ Breck server already running (pid: $BRECK_PID)"
  else
    if [ -f "$BRECK_DIR/start-dev.sh" ]; then
      (cd "$BRECK_DIR" && ./start-dev.sh)
    elif [ -f "$BRECK_DIR/.wsl-startup" ]; then
      echo "ℹ️  Using .wsl-startup script for breck"
      (cd "$BRECK_DIR" && source .wsl-startup)
    else
      (cd "$BRECK_DIR" && nohup php artisan serve --host=0.0.0.0 --port=8002 --no-interaction > storage/logs/serve.log 2>&1 &)
      sleep 0.7
      if lsof -Pi :8002 -sTCP:LISTEN -t >/dev/null 2>&1; then
        echo "✅ Breck Laravel server started on port 8002"
      else
        echo "❌ Breck Laravel server failed to start"
      fi
    fi
  fi
fi

echo ""

# ==============================================================================
# TEST (Port 8005)
# ==============================================================================
TEST_DIR="/home/patryk/web/test"
if [ ! -d "$TEST_DIR" ]; then
  echo "❌ test directory not found at $TEST_DIR"
else
  echo "════════════════════════════════════════════════════════════════"
  echo "📦 Starting TEST (Port 8005)"
  echo "════════════════════════════════════════════════════════════════"
  if lsof -Pi :8005 -sTCP:LISTEN -t >/dev/null 2>&1; then
    TEST_PID=$(lsof -Pi :8005 -sTCP:LISTEN -t)
    echo "✅ Test server already running (pid: $TEST_PID)"
  else
    (cd "$TEST_DIR" && nohup php artisan serve --host=0.0.0.0 --port=8005 --no-interaction > storage/logs/serve.log 2>&1 &)
    sleep 0.7
    if lsof -Pi :8005 -sTCP:LISTEN -t >/dev/null 2>&1; then
      echo "✅ Test Laravel server started on port 8005"
    else
      echo "❌ Test Laravel server failed to start"
    fi
  fi
fi

echo ""

# ==============================================================================
# HIVE2025 (Port 8000)
# ==============================================================================
echo "════════════════════════════════════════════════════════════════"
echo "📦 Starting HIVE2025 (Port 8000)"
echo "════════════════════════════════════════════════════════════════"

# Horizon
echo "🔄 Starting Laravel Horizon..."
nohup php artisan horizon --no-interaction >"$LOG_DIR/horizon.log" 2>&1 &
HORIZON_PID=$!
sleep 0.7
if ps -p "$HORIZON_PID" >/dev/null 2>&1; then
  echo "✅ Horizon started (pid: $HORIZON_PID)"
else
  echo "❌ Horizon failed → check logs: $LOG_DIR/horizon.log"
fi

# Reverb (WebSocket server for real-time broadcasting)
if pgrep -f "artisan reverb:start" >/dev/null 2>&1; then
  REVERB_PID=$(pgrep -f "artisan reverb:start" | head -n 1)
  echo "✅ Reverb already running (pid: $REVERB_PID)"
else
  echo "🔄 Starting Laravel Reverb..."
  nohup php artisan reverb:start --no-interaction >"$LOG_DIR/reverb.log" 2>&1 &
  REVERB_PID=$!
  sleep 1
  if ps -p "$REVERB_PID" >/dev/null 2>&1; then
    echo "✅ Reverb started (pid: $REVERB_PID) on port 8080"
  else
    echo "❌ Reverb failed → check logs: $LOG_DIR/reverb.log"
  fi
fi

# Laravel dev server
if lsof -Pi :8000 -sTCP:LISTEN -t >/dev/null 2>&1; then
  SERVE_PID=$(lsof -Pi :8000 -sTCP:LISTEN -t)
  echo "✅ Laravel server already running (pid: $SERVE_PID)"
else
  echo "🔄 Starting Laravel dev server..."
  nohup php artisan serve --host=0.0.0.0 --port=8000 --no-interaction >"$LOG_DIR/serve.log" 2>&1 &
  SERVE_PID=$!
  sleep 0.7
  if ps -p "$SERVE_PID" >/dev/null 2>&1; then
    echo "✅ Laravel server started (pid: $SERVE_PID)"
  else
    echo "❌ Laravel server failed → check logs: $LOG_DIR/serve.log"
  fi
fi

# Write lock file now that the main server is up
echo "$SERVE_PID" > "$LOCK_FILE"

# Vite dev server
if [ -f public/hot ]; then
  HOT_URL=$(cat public/hot 2>/dev/null | tr -d '\r')
  HOT_PORT=$(echo "$HOT_URL" | sed -nE 's#^https?://[^:]+:([0-9]+).*$#\1#p')
  if [ -n "$HOT_PORT" ] && ! lsof -Pi :"$HOT_PORT" -sTCP:LISTEN -t >/dev/null 2>&1; then
    echo "ℹ️  Removing stale Vite hot file ($HOT_URL)"
    rm -f public/hot
  fi
fi

if lsof -Pi :5173 -sTCP:LISTEN -t >/dev/null 2>&1; then
  VITE_PID=$(lsof -Pi :5173 -sTCP:LISTEN -t)
  echo "✅ Vite already running (pid: $VITE_PID)"
else
  echo "🔄 Starting Vite (npm run dev)..."
  nohup npm run dev -- --host 0.0.0.0 --port 5173 --strictPort >"$LOG_DIR/vite.log" 2>&1 &
  VITE_PID=$!
  sleep 0.7
  if ps -p "$VITE_PID" >/dev/null 2>&1; then
    echo "✅ Vite dev server started (pid: $VITE_PID)"
  else
    echo "❌ Vite failed → check logs: $LOG_DIR/vite.log"
  fi
fi

    echo ""

    # ==============================================================================
    # CLOUDFLARE TUNNEL (dev.hive.contractors)
    # ==============================================================================
    echo "════════════════════════════════════════════════════════════════"
    echo "🌐 Starting Cloudflare Tunnel (dev.hive.contractors)"
    echo "════════════════════════════════════════════════════════════════"

    CLOUDFLARED_CONFIG="/home/patryk/web/hive2025/cloudflared-config.yml"
    CLOUDFLARED_BIN="$(command -v cloudflared 2>/dev/null)"
    if [ -z "$CLOUDFLARED_BIN" ] && [ -x "$HOME/.local/bin/cloudflared" ]; then
      CLOUDFLARED_BIN="$HOME/.local/bin/cloudflared"
    fi

    CLOUDFLARE_TUNNEL_ID="${CLOUDFLARE_TUNNEL_ID:-2f84efc0-98d3-42b6-8ddf-c53d0bb0ce27}"
    CLOUDFLARE_CREDENTIALS_FILE="$HOME/.cloudflared/${CLOUDFLARE_TUNNEL_ID}.json"

    if [ -z "$CLOUDFLARED_BIN" ]; then
      echo "❌ cloudflared not found in PATH or ~/.local/bin/cloudflared"
    elif pgrep -f "cloudflared tunnel" >/dev/null 2>&1; then
      TUNNEL_PID=$(pgrep -f "cloudflared tunnel" | head -n 1)
      echo "✅ Cloudflare tunnel already running (pid: $TUNNEL_PID)"
    else
      echo "🔄 Starting Cloudflare tunnel..."
      LAUNCHED_TUNNEL=false
      if [ -f "$CLOUDFLARE_CREDENTIALS_FILE" ]; then
        nohup "$CLOUDFLARED_BIN" tunnel --config "$CLOUDFLARED_CONFIG" run >"$LOG_DIR/cloudflared.log" 2>&1 &
        LAUNCHED_TUNNEL=true
      elif [ -n "${CLOUDFLARE_TUNNEL_TOKEN:-}" ]; then
        nohup "$CLOUDFLARED_BIN" tunnel run --token "$CLOUDFLARE_TUNNEL_TOKEN" >"$LOG_DIR/cloudflared.log" 2>&1 &
        LAUNCHED_TUNNEL=true
      else
        echo "❌ Missing tunnel credentials: expected $CLOUDFLARE_CREDENTIALS_FILE"
        echo "   Set CLOUDFLARE_TUNNEL_TOKEN or restore the credentials JSON file."
      fi

      if [ "$LAUNCHED_TUNNEL" = true ]; then
        TUNNEL_PID=$!
        sleep 0.7
        if ps -p "$TUNNEL_PID" >/dev/null 2>&1; then
          echo "✅ Cloudflare tunnel started (pid: $TUNNEL_PID)"
        else
          echo "❌ Cloudflare tunnel failed → check logs: $LOG_DIR/cloudflared.log"
        fi
      fi
    fi

echo ""

# Hookdeck tunnel (optional)
HOOKDECK_URL=""
HOOKDECK_BIN="$(command -v hookdeck 2>/dev/null)"
if [ -z "$HOOKDECK_BIN" ]; then
  HOOKDECK_BIN=$(ls "$HOME"/.nvm/versions/node/*/bin/hookdeck 2>/dev/null | tail -n1)
fi

if [ -n "$HOOKDECK_BIN" ]; then
  echo "🔄 Starting Hookdeck tunnel..."
  HOOKDECK_LOG="$LOG_DIR/hookdeck.log"

  "$HOOKDECK_BIN" connection upsert nylas-local \
    --source-name nylas --source-type WEBHOOK \
    --destination-name nylas-local-cli --destination-type CLI \
    --destination-cli-path /webhooks/nylas >>"$HOOKDECK_LOG" 2>&1

  nohup "$HOOKDECK_BIN" listen 8000 nylas nylas-local \
    --path /webhooks/nylas \
    --output compact >>"$HOOKDECK_LOG" 2>&1 &
  HOOKDECK_PID=$!

  # Mailtrap webhook tunnel
  "$HOOKDECK_BIN" connection upsert mailtrap-local \
    --source-name mailtrap --source-type WEBHOOK \
    --destination-name mailtrap-local-cli --destination-type CLI \
    --destination-cli-path / >>"$HOOKDECK_LOG" 2>&1

  MAILTRAP_TOKEN=$(grep -E '^MAILTRAP_WEBHOOK_TOKEN=' .env 2>/dev/null | cut -d '=' -f2- | tr -d '\r' | xargs)
  if [ -z "$MAILTRAP_TOKEN" ]; then
    MAILTRAP_TOKEN="dev-mailtrap-webhook-token"
  fi

  nohup "$HOOKDECK_BIN" listen 8000 mailtrap mailtrap-local \
    --path "/" \
    --output compact >>"$HOOKDECK_LOG" 2>&1 &
  HOOKDECK_MAILTRAP_PID=$!

  # Public app URL (HTTP source supports GET)
  echo "APP_HTTP_LISTENER_START" >>"$HOOKDECK_LOG"
  "$HOOKDECK_BIN" connection upsert app-local \
    --source-name http --source-type HTTP \
    --source-allowed-methods GET,POST,PUT,PATCH,DELETE \
    --destination-name app-local-cli --destination-type CLI \
    --destination-cli-path /l >>"$HOOKDECK_LOG" 2>&1

  nohup "$HOOKDECK_BIN" listen 8000 http app-local \
    --path "/l" \
    --output compact >>"$HOOKDECK_LOG" 2>&1 &
  HOOKDECK_APP_PID=$!
  sleep 2
  
  if ps -p "$HOOKDECK_PID" >/dev/null 2>&1; then
    if [ -f "$HOOKDECK_LOG" ]; then
      HOOKDECK_URL=$(grep -o 'https://[^ ]*' "$HOOKDECK_LOG" | head -1)
    fi
    if [ -n "$HOOKDECK_URL" ]; then
      echo "✅ Hookdeck tunnel started → $HOOKDECK_URL"
    else
      echo "✅ Hookdeck tunnel started → logs: $HOOKDECK_LOG"
    fi
  else
    echo "❌ Hookdeck tunnel failed → check logs: $HOOKDECK_LOG"
  fi

  if ps -p "${HOOKDECK_MAILTRAP_PID:-0}" >/dev/null 2>&1; then
    if [ -f "$HOOKDECK_LOG" ]; then
      HOOKDECK_MAILTRAP_URL=$(grep -o 'https://hkdk\.events/[^ ]*' "$HOOKDECK_LOG" | tail -1)
    fi
    if [ -n "$HOOKDECK_MAILTRAP_URL" ]; then
      echo "✅ Mailtrap webhook URL: ${HOOKDECK_MAILTRAP_URL}/webhooks/mailtrap/${MAILTRAP_TOKEN}"
    else
      echo "✅ Mailtrap Hookdeck listener started"
    fi
  fi

  if ps -p "${HOOKDECK_APP_PID:-0}" >/dev/null 2>&1; then
    if [ -f "$HOOKDECK_LOG" ]; then
      HOOKDECK_APP_URL=$(awk '/APP_HTTP_LISTENER_START/{found=1} found && /https:\/\/hkdk\.events\// {match($0, /https:\/\/hkdk\.events\/[^ ]+/); print substr($0, RSTART, RLENGTH); exit}' "$HOOKDECK_LOG")
    fi
    if [ -n "$HOOKDECK_APP_URL" ]; then
      echo "✅ Public app URL: ${HOOKDECK_APP_URL}"
      echo "ℹ️  Set DEV_WEBHOOK_URL=${HOOKDECK_APP_URL} if needed"
    else
      echo "✅ Hookdeck app HTTP listener started"
    fi
  fi
else
  echo "ℹ️  Hookdeck CLI not found, skipping tunnel setup"
fi

echo ""
echo "════════════════════════════════════════════════════════════════"
echo "🎉 All applications started!"
echo "════════════════════════════════════════════════════════════════"
echo "📍 GSC:      http://127.0.0.1:8003"
echo "📍 Breck:    http://127.0.0.1:8002"
echo "📍 Hive2025: http://127.0.0.1:8000"
echo "📍 Test:     http://127.0.0.1:8005"
echo "📍 Vite:     http://127.0.0.1:5173"
echo "════════════════════════════════════════════════════════════════"
