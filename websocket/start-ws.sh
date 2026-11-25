#!/usr/bin/env bash
set -euo pipefail
ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT_DIR"
nohup php "$ROOT_DIR/websocket/sso-realtime-server.php" > "$ROOT_DIR/websocket/ws.log" 2>&1 &
echo "Ilmuna SSO WebSocket Daemon dimulai (PID: $!)"
