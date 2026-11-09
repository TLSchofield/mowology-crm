#!/usr/bin/env bash
# android-field-release.sh — build, sign, check and publish a Mowology Crew field build (2026-11-09).
#
# Why: the first native field release (2.2.10) was a chain of hand-run steps with three ways to go wrong that the
# device would only show later: an APK signed with the wrong key (installed apps carry the Android debug key and
# refuse anything else), a build made in the Codex worktree (its google-services.json is a dummy: no push), and a feed
# whose version / build / checksum do not match the APK (the app then refuses the update or asks for it forever).
#
#   scripts/android-field-release.sh build   <commit-on-codex/native>   # build + sign + check, copy the APK here
#   scripts/android-field-release.sh prepare "<release notes>"          # edit the feed and the /crew link for that APK
#   scripts/android-field-release.sh publish                            # deploy: APK, /crew link, feed (in that order)
#   scripts/android-field-release.sh status                             # what the live site serves now
#
# `build` takes about half an hour on this Mac. `prepare` changes files in this checkout only; commit them, then
# `publish` (each file goes through scripts/deploy-safe.sh and its gate). The other businesses' feed files pin their own
# release fields, so nothing here reaches their apps.
set -euo pipefail
cd "$(git rev-parse --show-toplevel)"

ANDROID_REPO="${ANDROID_REPO:-$HOME/Projects/mowology-android-codex}"
BUILD_TREE="${BUILD_TREE:-$HOME/Projects/mowology-android-worktrees/field-carousel}"
export JAVA_HOME="${JAVA_HOME:-$HOME/.jdks/jdk-17.0.18+8/Contents/Home}"
export ANDROID_HOME="${ANDROID_HOME:-$HOME/Library/Android/sdk}"
BT="$ANDROID_HOME/build-tools/34.0.0"
DEBUG_CERT="472fd50fe306fbfb90a3cbce2caa191f61fcaf26986b5565b591933f0379ba03"   # the key every installed copy carries
DL="public/crm/downloads"
FEED="$DL/app-version.json"
STATE="${TMPDIR:-/tmp}/android-field-release.env"
SITE="https://mowology.ca"

say() { printf '%s\n' "$*"; }
die() { printf 'STOP  %s\n' "$*" >&2; exit 1; }

badge() { "$BT/aapt" dump badging "$1" 2>/dev/null | sed -n "s/^package: name='\([^']*\)' versionCode='\([^']*\)' versionName='\([^']*\)'.*/\1 \2 \3/p"; }

cmd_build() {
  local commit="${1:-}"; [ -n "$commit" ] || die "usage: build <commit-on-codex/native>"
  [ -d "$BUILD_TREE/.git" ] || [ -f "$BUILD_TREE/.git" ] || die "build checkout missing: $BUILD_TREE (a clean worktree of $ANDROID_REPO with the real google-services.json)"
  git -C "$BUILD_TREE" checkout -q --detach "$commit"
  [ -z "$(git -C "$BUILD_TREE" status --porcelain --untracked-files=no)" ] || die "the build checkout has local edits"
  if grep -q AIzaDUMMY "$BUILD_TREE/android/app/google-services.json"; then die "google-services.json is the dummy: push would not work in this build"; fi
  local out="$BUILD_TREE/android/app/build/outputs/apk/mowology/release/app-mowology-release.apk"
  rm -f "$out"   # never mistake the last build's APK for this one
  say "building $(git -C "$BUILD_TREE" log --oneline -1 | cut -c1-70) …"
  # Lint cannot run offline; without the -x flags the build fails after 20 minutes.
  ( cd "$BUILD_TREE" && ./android/gradlew -p android --offline assembleMowologyRelease \
      -x lintVitalAnalyzeMowologyRelease -x lintVitalReportMowologyRelease -x lintVitalMowologyRelease \
      -x generateMowologyReleaseLintVitalReportModel -q ) 2>&1 | grep -E "FAILED|error:|What went wrong|^e: " && die "gradle failed"
  [ -f "$out" ] || die "no APK came out of the build"
  read -r pkg build version <<<"$(badge "$out")"
  [ "$pkg" = "ca.mowology.crew" ] || die "the APK is package '$pkg', not ca.mowology.crew"
  local apk="$DL/mowology-crew-$version-test.apk"
  cp "$out" "$apk"
  "$BT/apksigner" sign --ks "$HOME/.android/debug.keystore" --ks-key-alias androiddebugkey --ks-pass pass:android --key-pass pass:android "$apk"
  rm -f "$apk.idsig"   # apksigner's side file; nothing uses it
  "$BT/apksigner" verify --print-certs "$apk" | grep -q "$DEBUG_CERT" || die "the signed APK does not carry the debug key installed copies expect"
  unzip -p "$apk" assets/capacitor.config.json | grep -q '"url": "https://mowology.ca/' || die "the APK is not pointed at mowology.ca"
  local sha; sha="$(shasum -a 256 "$apk" | cut -c1-64)"
  printf 'APK=%s\nVERSION=%s\nBUILD=%s\nSHA=%s\n' "$apk" "$version" "$build" "$sha" > "$STATE"
  say "built  $apk"
  say "       version $version  build $build  $(( $(stat -f %z "$apk") / 1048576 )) MB  sha256 $sha"
  say "next:  scripts/android-field-release.sh prepare \"<release notes>\""
}

cmd_prepare() {
  local notes="${1:-}"; [ -n "$notes" ] || die "usage: prepare \"<release notes>\""
  [ -f "$STATE" ] || die "run build first"; . "$STATE"
  [ -f "$APK" ] && [ "$(shasum -a 256 "$APK" | cut -c1-64)" = "$SHA" ] || die "the APK changed since it was built"
  # Start from what is live: another chat may have deployed a newer feed than this checkout holds.
  scripts/prod-pull.sh "$FEED" "public/.htaccess" >/dev/null
  local pulled="${TMPDIR:-/tmp}/prod-pull"
  cmp -s "$pulled/$FEED" "$FEED" || die "the live feed differs from this checkout's — run: scripts/prod-pull.sh --into-repo $FEED, look at the difference, then prepare again"
  cmp -s "$pulled/public/.htaccess" "public/.htaccess" || die "the live .htaccess differs from this checkout's — pull it into the repo first"
  local live_build; live_build="$(python3 -c "import json,sys; print(json.load(open(sys.argv[1]))['android'].get('build',0))" "$FEED")"
  [ "$BUILD" -gt "$live_build" ] || die "build $BUILD is not higher than the live build $live_build (an app only ever updates upwards)"
  python3 - "$FEED" "$VERSION" "$BUILD" "$SHA" "/crm/downloads/$(basename "$APK")" "$notes" <<'PY'
import json, sys, datetime
path, version, build, sha, url, notes = sys.argv[1:7]
feed = json.load(open(path))
feed["android"].update({
    "version": version, "build": int(build),
    # Required for every install while in development (Tim, 2026-11-09). force_update stays false: it once blocked every install.
    "min_version": version, "min_build": int(build),
    "apk_url": url, "apk_sha256": sha, "released": datetime.date.today().isoformat(),
    "release_notes": notes, "force_update": False,
})
open(path, "w").write(json.dumps(feed, indent=2, ensure_ascii=False) + "\n")
PY
  python3 - "$(basename "$APK")" <<'PY'
import re, sys
p = "public/.htaccess"
s = open(p).read()
# Two rules lead to the release: /crew, and the fixed file name the old app's pages link.
new, n = re.subn(r"(RewriteRule \^(?:crew/\?|crm/downloads/mowology-crew\\\.apk)\$ /crm/downloads/)\S+( \[NC,L,R=302\])", r"\g<1>" + sys.argv[1] + r"\g<2>", s)
if n != 2: sys.exit("STOP  the two download rules were not both found in public/.htaccess")
open(p, "w").write(new)
PY
  say "prepared: $FEED and the /crew link now describe $(basename "$APK") (version $VERSION, build $BUILD)"
  say "next:  git add $APK $FEED public/.htaccess && git commit, then scripts/android-field-release.sh publish"
}

cmd_publish() {
  [ -f "$STATE" ] || die "run build and prepare first"; . "$STATE"
  [ -z "$(git status --porcelain -- "$APK" "$FEED" public/.htaccess)" ] || die "commit $APK, $FEED and public/.htaccess first"
  scripts/prod-pull.sh "$APK" >/dev/null 2>&1 || true            # records that the file is new on the server
  # The APK first, the link next, the feed last: the feed is what tells installed apps to update.
  scripts/deploy-safe.sh "$APK"
  [ "$(curl -s -m 600 "$SITE/crm/downloads/$(basename "$APK")" | shasum -a 256 | cut -c1-64)" = "$SHA" ] || die "the served APK's checksum is not $SHA — the feed was NOT changed"
  scripts/deploy-safe.sh public/.htaccess
  curl -sI -m 30 "$SITE/" | head -1 | grep -q " 200" || die "the home page did not answer 200 after the .htaccess deploy — look at it now; the feed was NOT changed"
  scripts/deploy-safe.sh "$FEED"
  cmd_status
}

cmd_status() {
  say "live feed:"
  curl -s -m 30 "$SITE/crm/api/app-version.php" | python3 -c "
import json, sys
a = json.load(sys.stdin)['android']
for k in ('version', 'build', 'min_version', 'min_build', 'apk_url', 'apk_sha256', 'force_update', 'native_day', 'native_crew_schedule'): print('  %s = %s' % (k, a.get(k)))"
  say "/crew → $(curl -sI -m 30 "$SITE/crew" | sed -n 's/^[Ll]ocation: //p' | tr -d '\r')"
  for b in testcompany rehearsal rehearsal2; do
    curl -s -m 30 "https://$b.ittai.ca/crm/api/app-version.php" | python3 -c "
import json, sys
a = json.load(sys.stdin)['android']; print('  other business $b: version %s, minimum build %s' % (a.get('version'), a.get('min_build')))"
  done
}

case "${1:-}" in
  build)   shift; cmd_build "$@" ;;
  prepare) shift; cmd_prepare "$@" ;;
  publish) cmd_publish ;;
  status)  cmd_status ;;
  *) sed -n '2,17p' "$0" | sed 's/^# \{0,1\}//'; exit 2 ;;
esac
