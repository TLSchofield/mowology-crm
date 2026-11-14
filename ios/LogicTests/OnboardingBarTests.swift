import Foundation

var failures = 0
func check(_ ok: Bool, _ what: String) { if !ok { failures += 1; print("FAIL: \(what)") } }

check(OnboardingBarLogic.tabs() == [.kaname], "onboarding bar is Kaname alone")
check(OnboardingBarLogic.tabs().count == 1, "one tab")
check(OnboardingBarLogic.selectedIndex == 0 && OnboardingBarLogic.selectedIndex < OnboardingBarLogic.tabs().count, "Kaname selected")
check(!OnboardingBarLogic.tapMovesAway(0), "tap goes nowhere")
check(!OnboardingBarLogic.showsBar(accountExists: false), "no bar before the account exists")
check(OnboardingBarLogic.showsBar(accountExists: true), "bar once the account exists")
check(OnboardingBarLogic.landing(stored: "receipts", isAdmin: true, hasReceipts: true) == .receipts, "lands on Receipts")
check(OnboardingBarLogic.landing(stored: "receipts", isAdmin: false, hasReceipts: true) == .receipts, "crew lands on Receipts too")
check(OnboardingBarLogic.landing(stored: "receipts", isAdmin: true, hasReceipts: false) == .kaname, "no Receipts: Kaname")
check(OnboardingBarLogic.landing(stored: "receipts", isAdmin: false, hasReceipts: false) == nil, "no Receipts, no Kaname: normal open")
check(OnboardingBarLogic.landing(stored: nil, isAdmin: true, hasReceipts: true) == nil, "no value, normal open")
check(OnboardingBarLogic.rollTitles[OnboardingBarLogic.rollFromSlot] == "Kaname" && OnboardingBarLogic.rollTitles[OnboardingBarLogic.rollToSlot] == "Receipts", "roll goes Kaname to Receipts")
check(OnboardingBarLogic.pulses(screenKey: "done", rolling: false), "pulses on Done")
check(!OnboardingBarLogic.pulses(screenKey: "company", rolling: false), "not on other steps")
check(!OnboardingBarLogic.pulses(screenKey: "done", rolling: true), "not while rolling")
check(OnboardingBarLogic.pulseScale(reduceMotion: true, high: true) == 1.0, "Reduce Motion: no pulse")
check(OnboardingBarLogic.pulseScale(reduceMotion: false, high: true) == 1.07, "pulse peak")

if failures == 0 { print("onboarding bar: all checks passed") } else { exit(1) }
