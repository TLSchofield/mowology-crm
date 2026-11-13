import Foundation

// Real payloads: Fixtures/metrickit-cpu-exception.json (build 581, 2026-11-12) and
// metrickit-crash-watchdog.json (build 567, 2026-11-11), both copied from the diagnostics export.
// The hang, launch and metrics cases are synthetic: no such payload has reached the log yet.
func check(_ ok: Bool, _ label: String) {
    if !ok { fatalError("FAIL: \(label)") }
}

let dir = URL(fileURLWithPath: CommandLine.arguments[1])
func fixture(_ name: String) -> Data { try! Data(contentsOf: dir.appendingPathComponent(name)) }

let cpu = MetricKitSummary.diagnosticLines(fixture("metrickit-cpu-exception.json"))
check(cpu.count == 1, "one line per CPU diagnostic")
print(cpu[0])
check(cpu[0].hasPrefix("CPU EXCEPTION: v1.3.139(581) iPhone17,3 iPhone OS 18.5 (22F76)"), "app, build, device, OS")
check(cpu[0].contains("cpu 48 sec of 48 sec sampled"), "CPU time")
check(cpu[0].contains("window 2026-11-12"), "time window")
check(cpu[0].contains("samples ") && cpu[0].contains("%"), "binary shares")
check(cpu[0].contains("MowologyCRM+0x"), "app frames named by offset")
check(cpu[0].count < 700, "one short line, not a payload dump")

let crash = MetricKitSummary.diagnosticLines(fixture("metrickit-crash-watchdog.json"))
check(crash.count == 1, "one line per crash diagnostic")
print(crash[0])
check(crash[0].hasPrefix("CRASH: v1.3.139(567)"), "crash build")
check(crash[0].contains("scene-update watchdog (0x8BADF00D, 10.00 s allowance)"), "watchdog decoded")
check(crash[0].contains("signal 9"), "signal")
check(!crash[0].contains("RBSTerminateContext"), "raw termination text not repeated")

check(MetricKitSummary.diagnosticLines(Data("{\"timeStampBegin\":\"2026".utf8))[0].hasPrefix("unreadable payload"), "truncated JSON still gives a line")
check(MetricKitSummary.diagnosticLines(Data("{\"timeStampBegin\":\"2026-11-12 07:07:00\"}".utf8))[0].hasPrefix("no diagnostics inside payload"), "empty payload")

let hang = #"{"timeStampBegin":"2026-11-12 07:00:00","hangDiagnostics":[{"diagnosticMetaData":{"appVersion":"1.3.140","appBuildVersion":"600","deviceType":"iPhone17,3","osVersion":"iPhone OS 18.5","hangDuration":"2.4 sec"},"callStackTree":{"callStacks":[{"callStackRootFrames":[{"binaryName":"dyld","binaryUUID":"AAAAAAAA-0000","offsetIntoBinaryTextSegment":1,"sampleCount":5,"subFrames":[{"binaryName":"MowologyCRM","binaryUUID":"BBBBBBBB-1111","offsetIntoBinaryTextSegment":4096,"sampleCount":5,"subFrames":[{"binaryName":"SwiftUICore","binaryUUID":"CCCCCCCC","offsetIntoBinaryTextSegment":9,"sampleCount":3},{"binaryName":"MowologyCRM","binaryUUID":"BBBBBBBB-1111","offsetIntoBinaryTextSegment":8192,"sampleCount":2}]}]}]}]}}]}"#
let hangLine = MetricKitSummary.diagnosticLines(Data(hang.utf8))[0]
print(hangLine)
check(hangLine.hasPrefix("HANG: v1.3.140(600)") && hangLine.contains("hang 2.4 sec"), "hang duration")
check(hangLine.contains("samples 5: SwiftUICore 60%, MowologyCRM 40%"), "leaf shares")
check(hangLine.contains("MowologyCRM+0x1000[BBBBBBBB] ×3") && hangLine.contains("MowologyCRM+0x2000[BBBBBBBB] ×2"), "last app frame before leaf")

let launch = #"{"appLaunchDiagnostics":[{"diagnosticMetaData":{"launchDuration":"6.1 sec"}}]}"#
check(MetricKitSummary.diagnosticLines(Data(launch.utf8))[0].contains("launch 6.1 sec"), "launch duration")

let row = MetricKitSummary.metricsRow(.init(launchMedianMs: 640, resumeMedianMs: 120, hitchMsPerSecond: 7.25, peakMemoryMB: 312, cpuSeconds: 900, gpuSeconds: 12.5, locationSeconds: 3000, foregroundSeconds: 7200))
print(row)
check(row == "launch 640 ms median · resume 120 ms · hitches 7.2 ms/s · peak memory 312 MB · cpu 900 s · gpu 12.5 s · location 3000 s · foreground 7200 s · cpu per foreground hour 450 s", "metrics row")
check(MetricKitSummary.metricsRow(.init()).hasPrefix("launch n/a median · resume n/a"), "missing values read n/a")

check(MetricKitSummary.histogramMedian([(100, 1), (200, 5), (50, 1)]) == 200, "histogram median")
check(MetricKitSummary.histogramMedian([]) == nil, "empty histogram")
print("metrickit summary checks passed")
