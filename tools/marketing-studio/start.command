#!/bin/bash
# Mowology Marketing Studio — double-click to start (or run from Terminal).
cd "/Users/timschofield/Projects/mowology-crm/tools/marketing-studio"
PORT=${STUDIO_PORT:-8740}
if lsof -iTCP:$PORT -sTCP:LISTEN >/dev/null 2>&1; then
  echo "Studio already running on port $PORT"
else
  nohup python3 server.py > state/server.log 2>&1 &
  sleep 1
fi
open "http://127.0.0.1:$PORT/"
echo "Marketing Studio → http://127.0.0.1:$PORT/   (log: state/server.log)"
