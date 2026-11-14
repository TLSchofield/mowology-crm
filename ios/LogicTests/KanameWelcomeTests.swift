import Foundation

var failures = 0
func check<T: Equatable>(_ got: T, _ want: T, _ label: String) {
    if got != want { failures += 1; print("FAIL \(label): got \(got), want \(want)") }
}

// Receipts empty screen wording
check(ReceiptsEmptyWording.subtitle(isAdmin: true, autoShoots: true),
      "Tap the camera below. Hold your phone over the receipt, and it takes the photo by itself when it is steady.\nPenny reads it and files it; she asks you only if she is not sure.", "admin auto")
check(ReceiptsEmptyWording.subtitle(isAdmin: false, autoShoots: true),
      "Tap the camera below. Hold your phone over the receipt, and it takes the photo by itself when it is steady.\nThe office reviews it.", "crew auto")
check(ReceiptsEmptyWording.subtitle(isAdmin: true, autoShoots: false), "Tap the camera below, point it at the receipt and tap the button.\nPenny reads it and files it; she asks you only if she is not sure.", "admin manual")
check(ReceiptsEmptyWording.subtitle(isAdmin: false, autoShoots: false), "Tap the camera below, point it at the receipt and tap the button.\nThe office reviews it.", "crew manual")
check(ReceiptsEmptyWording.showsHint(hasLoaded: true, isLoading: false, isEmpty: true, hasError: false), true, "hint when loaded, empty")
check(ReceiptsEmptyWording.showsHint(hasLoaded: false, isLoading: false, isEmpty: true, hasError: false), false, "no hint before first load")
check(ReceiptsEmptyWording.showsHint(hasLoaded: true, isLoading: true, isEmpty: true, hasError: false), false, "no hint while loading")
check(ReceiptsEmptyWording.showsHint(hasLoaded: true, isLoading: false, isEmpty: false, hasError: false), false, "no hint with receipts")
check(ReceiptsEmptyWording.showsHint(hasLoaded: true, isLoading: false, isEmpty: true, hasError: true), false, "no hint on error")

// Welcome entry and gate
check(KanameWelcome.entry(canAddJobs: true, receiptsOn: true), .work, "work wins")
check(KanameWelcome.entry(canAddJobs: true, receiptsOn: false), .work, "work alone")
check(KanameWelcome.entry(canAddJobs: false, receiptsOn: true), .books, "books when only receipts")
check(KanameWelcome.entry(canAddJobs: false, receiptsOn: false), nil, "neither")
check(KanameWelcome.shouldShowWelcome(seen: false, entry: .work), true, "unseen shows")
check(KanameWelcome.shouldShowWelcome(seen: true, entry: .work), false, "seen never shows")
check(KanameWelcome.shouldShowWelcome(seen: false, entry: nil), false, "no entry no welcome")

// Copy
check(KanameWelcome.headline(firstName: "Sam"), "Hello Sam. Let's dot the i's and cross the t's.", "headline")
check(KanameWelcome.headline(firstName: "  "), "Hello. Let's dot the i's and cross the t's.", "headline blank")
check(KanameWelcome.firstName(fromFullName: "Sam Lee"), "Sam", "first name")
check(KanameWelcome.firstName(fromFullName: ""), "", "first name empty")
check(KanameWelcome.primaryButton(.books), "Take my first receipt", "books button")
check(KanameWelcome.primaryButton(.work), "Add my first job", "work button")
check(KanameWelcome.entryLine(.books), "Start with receipts: take a photo and Penny, the bookkeeper, files it.", "books line")
check(KanameWelcome.entryLine(.work), "Start with the work: add a client and a job.", "work line")
let allCopy = [KanameWelcome.body, KanameWelcome.headline(firstName: "A"), KanameWelcome.entryLine(.books), KanameWelcome.entryLine(.work),
               ReceiptsEmptyWording.autoLine, ReceiptsEmptyWording.manualLine, ReceiptsEmptyWording.adminTail].joined()
check(allCopy.contains("\u{2014}") || allCopy.contains("!"), false, "no em dash or exclamation mark")

// Seen flag per business and user
let suite = UserDefaults(suiteName: "kaname-welcome-test-\(UUID().uuidString)")!
check(KanameWelcome.isSeen(host: "mowology.ca", userId: 4, defaults: suite), false, "unseen at start")
KanameWelcome.markSeen(host: "mowology.ca", userId: 4, defaults: suite)
check(KanameWelcome.isSeen(host: "mowology.ca", userId: 4, defaults: suite), true, "seen after mark")
check(KanameWelcome.isSeen(host: "mowology.ca", userId: 5, defaults: suite), false, "other user unseen")
check(KanameWelcome.isSeen(host: "other.example", userId: 4, defaults: suite), false, "other business unseen")

if failures > 0 { print("KanameWelcome: \(failures) failed"); exit(1) }
print("KanameWelcome: all passed")
