#!/usr/bin/env bash
# deploy-fingerprints.sh — remember the live version a deploy was built from.
# Why: parallel chats must not overwrite production changes they have never pulled. Tim 2026-10-24.

DEPLOY_STATE_DIR="${DEPLOY_STATE_DIR:-${HOME}/.mowology-deploy}"
DEPLOY_FINGERPRINT_LOG="$DEPLOY_STATE_DIR/fingerprints.tsv"
DEPLOY_LOCK_DIR="$DEPLOY_STATE_DIR/deploy.lock"

fp_lock_acquire() {
  local lock_pid
  mkdir -p "$DEPLOY_STATE_DIR" || return 1
  while ! mkdir "$DEPLOY_LOCK_DIR" 2>/dev/null; do
    lock_pid="$(cat "$DEPLOY_LOCK_DIR/pid" 2>/dev/null || true)"
    if [ -n "$lock_pid" ] && ! kill -0 "$lock_pid" 2>/dev/null; then
      rm -rf "$DEPLOY_LOCK_DIR"
      continue
    fi
    sleep "${DEPLOY_LOCK_POLL_SECONDS:-1}"
  done
  printf '%s\n' "$$" > "$DEPLOY_LOCK_DIR/pid" || {
    rmdir "$DEPLOY_LOCK_DIR" 2>/dev/null || true
    return 1
  }
}

fp_lock_release() {
  local lock_pid
  lock_pid="$(cat "$DEPLOY_LOCK_DIR/pid" 2>/dev/null || true)"
  if [ "$lock_pid" = "$$" ]; then
    rm -f "$DEPLOY_LOCK_DIR/pid"
    rmdir "$DEPLOY_LOCK_DIR" 2>/dev/null || true
  fi
}

fp_record() {
  local fp_path="$1" fp_file="$2" fp_event="$3" fp_sha fp_who
  mkdir -p "$DEPLOY_STATE_DIR" || return 1
  if [ "$fp_file" = "MISSING" ]; then
    fp_sha="MISSING"
  else
    fp_sha="$(shasum -a 256 "$fp_file" | awk '{print $1}')" || return 1
  fi
  fp_who="$(basename "$0") + ${USER:-unknown}"
  [ "${DEPLOY_FORCE:-0}" = "1" ] && fp_who="$fp_who FORCED"
  printf '%s\t%s\t%s\t%s\t%s\n' \
    "$(date -u '+%Y-%m-%dT%H:%M:%SZ')" "$fp_path" "$fp_sha" "$fp_event" "$fp_who" \
    >> "$DEPLOY_FINGERPRINT_LOG"
}

fp_last_record() {
  local fp_path="$1"
  [ -f "$DEPLOY_FINGERPRINT_LOG" ] || return 0
  awk -F '\t' -v wanted="$fp_path" '$2 == wanted { line=$0 } END { if (line != "") print line }' \
    "$DEPLOY_FINGERPRINT_LOG"
}

fp_last() {
  local fp_path="$1"
  fp_last_record "$fp_path" | awk -F '\t' '{print $3}'
}

# Pure comparison: the log is the only input besides path and the already-computed live sha.
fp_decide() {
  local fp_path="$1" fp_live="$2" fp_seen
  if [ "${DEPLOY_FORCE:-0}" = "1" ]; then
    echo "ALLOW"
    return
  fi
  fp_seen="$(fp_last "$fp_path")"
  if [ -z "$fp_seen" ]; then
    echo "REFUSE-NEVER"
  elif [ "$fp_seen" != "$fp_live" ]; then
    echo "REFUSE-CHANGED"
  else
    echo "ALLOW"
  fi
}
