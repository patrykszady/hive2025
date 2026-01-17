#!/bin/bash

# Unified startup script for all four Laravel apps
# GSC (8003), Breck (8002), Hive2025 (8000), Test (8005)

cd "$(dirname "$0")"

LOG_DIR="storage/logs/dev"
mkdir -p "$LOG_DIR"

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

# Redis
echo "🔄 Starting Redis..."
sudo service redis-server start >/dev/null 2>&1
status $? "Redis started (or already running)" "Failed to start Redis"

# Meilisearch
MEILI_HOST=$(grep -E '^MEILISEARCH_HOST=' .env 2>/dev/null | cut -d '=' -f2- | tr -d '\r' | xargs)
MEILI_KEY=$(grep -E '^MEILISEARCH_KEY=' .env 2>/dev/null | sed -E "s/^MEILISEARCH_KEY=['\"]?([^'\"]*)['\"]?/\1/" | tr -d '\r' | xargs)
MEILI_HOST=${MEILI_HOST:-http://127.0.0.1:7700}
MEILI_HTTP_ADDR=$(echo "$MEILI_HOST" | sed -E 's#^https?://##')

if pgrep -x meilisearch >/dev/null 2>&1; then
  echo "ℹ️  Meilisearch already running → $MEILI_HOST"
else
  echo "🔄 Starting Meilisearch at $MEILI_HOST..."
  if [ -n "$MEILI_KEY" ]; then
    nohup meilisearch --http-addr "$MEILI_HTTP_ADDR" --master-key "$MEILI_KEY" >"$LOG_DIR/meilisearch.log" 2>&1 &
  else
    nohup meilisearch --http-addr "$MEILI_HTTP_ADDR" >"$LOG_DIR/meilisearch.log" 2>&1 &
  fi
  MEILI_PID=$!
  sleep 1.2
  if curl -fsS "$MEILI_HOST/health" >/dev/null 2>&1; then
    echo "✅ Meilisearch healthy (pid: $MEILI_PID) → $MEILI_HOST"
  else
    echo "❌ Meilisearch not healthy at $MEILI_HOST → check $LOG_DIR/meilisearch.log"
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
      (cd "$BRECK_DIR" && nohup php artisan serve --host=127.0.0.1 --port=8002 --no-interaction > storage/logs/serve.log 2>&1 &)
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
    (cd "$TEST_DIR" && nohup php artisan serve --host=127.0.0.1 --port=8005 --no-interaction > storage/logs/serve.log 2>&1 &)
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

# Laravel dev server
if lsof -Pi :8000 -sTCP:LISTEN -t >/dev/null 2>&1; then
  SERVE_PID=$(lsof -Pi :8000 -sTCP:LISTEN -t)
  echo "✅ Laravel server already running (pid: $SERVE_PID)"
else
  echo "🔄 Starting Laravel dev server..."
  nohup php artisan serve --host=127.0.0.1 --port=8000 --no-interaction >"$LOG_DIR/serve.log" 2>&1 &
  SERVE_PID=$!
  sleep 0.7
  if ps -p "$SERVE_PID" >/dev/null 2>&1; then
    echo "✅ Laravel server started (pid: $SERVE_PID)"
  else
    echo "❌ Laravel server failed → check logs: $LOG_DIR/serve.log"
  fi
fi

# Vite dev server
if lsof -Pi :5173 -sTCP:LISTEN -t >/dev/null 2>&1; then
  VITE_PID=$(lsof -Pi :5173 -sTCP:LISTEN -t)
  echo "✅ Vite already running (pid: $VITE_PID)"
else
  echo "🔄 Starting Vite (npm run dev)..."
  nohup npm run dev >"$LOG_DIR/vite.log" 2>&1 &
  VITE_PID=$!
  sleep 0.7
  if ps -p "$VITE_PID" >/dev/null 2>&1; then
    echo "✅ Vite dev server started (pid: $VITE_PID)"
  else
    echo "❌ Vite failed → check logs: $LOG_DIR/vite.log"
  fi
fi

# Ngrok tunnels (for webhooks on all 3 apps)
# Uses ~/.config/ngrok/ngrok.yml which defines tunnels: hive (8000), breck (8002), gsc (8003), test (8005)
NGROK_BIN="$(command -v ngrok 2>/dev/null)"
if [ -n "$NGROK_BIN" ]; then
  # Kill any existing ngrok processes to ensure clean state
  if pgrep -x ngrok >/dev/null 2>&1; then
    echo "🔄 Restarting Ngrok tunnels (killing existing)..."
    pkill -x ngrok 2>/dev/null
    sleep 1
  fi

  echo "🔄 Starting Ngrok tunnels for all apps (hive:8000, breck:8002, gsc:8003, test:8005)..."
  nohup "$NGROK_BIN" start --all >"$LOG_DIR/ngrok.log" 2>&1 &
  NGROK_PID=$!
  sleep 3

  if ps -p "$NGROK_PID" >/dev/null 2>&1; then
    # Fetch all tunnel URLs from ngrok API
    TUNNELS_JSON=$(curl -s http://127.0.0.1:4040/api/tunnels 2>/dev/null)

    # Extract URLs using jq if available, fallback to grep-based extraction
    if command -v jq >/dev/null 2>&1; then
      HIVE_URL=$(echo "$TUNNELS_JSON" | jq -r '.tunnels[] | select(.config.addr | contains(":8000")) | .public_url' 2>/dev/null | head -1)
      BRECK_URL=$(echo "$TUNNELS_JSON" | jq -r '.tunnels[] | select(.config.addr | contains(":8002")) | .public_url' 2>/dev/null | head -1)
      GSC_URL=$(echo "$TUNNELS_JSON" | jq -r '.tunnels[] | select(.config.addr | contains(":8003")) | .public_url' 2>/dev/null | head -1)
      TEST_URL=$(echo "$TUNNELS_JSON" | jq -r '.tunnels[] | select(.config.addr | contains(":8005")) | .public_url' 2>/dev/null | head -1)
    else
      # Fallback: extract by tunnel name
      HIVE_URL=$(echo "$TUNNELS_JSON" | grep -o '"name":"hive"[^}]*"public_url":"https://[^"]*' | grep -o 'https://[^"]*' | head -1)
      BRECK_URL=$(echo "$TUNNELS_JSON" | grep -o '"name":"breck"[^}]*"public_url":"https://[^"]*' | grep -o 'https://[^"]*' | head -1)
      GSC_URL=$(echo "$TUNNELS_JSON" | grep -o '"name":"gsc"[^}]*"public_url":"https://[^"]*' | grep -o 'https://[^"]*' | head -1)
      TEST_URL=$(echo "$TUNNELS_JSON" | grep -o '"name":"test"[^}]*"public_url":"https://[^"]*' | grep -o 'https://[^"]*' | head -1)
    fi

    # Function to update DEV_WEBHOOK_URL in a .env file
    update_env_webhook() {
      local ENV_FILE="$1"
      local NEW_URL="$2"
      local APP_NAME="$3"
      if [ -f "$ENV_FILE" ] && [ -n "$NEW_URL" ]; then
        if grep -q '^DEV_WEBHOOK_URL=' "$ENV_FILE" 2>/dev/null; then
          sed -i "s|^DEV_WEBHOOK_URL=.*|DEV_WEBHOOK_URL=$NEW_URL|" "$ENV_FILE"
        else
          echo "DEV_WEBHOOK_URL=$NEW_URL" >> "$ENV_FILE"
        fi
        echo "   ✅ $APP_NAME → $NEW_URL"
      fi
    }

    echo "✅ Ngrok tunnels started:"
    update_env_webhook "/home/patryk/web/hive2025/.env" "$HIVE_URL" "Hive (8000)"
    update_env_webhook "/home/patryk/web/breck/.env" "$BRECK_URL" "Breck (8002)"
    update_env_webhook "/home/patryk/web/gsc/.env" "$GSC_URL" "GSC (8003)"
    update_env_webhook "/home/patryk/web/test/.env" "$TEST_URL" "Test (8005)"

    # Also update HIVE_URL in this .env for local reference
    if [ -n "$HIVE_URL" ]; then
      CURRENT_DEV_URL=$(grep -E '^DEV_WEBHOOK_URL=' .env 2>/dev/null | cut -d '=' -f2- | tr -d '\r' | xargs)
      if [ "$CURRENT_DEV_URL" != "$HIVE_URL" ]; then
        sed -i "s|^DEV_WEBHOOK_URL=.*|DEV_WEBHOOK_URL=$HIVE_URL|" .env
      fi
    fi
  else
    echo "❌ Ngrok failed to start → check logs: $LOG_DIR/ngrok.log"
    echo "   Tip: Make sure ~/.config/ngrok/ngrok.yml exists with tunnel definitions"
  fi
else
  echo "ℹ️  Ngrok not found, skipping tunnel setup (vendor SMS links will use local URL)"
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
if [ -n "$HIVE_URL" ]; then
  echo "📍 Hive Ngrok: $HIVE_URL"
fi
if [ -n "$BRECK_URL" ]; then
  echo "📍 Breck Ngrok: $BRECK_URL"
fi
if [ -n "$GSC_URL" ]; then
  echo "📍 GSC Ngrok: $GSC_URL"
fi
echo "════════════════════════════════════════════════════════════════"
