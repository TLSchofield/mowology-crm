#!/usr/bin/env bash
# merge-to-feature.sh — put this branch's committed work on feature/language (Tim, 2026-11-06: "set up automatic").
#
# Why: each chat works on its own branch and deploys files to production from there. Nothing made it merge back, so
# feature/language (the branch production is meant to track) fell days behind production, and every later chat built
# on old copies and hit conflicts. On 2026-11-06 nine branches with live work had to be merged by hand.
#
# What it does, in this checkout:
#   1. refuses if tracked files have uncommitted changes (commit first);
#   2. fetches origin/feature/language and merges it into the current branch (stops on a conflict, merge aborted);
#   3. runs the PHP tests (stops on a failure; the merge commit stays local, nothing pushed);
#   4. pushes HEAD to origin/feature/language — a fast-forward only; if someone pushed meanwhile it fetches and
#      repeats 2–4 (three tries).
# Every deploy script calls it through scripts/lib/feature-gate.sh, so a file is live only once it is on
# feature/language. Run it by hand after any change that is pushed but needs no deploy (docs, iOS).
#
# Usage: scripts/merge-to-feature.sh            # merge, test, push
#        scripts/merge-to-feature.sh --no-push  # merge and test only
# Exit: 0 done · 2 uncommitted changes / detached HEAD / fetch failed · 3 conflict · 4 tests failed · 5 push failed
set -uo pipefail
cd "$(git rev-parse --show-toplevel)" || exit 2

PUSH=1
[ "${1:-}" = "--no-push" ] && PUSH=0
TARGET=feature/language

branch="$(git symbolic-ref --quiet --short HEAD)" || { echo "merge-to-feature: detached HEAD — check out a branch first" >&2; exit 2; }
if ! git diff --quiet HEAD -- 2>/dev/null; then
    echo "merge-to-feature: uncommitted changes to tracked files — commit them first:" >&2
    git diff --name-only HEAD -- | sed 's/^/  /' >&2
    exit 2
fi

run_tests() {
    [ -x vendor/bin/phpunit ] || { echo "merge-to-feature: vendor/bin/phpunit missing — run composer install" >&2; return 1; }
    local log; log="$(mktemp "${TMPDIR:-/tmp}/merge-to-feature-tests.XXXXXX")"
    if php -d memory_limit=1G vendor/bin/phpunit >"$log" 2>&1 && ! grep -qE '^(FAILURES|ERRORS)!' "$log"; then
        echo "tests    $(grep -E '^(OK|Tests:)' "$log" | tail -1)"
        rm -f "$log"; return 0
    fi
    echo "merge-to-feature: tests failed — nothing pushed (log: $log)" >&2
    grep -E '^[0-9]+\) |^(FAILURES|ERRORS)!|^Tests:|Fatal' "$log" | head -15 >&2
    return 1
}

for try in 1 2 3; do
    git fetch -q origin "$TARGET" || { echo "merge-to-feature: could not fetch origin/$TARGET" >&2; exit 2; }
    if git merge-base --is-ancestor "origin/$TARGET" HEAD; then
        echo "merge    origin/$TARGET is already in $branch"
    else
        if ! git merge -q --no-ff --no-edit -m "merge: origin/$TARGET into $branch (merge-to-feature.sh)" "origin/$TARGET" >/dev/null 2>&1; then
            echo "merge-to-feature: conflict merging origin/$TARGET into $branch — merge aborted, nothing changed:" >&2
            git diff --name-only --diff-filter=U | sed 's/^/  /' >&2
            if ! git merge --abort 2>/dev/null || [ -f "$(git rev-parse --git-path MERGE_HEAD)" ]; then
                echo "merge-to-feature: the merge could NOT be aborted — this checkout is mid-merge; run 'git merge --abort' and check 'git status'" >&2
                exit 3
            fi
            echo "Resolve by hand: git merge origin/$TARGET, fix, commit, then run this again." >&2
            exit 3
        fi
        echo "merge    origin/$TARGET merged into $branch"
    fi
    if git merge-base --is-ancestor HEAD "origin/$TARGET"; then
        echo "done     $branch is already on $TARGET"
        exit 0
    fi
    tested="$(git rev-parse HEAD)"
    run_tests || exit 4
    [ "$PUSH" = 1 ] || { echo "done     merged and tested, not pushed (--no-push)"; exit 0; }
    # Push exactly the commit that was tested (Codex review 2026-11-06): a commit made in this checkout during the
    # test run would otherwise ride along untested.
    if [ "$(git rev-parse HEAD)" != "$tested" ]; then
        echo "merge-to-feature: $branch moved while the tests ran — nothing pushed; run it again" >&2
        exit 4
    fi
    if git push -q origin "$tested:refs/heads/$TARGET" 2>/dev/null; then
        echo "pushed   $branch → origin/$TARGET (${tested:0:9})"
        exit 0
    fi
    echo "push     rejected (someone pushed meanwhile) — try $try of 3, fetching again"
done
echo "merge-to-feature: could not push to origin/$TARGET after 3 tries" >&2
exit 5
