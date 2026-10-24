#!/usr/bin/env bash
# =============================================================================
# scripts/codex-review.sh — an independent Codex (OpenAI) code review on a clean copy of the code.
# =============================================================================
# Tim, 2026-10-14: Codex reviews, Claude edits. A trial on the Android camera found 5 real defects
# Claude had missed. Codex only ever sees CODE:
#   1. the given paths are copied into a temp folder: code files only — no docs, no tests,
#      no .md/.sql/.csv/.json data, no secrets.php / app_config;
#   2. every client / company / property name in the live CRM (fetched fresh with prod-run.sh,
#      never written to the repo) is replaced by [client], comments included;
#   3. it stops if anything looking like a password, key or token literal remains;
#   4. `codex exec` runs read-only (no edits, no shell writes) and writes its findings.
# Claude then checks every finding against the real code before anything is fixed.
#
# Usage:
#   scripts/codex-review.sh <name> <prompt-file> <path> [<path> …]
#   paths: files or directories, relative to the repo root, or absolute (e.g. the Android repo).
#   Findings: $CODEX_OUT (default /tmp/codex-review/<name>.md). Exit 3 = secret found, nothing sent.
#   Exit 75 = Codex usage used up: hand the same review to the code-reviewer Claude agent.
# =============================================================================
set -euo pipefail
cd "$(git rev-parse --show-toplevel)"

NAME="${1:?usage: codex-review.sh <name> <prompt-file> <path>…}"; shift
PROMPT_FILE="${1:?prompt file}"; shift
[ $# -gt 0 ] || { echo "give at least one path" >&2; exit 2; }
[ -f "$PROMPT_FILE" ] || { echo "no prompt file: $PROMPT_FILE" >&2; exit 2; }
command -v codex >/dev/null || { echo "codex CLI not installed (npm i -g @openai/codex)" >&2; exit 2; }

BASE="${TMPDIR:-/tmp}/codex-review"
WORK="$BASE/$NAME"
OUT="${CODEX_OUT:-$BASE/$NAME.md}"
rm -rf "$WORK"; mkdir -p "$WORK"

# ── 1. Code files only ────────────────────────────────────────────────────────
CODE_EXT='php|js|ts|kt|java|swift|css|html|sh|py|gradle|xml|htaccess'   # .htaccess: access rules are code
copied=0
for p in "$@"; do
  if [ -d "$p" ]; then
    root="$p"
    while IFS= read -r f; do
      rel="${f#"$root"/}"
      dest="$WORK/$(basename "$root")/$rel"
      mkdir -p "$(dirname "$dest")"; cp "$f" "$dest"; copied=$((copied + 1))
    done < <(find "$p" -type f | grep -E "\.($CODE_EXT)$" \
             | grep -vE '/(tests?|Tests?|docs?|vendor|node_modules|build|uploads|downloads|app_config)/|\.min\.(js|css)$|secrets\.php$|\.test\.')
  elif [ -f "$p" ]; then
    echo "$p" | grep -qE "\.($CODE_EXT)$" || { echo "skipping non-code file $p" >&2; continue; }
    dest="$WORK/files/$(echo "$p" | sed 's#^/##')"
    mkdir -p "$(dirname "$dest")"; cp "$p" "$dest"; copied=$((copied + 1))
  else
    echo "no such path: $p" >&2; exit 2
  fi
done
[ "$copied" -gt 0 ] || { echo "nothing to review" >&2; exit 2; }

# ── 2–3. Client names out, then secrets stop rather than send ─────────────────
. "$(dirname "$0")/lib/codex-sanitize.sh"
SANITIZE_REPO="$(pwd)"
sanitize_names "$WORK" || exit 3
sanitize_secret_scan "$WORK" || exit 3

echo "reviewing $copied file(s) in $WORK"
# ── 4. Codex, read-only ───────────────────────────────────────────────────────
# Exit 75 = Codex usage used up (scripts/lib/codex-run.sh): send this review to the code-reviewer Claude agent instead.
. "$(dirname "$0")/lib/codex-run.sh"
rc=0
CODEX_CALLER="codex-review.sh $NAME" codex_run "$BASE/$NAME.log" --sandbox read-only --skip-git-repo-check --ephemeral -C "$WORK" -o "$OUT" "$(cat "$PROMPT_FILE")" || rc=$?
if [ "$rc" = "$CODEX_LIMIT_RC" ]; then echo "CODEX LIMIT — use the code-reviewer agent (same prompt, same files)"; exit "$rc"; fi
[ "$rc" = 0 ] || { echo "codex failed (rc=$rc) — see $BASE/$NAME.log" >&2; exit "$rc"; }
echo "findings: $OUT"
