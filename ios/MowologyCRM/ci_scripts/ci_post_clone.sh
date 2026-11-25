#!/bin/sh
# NOTE (2026-07-19): The agvtool line below does NOT control the build number
# that TestFlight sees. Proven empirically: builds 2,3,4,6,7 each uploaded with
# CFBundleVersion == Xcode Cloud's own CI_BUILD_NUMBER, ignoring both the
# project's CURRENT_PROJECT_VERSION (100) AND this script's agvtool value (the
# log for build 7 shows agvtool correctly wrote 107, yet TestFlight received 7).
# Xcode Cloud unconditionally stamps CFBundleVersion = CI_BUILD_NUMBER.
#
# Because the legacy fastlane pipeline already shipped build 34 under version
# 1.0.0, and Xcode Cloud's counter restarted at 1, no Cloud build can outrank
# 34 on build number. The real lever is MARKETING_VERSION: iOS/TestFlight
# compares the marketing version BEFORE the build number, so 1.0.1 (8) beats
# 1.0.0 (34). That bump lives in project.yml + project.pbxproj, not here.
#
# The agvtool line is kept only so that *local* Xcode builds (no CI_BUILD_NUMBER
# override) still get a sane, high CFBundleVersion. It is a no-op in Xcode Cloud.
set -e
cd "$CI_PRIMARY_REPOSITORY_PATH/ios/MowologyCRM"
agvtool new-version -all $((CI_BUILD_NUMBER + 100))
