#!/bin/sh
# Xcode Cloud overrides CURRENT_PROJECT_VERSION with its own internal build
# counter regardless of what's committed in project.pbxproj (confirmed: builds
# 1-4 all shipped with that literal counter as CFBundleVersion, ignoring the
# project's hardcoded value entirely). That counter restarted from 1 with no
# awareness of build 34, already installed via the old GitHub Actions/fastlane
# pipeline — so every Xcode Cloud build was silently invisible on-device as a
# "lower" build number than what's already there.
#
# This is Apple's own documented fix: a ci_post_clone.sh using agvtool to set
# CFBundleVersion explicitly from Xcode Cloud's $CI_BUILD_NUMBER, offset well
# above both the Xcode Cloud counter's own history and the old pipeline's
# GITHUB_RUN_NUMBER-based builds (which reached the 60s).
set -e
cd "$CI_PRIMARY_REPOSITORY_PATH/ios/MowologyCRM"
agvtool new-version -all $((CI_BUILD_NUMBER + 100))
