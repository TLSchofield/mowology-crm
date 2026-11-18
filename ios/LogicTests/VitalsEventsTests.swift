import Foundation

// Lines are what the app writes today: MetricKitSummary output for the two real payloads, and HangWatchdog's own wording.
func check(_ ok: Bool, _ label: String) {
    if !ok { fatalError("FAIL: \(label)") }
}

let dir = URL(fileURLWithPath: CommandLine.arguments[1])
func fixture(_ name: String) -> Data { try! Data(contentsOf: dir.appendingPathComponent(name)) }
let now = Date(timeIntervalSince1970: 1_800_000_000)

// --- diagnostics lines → events
let cpuLine = MetricKitSummary.diagnosticLines(fixture("metrickit-cpu-exception.json"))[0]
let cpu = VitalsClassifier.event(category: "apple.diagnostic", message: cpuLine, at: now, id: "e1")!
check(cpu.kind == "cpu_exception" && cpu.severity == "warn", "cpu kind")
check(cpu.signatureParts?.frame == "MowologyCRM+0x8499f" && cpu.signatureParts?.binaryUUID == "10BFDF3A", "cpu top frame is the signature")
check(cpu.signatureParts?.cause == "", "cpu cause left to the frame")
check(cpu.at == "2027-01-15T08:00:00Z", "UTC timestamp")

let crashLine = MetricKitSummary.diagnosticLines(fixture("metrickit-crash-watchdog.json"))[0]
let crash = VitalsClassifier.event(category: "apple.diagnostic", message: crashLine, at: now, id: "e2")!
check(crash.kind == "crash" && crash.severity == "error", "crash kind")
check(crash.signatureParts?.cause == "scene-update watchdog (0x8BADF00D, 10.00 s allowance), signal 9, exception type 10", "crash cause names the watchdog")

check(VitalsClassifier.event(category: "apple.diagnostic", message: "unreadable payload (9 bytes, not valid JSON)", at: now, id: "x") == nil, "unreadable payload is not uploaded")
check(VitalsClassifier.event(category: "network", message: "status=503", at: now, id: "x") == nil, "other categories stay local")
check(VitalsClassifier.event(category: "apple.diagnostic", message: String(repeating: "CRASH: ", count: 200), at: now, id: "x")!.message.count == 600, "message cut at 600")

// --- daily metrics
let daily = VitalsClassifier.event(category: "apple.metrics", message: "daily 2026-11-14 – launch 640 ms median", at: now, id: "m")!
check(daily.kind == "daily_metrics" && daily.severity == "info" && daily.signatureParts == nil, "metrics row")

// --- hang watchdog
let start = VitalsClassifier.event(category: "HangWatchdog",
    message: "Main thread not responding for over 2.0 s. Last navigation: tab:schedule | sheet:visit | tab:search", at: now, id: "w")!
check(start.kind == "watchdog" && start.severity == "error", "stall start is a watchdog event")
check(start.signatureParts?.cause == "main thread stall" && start.signatureParts?.frame == "tab:search", "grouped by the last screen")
check(start.breadcrumbs == ["tab:schedule", "sheet:visit", "tab:search"], "trail travels with it")
let end = VitalsClassifier.event(category: "HangWatchdog", message: "Main thread stalled for 3.40 s. Last navigation: tab:search", at: now, id: "w2")!
check(end.kind == "stall_end" && end.signatureParts == nil, "stall length is a number, not a second issue")
let bare = VitalsClassifier.event(category: "HangWatchdog", message: "Main thread not responding for over 2.0 s. Last navigation: (no navigation breadcrumbs)", at: now, id: "w3")!
check(bare.breadcrumbs == nil && bare.signatureParts?.frame == "", "no trail, no crumbs")

// --- unclean exit and crash loop
let unclean = VitalsClassifier.event(category: "vitals.unclean_exit", message: "Previous run on build 587 ended while the app was in the foreground (crash, watchdog or memory kill).",
                                     at: now, id: "u", previousRunBreadcrumbs: ["tab:schedule", "tab:search"])!
check(unclean.kind == "unclean_exit" && unclean.signatureParts?.frame == "tab:search" && unclean.breadcrumbs?.count == 2, "unclean exit carries the last run's trail")
check(VitalsClassifier.event(category: "CrashLoopBreaker", message: "Two unclean exits within 24 h on build 587", at: now, id: "c")!.severity == "error", "crash loop is an error")

// --- wire shape the server expects
let json = String(data: try! JSONEncoder().encode(start), encoding: .utf8)!
check(json.contains("\"signature_parts\"") && json.contains("\"binary_uuid\"") == false, "snake_case keys; absent uuid omitted")
check(String(data: try! JSONEncoder().encode(cpu), encoding: .utf8)!.contains("\"binary_uuid\":\"10BFDF3A\""), "uuid key")

// --- outbox
var box = VitalsOutbox()
for i in 0..<120 { box.add(VitalsEvent(id: "id\(i)", at: "2027-01-15T08:00:00Z", kind: "hang", severity: "warn", message: "m", signatureParts: nil, breadcrumbs: nil)) }
check(box.events.count == 120, "queued")
box.add(VitalsEvent(id: "id5", at: "", kind: "hang", severity: "warn", message: "dup", signatureParts: nil, breadcrumbs: nil))
check(box.events.count == 120, "same id is not queued twice")
let first = box.nextBatch(now: now)
check(first.count == 50 && first.first?.id == "id0", "50 oldest first")
box.sent(first.map(\.id))
check(box.events.count == 70 && box.nextBatch(now: now).first?.id == "id50", "sent events leave the queue")

var big = VitalsOutbox()
for i in 0..<30 { big.add(VitalsEvent(id: "b\(i)", at: "2027-01-15T08:00:00Z", kind: "crash", severity: "error", message: String(repeating: "x", count: 600), signatureParts: nil, breadcrumbs: (0..<30).map { _ in String(repeating: "c", count: 160) })) }
let bytes = try! JSONEncoder().encode(big.nextBatch(now: now)).count
check(bytes <= 64_000 && big.nextBatch(now: now).count < 30, "a heavy batch stays under the server's 64 KB")

var flood = VitalsOutbox()
for i in 0..<(VitalsOutbox.maxEvents + 40) { flood.add(VitalsEvent(id: "f\(i)", at: "", kind: "hang", severity: "warn", message: "", signatureParts: nil, breadcrumbs: nil)) }
check(flood.events.count == VitalsOutbox.maxEvents && flood.events.first?.id == "f40", "a flood drops the oldest")

var retry = VitalsOutbox()
retry.add(VitalsEvent(id: "r", at: "", kind: "hang", severity: "warn", message: "", signatureParts: nil, breadcrumbs: nil))
retry.failed(now: now)
check(retry.nextBatch(now: now).isEmpty && retry.nextBatch(now: now.addingTimeInterval(31)).count == 1, "30 s back-off after one failure")
retry.failed(now: now); retry.failed(now: now)
check(retry.nextBatch(now: now.addingTimeInterval(100)).isEmpty && retry.nextBatch(now: now.addingTimeInterval(121)).count == 1, "doubles: 2 minutes after the third")
for _ in 0..<20 { retry.failed(now: now) }
check(retry.nextBatch(now: now.addingTimeInterval(3601)).count == 1 && retry.nextBatch(now: now.addingTimeInterval(3500)).isEmpty, "never waits more than an hour")
retry.sent(["r"])
check(retry.events.isEmpty && retry.retryAfter == nil, "success clears the back-off")
let saved = try! JSONDecoder().decode(VitalsOutbox.self, from: JSONEncoder().encode(box))
check(saved == box, "outbox survives relaunch")
print("vitals events checks passed")
