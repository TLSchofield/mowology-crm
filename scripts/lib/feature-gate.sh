# feature-gate.sh — a file goes live only once it is on origin/feature/language (Tim, 2026-11-06). Source it.
#
#   . scripts/lib/feature-gate.sh
#   fg_gate public/crm/x.php app/Y.php || exit 1          # repo paths, read from the working tree
#   fg_gate /tmp/patched.php:public/crm/x.php || exit 1   # SOURCE:REPO_PATH (deploy-safe.sh style)
#   fg_gate --check-only public/crm/x.php                 # dry runs: report, never merge
#
# For each file the bytes about to be uploaded must equal origin/feature/language's copy. When they don't, and
# every such file is the committed working-tree copy, it runs scripts/merge-to-feature.sh (merge feature/language
# in, run the tests, push) and checks again — so deploying IS merging. It refuses, uploading nothing, when:
# a file has uncommitted edits, the source is a temp copy that is not on feature/language, the merge conflicts,
# the tests fail, or the push is rejected three times. Why: see the header of scripts/merge-to-feature.sh.
#
# FEATURE_GATE=off skips it, loudly, for an emergency (production down, git unreachable); say why in the chat.

fg__root() { git rev-parse --show-toplevel 2>/dev/null; }

# fg__same SRC REPO — 0 when SRC's bytes equal origin/feature/language:REPO
fg__same() {
    local want
    want="$(git rev-parse -q --verify "origin/feature/language:$2" 2>/dev/null)" || return 1
    [ "$(git hash-object -- "$1")" = "$want" ]
}

fg_gate() {
    local check_only=0
    if [ "${1:-}" = "--check-only" ]; then check_only=1; shift; fi
    if [ "${FEATURE_GATE:-}" = "off" ]; then
        echo "FEATURE GATE SKIPPED (FEATURE_GATE=off) — these files may go live without being on feature/language: $*" >&2
        return 0
    fi
    [ $# -gt 0 ] || return 0
    local root; root="$(fg__root)" || { echo "feature-gate: not inside the git repo" >&2; return 1; }
    if ! git -C "$root" fetch -q origin feature/language 2>/dev/null; then
        echo "REFUSED  could not fetch origin/feature/language to check what is on it (FEATURE_GATE=off only in an emergency)" >&2
        return 1
    fi

    local arg src repo off=() temp=() dirty=()
    for arg in "$@"; do
        src="${arg%%:*}"; repo="${arg#*:}"
        [[ "$arg" == *:* ]] || { src="$arg"; repo="$arg"; }
        fg__same "$src" "$repo" && continue
        off+=("$repo")
        if [ "$src" != "$repo" ]; then temp+=("$repo")
        elif ! git -C "$root" diff --quiet HEAD -- "$repo" 2>/dev/null || [ -z "$(git -C "$root" ls-files -- "$repo")" ]; then dirty+=("$repo")
        fi
    done
    [ ${#off[@]} -eq 0 ] && { echo "on feature/language: $#/$# file(s)"; return 0; }

    if [ $check_only = 1 ]; then
        echo "NOT ON feature/language (a real deploy merges first): ${off[*]}"
        return 0
    fi
    if [ ${#dirty[@]} -gt 0 ]; then
        echo "REFUSED  uncommitted or untracked — commit, then deploy (the deploy merges to feature/language): ${dirty[*]}" >&2
        return 1
    fi
    if [ ${#temp[@]} -gt 0 ]; then
        echo "REFUSED  the upload is a temp copy that is not on feature/language — put the change in the repo file, commit, deploy: ${temp[*]}" >&2
        return 1
    fi

    echo "merging  ${#off[@]} file(s) not yet on feature/language — running scripts/merge-to-feature.sh"
    "$root/scripts/merge-to-feature.sh" || { echo "REFUSED  merge to feature/language did not finish (above) — nothing uploaded" >&2; return 1; }
    git -C "$root" fetch -q origin feature/language 2>/dev/null
    local still=()
    for arg in "$@"; do
        src="${arg%%:*}"; repo="${arg#*:}"
        [[ "$arg" == *:* ]] || { src="$arg"; repo="$arg"; }
        fg__same "$src" "$repo" || still+=("$repo")
    done
    if [ ${#still[@]} -gt 0 ]; then
        echo "REFUSED  still differs from origin/feature/language after the merge: ${still[*]}" >&2
        return 1
    fi
    echo "on feature/language: $#/$# file(s) (merged just now)"
}
