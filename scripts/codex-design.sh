#!/usr/bin/env bash
# =============================================================================
# scripts/codex-design.sh — Ittai design studio: Codex builds a design round, the machine checks it, Claude art-directs.
# =============================================================================
# Claude is the Creative Director (writes the brief, critiques, decides). Codex is the visual designer: it writes HTML/CSS/SVG
# into out/ only. This script then renders every file at 375/768/1024/1440 in light/dark/field and runs the v1.2 checks
# (scripts/ittai-design/review-shots.mjs). It never touches the repo: designs stay in ~/.mowology-codex/design-studio/<name>/
# until Claude applies them. Inputs are the design system only (tokens.css, spec, component previews, symbol) plus --ref files
# you name — never app code, never client data. Briefs must use synthetic data. (Like the other codex-*.sh scripts, the
# sandbox limits Codex's WRITES to the round dir; it does not stop it reading elsewhere on this Mac.)
#
# Usage: scripts/codex-design.sh <name> <brief.md> [--ref <file-or-dir> ...] [--round N]
#   Round N defaults to the next unused number. From round 2 the previous round's out/ and machine-check findings are
#   copied in as previous/ and previous-checks.md, and the brief is a revision brief ("fix these, keep the rest").
# Exit: 0 round built, all checks pass · 2 built but checks fail (see round-N/checks.md) · 3 setup/safety stop · 75 Codex limit.
#   On 75 hand the SAME brief to the web-builder agent (.claude/agents/) and say so.
# Output: ~/.mowology-codex/design-studio/<name>/round-N/{out/,shots/contact-sheet.html,checks.md,summary.md,codex.log}
# =============================================================================
set -uo pipefail
REPO="$(git rev-parse --show-toplevel)"; cd "$REPO"
# shellcheck source=lib/codex-run.sh
. "$REPO/scripts/lib/codex-run.sh"

NAME="${1:-}"; BRIEF="${2:-}"; [ $# -ge 2 ] && shift 2
[ -n "$NAME" ] && [ -f "$BRIEF" ] || { echo "usage: codex-design.sh <name> <brief.md> [--ref <path>…] [--round N]" >&2; exit 3; }
case "$NAME" in *[!A-Za-z0-9._-]*|""|.|..|.*) echo "name: letters, digits . _ - only, not starting with a dot" >&2; exit 3 ;; esac
REFS=(); ROUND=""
while [ $# -gt 0 ]; do
  case "$1" in
    --ref) { [ $# -ge 2 ] && [ -e "$2" ]; } || { echo "no such --ref: ${2:-}" >&2; exit 3; }; REFS+=("$2"); shift 2 ;;
    --round) { [ $# -ge 2 ] && [[ "$2" =~ ^[1-9][0-9]*$ ]]; } || { echo "--round needs a positive number" >&2; exit 3; }; ROUND="$2"; shift 2 ;;
    *) echo "unknown argument: $1" >&2; exit 3 ;;
  esac
done
command -v codex >/dev/null || { echo "codex CLI not installed" >&2; exit 3; }
command -v node >/dev/null || { echo "node not installed" >&2; exit 3; }

DS="$REPO/docs/design/ittai-v1.2"
[ -f "$DS/tokens.json" ] || { echo "missing $DS/tokens.json — is this the right branch (feature/language)?" >&2; exit 3; }
BASE="${CODEX_DESIGN_HOME:-$HOME/.mowology-codex/design-studio}/$NAME"
if [ -z "$ROUND" ]; then ROUND=1; while [ -d "$BASE/round-$ROUND" ]; do ROUND=$((ROUND + 1)); done; fi
R="$BASE/round-$ROUND"; [ ! -e "$R" ] || { echo "$R already exists" >&2; exit 3; }
mkdir -p "$R/out" "$R/shots" "$R/refs"

# ── Inputs: design system only ───────────────────────────────────────────────
node "$REPO/scripts/ittai-design/build-tokens.mjs" >/dev/null || { echo "tokens.css build failed" >&2; exit 3; }
cp "$DS/tokens.css" "$R/tokens.css"
cp "$DS/spec/SPEC-v1.2.md" "$R/SPEC-v1.2.md"
cp -R "$DS/cd-source" "$R/cd-source"
cp -R "$DS/assets" "$R/assets"                      # the frozen symbol: reference only, never redraw
cp "$REPO/.claude/skills/ittai-design-studio/references/v1.2-rules.md" "$R/RULES.md" 2>/dev/null \
  || { echo "missing .claude/skills/ittai-design-studio/references/v1.2-rules.md" >&2; exit 3; }
for p in ${REFS[@]+"${REFS[@]}"}; do cp -R "$p" "$R/refs/"; done
if [ "$ROUND" -gt 1 ]; then
  PREV="$BASE/round-$((ROUND - 1))"
  [ -d "$PREV/out" ] || { echo "no previous round at $PREV" >&2; exit 3; }
  cp -R "$PREV/out" "$R/previous"
  [ -f "$PREV/checks.md" ] && cp "$PREV/checks.md" "$R/previous-checks.md"
fi
# Safety: refuse secrets or obvious private data in anything Codex will read.
if grep -rIEl "(sk-[A-Za-z0-9]{20,}|AKIA[0-9A-Z]{16}|-----BEGIN [A-Z ]*PRIVATE KEY|password[[:space:]]*[:=][[:space:]]*['\"][^'\"]{6,})" "$R" "$BRIEF" >/dev/null 2>&1; then
  echo "STOP: something that looks like a secret is in the inputs — nothing sent." >&2; rm -rf "$R"; rmdir "$BASE" 2>/dev/null; exit 3
fi

# ── Prompt ───────────────────────────────────────────────────────────────────
PROMPT="$R/PROMPT.md"
{
  echo "You are the specialist visual designer in the Ittai design studio. The Creative Director's brief follows the standing rules."
  echo
  echo "STANDING RULES (non-negotiable)"
  echo "- Read RULES.md, SPEC-v1.2.md and cd-source/README.md first. Follow them exactly."
  echo "- Write ONLY inside out/. Plain HTML + CSS + SVG, no frameworks, no network except the Google Fonts Inter stylesheet."
  echo "- Every HTML file links ../tokens.css and uses its CSS variables for EVERY colour. Never write a hex, rgb() or hsl() literal."
  echo "- Show light, dark and field modes by the data-theme / data-mode attributes on <html> (field wins); do not duplicate pages per mode."
  echo "- Must work at 375, 768, 1024 and 1440 px wide with no sideways scroll, no text under 12 px, touch targets 48 px (under 1024) / 44 px (desktop), main action 56 px."
  echo "- Synthetic data only (invented names, 123 Example Street). The Ittai symbol is FROZEN: use assets/symbol/*.svg as is, never redraw or recolour it."
  echo "- Finish with out/NOTES.md: what you built, every file, anything you could not do or are unsure about."
  echo
  if [ "$ROUND" -gt 1 ]; then
    echo "THIS IS ROUND $ROUND. previous/ holds round $((ROUND - 1)); previous-checks.md lists what the automatic checks found. Revise previous/ into out/ (copy then change): keep everything the brief does not criticise, fix every machine finding."
    echo
  fi
  echo "CREATIVE DIRECTOR'S BRIEF"
  cat "$BRIEF"
} > "$PROMPT"

# ── Codex builds ─────────────────────────────────────────────────────────────
echo "== round $ROUND of $NAME — Codex is designing (inputs: $R)"
rc=0
CODEX_CALLER="codex-design.sh $NAME" codex_run "$R/codex.log" --sandbox workspace-write --skip-git-repo-check --ephemeral -C "$R" -o "$R/summary.md" "$(cat "$PROMPT")" || rc=$?
if [ "$rc" -eq 75 ]; then echo "Codex is out of usage — hand $BRIEF to the web-builder agent (round dir: $R)." >&2; exit 75; fi
[ "$rc" -eq 0 ] || { echo "Codex failed (rc=$rc) — see $R/codex.log" >&2; exit 3; }
# codex_run returns 0 for a failed turn that is not a usage limit — a half-written round must not count as built.
if grep -q '"turn.failed"\|"turn_failed"' "$R/codex.log"; then echo "Codex turn failed — see $R/codex.log" >&2; exit 3; fi

# Anything Codex wrote outside out/ is ignored; only files in out/ are rendered.
shopt -s nullglob; HTML=("$R"/out/*.html); shopt -u nullglob
[ "${#HTML[@]}" -gt 0 ] || { echo "Codex produced no out/*.html — see $R/summary.md" >&2; exit 3; }

# ── Machine checks at 375/768/1024/1440 × light/dark/field ──────────────────
node "$REPO/scripts/ittai-design/review-shots.mjs" "$R/shots" "${HTML[@]}" > "$R/checks.raw" 2>&1; crc=$?
{
  echo "# Machine checks — $NAME round $ROUND"
  echo
  echo '```'; grep -E "^[^ ]+\.png|screens with failures" "$R/checks.raw"; echo '```'
  echo
  echo "Per-screen detail: shots/report.json · look at shots/contact-sheet.html before judging the design."
} > "$R/checks.md"
tail -1 "$R/checks.raw"
echo "round dir: $R   (open $R/shots/contact-sheet.html)"
fails="$(grep -c "screens with failures: [1-9]" "$R/checks.raw" || true)"
[ "$crc" -eq 0 ] && [ "$fails" -eq 0 ] || exit 2
exit 0
