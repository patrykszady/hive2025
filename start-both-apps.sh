#!/bin/bash

# Unified startup script for both hive2025 and breck apps
# This script runs the individual start-dev.sh scripts for each app

echo "🚀 Starting both Laravel applications..."
echo ""

# ==============================================================================
# BRECK (Port 8002)
# ==============================================================================
BRECK_DIR="/home/patryk/web/breck"
if [ ! -d "$BRECK_DIR" ]; then
  echo "❌ breck directory not found at $BRECK_DIR"
else
  echo "════════════════════════════════════════════════════════════════"
  echo "📦 Starting BRECK"
  echo "════════════════════════════════════════════════════════════════"
  if [ -f "$BRECK_DIR/start-dev.sh" ]; then
    (cd "$BRECK_DIR" && ./start-dev.sh)
  elif [ -f "$BRECK_DIR/.wsl-startup" ]; then
    echo "ℹ️  Using .wsl-startup script for breck"
    (cd "$BRECK_DIR" && source .wsl-startup)
  else
    echo "❌ No startup script found in $BRECK_DIR"
  fi
fi

echo ""
echo ""

# ==============================================================================
# HIVE2025 (Port 8000)
# ==============================================================================
HIVE_DIR="/home/patryk/web/hive2025"
if [ ! -d "$HIVE_DIR" ]; then
  echo "❌ hive2025 directory not found at $HIVE_DIR"
else
  echo "════════════════════════════════════════════════════════════════"
  echo "📦 Starting HIVE2025"
  echo "════════════════════════════════════════════════════════════════"
  if [ -f "$HIVE_DIR/start-dev.sh" ]; then
    (cd "$HIVE_DIR" && ./start-dev.sh)
  else
    echo "❌ start-dev.sh not found in $HIVE_DIR"
  fi
fi

echo ""
echo "════════════════════════════════════════════════════════════════"
echo "🎉 Both applications started!"
echo "════════════════════════════════════════════════════════════════"
echo "📍 Breck:    http://127.0.0.1:8002"
echo "📍 Hive2025: http://127.0.0.1:8000"
echo "════════════════════════════════════════════════════════════════"
