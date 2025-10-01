#!/bin/bash

# Always run from project root
cd "$(dirname "$0")"

LOG_DIR="storage/logs/dev"
mkdir -p "$LOG_DIR"

echo "🚀 Starting Laravel dev environment..."

# Helper to show status
status() {
  if [ "$1" -eq 0 ]; then
    echo "✅ $2"
  else
    echo "❌ $3"
  fi
}

# 0) Clear Laravel caches to re-read .env
echo "🧹 Clearing Laravel caches..."
nohup php artisan config:clear --no-interaction >"$LOG_DIR/config_clear.log" 2>&1 &
wait $!
status $? "Config cache cleared" "Config cache clear failed (see $LOG_DIR/config_clear.log)"

# 1) Redis
echo "🔄 Starting Redis..."
sudo service redis-server start >/dev/null 2>&1
status $? "Redis started (or already running)" "Failed to start Redis"

# 2) Meilisearch (use host/key from .env)
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
  # Wait briefly and health-check
  sleep 1.2
  if curl -fsS "$MEILI_HOST/health" >/dev/null 2>&1; then
    echo "✅ Meilisearch healthy (pid: $MEILI_PID) → $MEILI_HOST (logs: $LOG_DIR/meilisearch.log)"
  else
    echo "❌ Meilisearch not healthy at $MEILI_HOST → check $LOG_DIR/meilisearch.log"
  fi
fi

# 3) Horizon
echo "🔄 Starting Laravel Horizon..."
nohup php artisan horizon --no-interaction >"$LOG_DIR/horizon.log" 2>&1 &
HORIZON_PID=$!
sleep 0.7
if ps -p "$HORIZON_PID" >/dev/null 2>&1; then
  echo "✅ Horizon started (pid: $HORIZON_PID) → logs: $LOG_DIR/horizon.log"
else
  echo "❌ Horizon failed → check logs: $LOG_DIR/horizon.log"
fi

# 4) Laravel dev server
echo "🔄 Starting Laravel dev server (http://127.0.0.1:8000)..."
nohup php artisan serve --host=127.0.0.1 --port=8000 --no-interaction >"$LOG_DIR/serve.log" 2>&1 &
SERVE_PID=$!
sleep 0.7
if ps -p "$SERVE_PID" >/dev/null 2>&1; then
  echo "✅ Laravel server started (pid: $SERVE_PID) → logs: $LOG_DIR/serve.log"
else
  echo "❌ Laravel server failed → check logs: $LOG_DIR/serve.log"
fi

# 5) Vite dev server (npm run dev)
echo "🔄 Starting Vite (npm run dev)..."
nohup npm run dev >"$LOG_DIR/vite.log" 2>&1 &
VITE_PID=$!
sleep 0.7
if ps -p "$VITE_PID" >/dev/null 2>&1; then
  echo "✅ Vite dev server started (pid: $VITE_PID, default http://127.0.0.1:5173) → logs: $LOG_DIR/vite.log"
else
  echo "❌ Vite failed → check logs: $LOG_DIR/vite.log"
fi

# 6) ngrok tunnel (optional)
if command -v ngrok >/dev/null 2>&1; then
  echo "🔄 Starting ngrok tunnel..."
  nohup ngrok http 8000 >"$LOG_DIR/ngrok.log" 2>&1 &
  NGROK_PID=$!
  sleep 2
  if ps -p "$NGROK_PID" >/dev/null 2>&1; then
    # Try to extract the public URL from ngrok API
    NGROK_URL=$(curl -s http://127.0.0.1:4040/api/tunnels 2>/dev/null | grep -o '"public_url":"https://[^"]*' | cut -d'"' -f4 | head -1)
    if [ -n "$NGROK_URL" ]; then
      echo "✅ ngrok tunnel started (pid: $NGROK_PID) → $NGROK_URL"
      echo "   • Web interface: http://127.0.0.1:4040"
    else
      echo "✅ ngrok tunnel started (pid: $NGROK_PID) → logs: $LOG_DIR/ngrok.log"
    fi
  else
    echo "❌ ngrok failed → check logs: $LOG_DIR/ngrok.log"
    NGROK_PID=""
  fi
else
  echo "ℹ️  ngrok not found, skipping tunnel setup"
  NGROK_PID=""
fi

echo "🎉 Done! Services running:"
echo "   • Redis ✅"
echo "   • Meilisearch ✅ → $MEILI_HOST"
echo "   • Horizon pid: ${HORIZON_PID:-n/a}"
echo "   • PHP server pid: ${SERVE_PID:-n/a} → http://127.0.0.1:8000"
echo "   • Vite pid: ${VITE_PID:-n/a} → http://127.0.0.1:5173"
if [ -n "$NGROK_PID" ]; then
  echo "   • ngrok pid: $NGROK_PID → ${NGROK_URL:-'check http://127.0.0.1:4040'}"
fi