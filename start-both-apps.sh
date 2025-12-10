#!/bin/bash

# Unified startup script for both hive2025 and breck apps
# This script starts both Laravel applications on their respective ports

echo "🚀 Starting both Laravel applications..."
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
# HIVE2025 (Port 8000)
# ==============================================================================
echo "📦 Starting hive2025 on port 8000..."

HIVE_DIR="/home/patryk/web/hive2025"
if [ ! -d "$HIVE_DIR" ]; then
  echo "❌ hive2025 directory not found at $HIVE_DIR"
else
  cd "$HIVE_DIR"
  
  # Check if already running
  if lsof -Pi :8000 -sTCP:LISTEN -t >/dev/null 2>&1; then
    HIVE_PID=$(lsof -Pi :8000 -sTCP:LISTEN -t)
    echo "ℹ️  hive2025 already running (pid: $HIVE_PID) → http://127.0.0.1:8000"
  else
    # Start the Laravel server for hive2025
    mkdir -p storage/logs/dev
    nohup php artisan serve --host=127.0.0.1 --port=8000 --no-interaction >storage/logs/dev/serve.log 2>&1 &
    HIVE_PID=$!
    sleep 0.7
    if ps -p "$HIVE_PID" >/dev/null 2>&1; then
      echo "✅ hive2025 started (pid: $HIVE_PID) → http://127.0.0.1:8000"
    else
      echo "❌ hive2025 failed → check logs: storage/logs/dev/serve.log"
    fi
  fi
fi

echo ""

# ==============================================================================
# BRECK (Port 8002)
# ==============================================================================
echo "📦 Starting breck on port 8002..."

BRECK_DIR="/home/patryk/web/breck"
if [ ! -d "$BRECK_DIR" ]; then
  echo "❌ breck directory not found at $BRECK_DIR"
else
  cd "$BRECK_DIR"
  
  # Check if already running
  if lsof -Pi :8002 -sTCP:LISTEN -t >/dev/null 2>&1; then
    BRECK_PID=$(lsof -Pi :8002 -sTCP:LISTEN -t)
    echo "ℹ️  breck already running (pid: $BRECK_PID) → http://127.0.0.1:8002"
  else
    # Start the Laravel server for breck
    mkdir -p storage/logs/dev
    nohup php artisan serve --host=127.0.0.1 --port=8002 --no-interaction >storage/logs/dev/serve.log 2>&1 &
    BRECK_PID=$!
    sleep 0.7
    if ps -p "$BRECK_PID" >/dev/null 2>&1; then
      echo "✅ breck started (pid: $BRECK_PID) → http://127.0.0.1:8002"
    else
      echo "❌ breck failed → check logs: storage/logs/dev/serve.log"
    fi
  fi
fi

echo ""
echo "🎉 Startup complete!"
echo ""
echo "📍 hive2025:  http://127.0.0.1:8000"
echo "📍 breck:     http://127.0.0.1:8002"
