import Foundation
#if canImport(UIKit)
import UIKit
#endif

struct CrashLoopStrike: Codable, Equatable {
    let date: Date
    let build: String
}

struct CrashLoopEvaluation: Equatable {
    let strikes: [CrashLoopStrike]
    let tripped: Bool
}

enum CrashLoopRules {
    static let window: TimeInterval = 24 * 60 * 60

    static func evaluate(strikes: [CrashLoopStrike], now: Date, build: String) -> CrashLoopEvaluation {
        let recent = strikes.filter {
            $0.build == build && $0.date <= now && now.timeIntervalSince($0.date) <= window
        }
        return CrashLoopEvaluation(strikes: recent, tripped: recent.count >= 2)
    }
}

#if !CRASH_GUARD_LOGIC_ONLY
final class CrashLoopBreaker: @unchecked Sendable {
    static let shared = CrashLoopBreaker(defaults: .standard)

    private enum Key {
        static let cleanExit = "crashGuard.cleanExit"
        static let hasLaunched = "crashGuard.hasLaunched"
        static let lastBuild = "crashGuard.lastBuild"
        static let strikes = "crashGuard.strikes"
    }

    private let defaults: UserDefaults
    private let notificationCenter: NotificationCenter
    private var observers: [NSObjectProtocol] = []

    init(defaults: UserDefaults, notificationCenter: NotificationCenter = .default) {
        self.defaults = defaults
        self.notificationCenter = notificationCenter
    }

    func start(now: Date = Date(), build: String? = nil) {
        let currentBuild = build ?? (Bundle.main.object(forInfoDictionaryKey: "CFBundleVersion") as? String ?? "unknown")
        let previousBuild = defaults.string(forKey: Key.lastBuild)
        let isSameBuild = previousBuild == currentBuild
        var strikes = isSameBuild ? storedStrikes() : []
        if isSameBuild, defaults.bool(forKey: Key.hasLaunched), !defaults.bool(forKey: Key.cleanExit) {
            strikes.append(CrashLoopStrike(date: now, build: currentBuild))
        }
        let evaluation = CrashLoopRules.evaluate(strikes: strikes, now: now, build: currentBuild)
        store(evaluation.strikes)
        defaults.set(true, forKey: Key.hasLaunched)
        defaults.set(currentBuild, forKey: Key.lastBuild)
        defaults.set(false, forKey: Key.cleanExit)

        if evaluation.tripped { disableExperimentalBarFeatures(build: currentBuild) }

        #if canImport(UIKit)
        observers.append(notificationCenter.addObserver(forName: UIApplication.didEnterBackgroundNotification,
                                                        object: nil, queue: nil) { [weak self] _ in
            self?.defaults.set(true, forKey: Key.cleanExit)
        })
        // Back in the foreground the run is live again: a crash from here on must count.
        observers.append(notificationCenter.addObserver(forName: UIApplication.willEnterForegroundNotification,
                                                        object: nil, queue: nil) { [weak self] _ in
            self?.defaults.set(false, forKey: Key.cleanExit)
        })
        observers.append(notificationCenter.addObserver(forName: UIApplication.willTerminateNotification,
                                                        object: nil, queue: nil) { [weak self] _ in
            self?.defaults.set(true, forKey: Key.cleanExit)
        })
        #endif
    }

    private func storedStrikes() -> [CrashLoopStrike] {
        guard let data = defaults.data(forKey: Key.strikes),
              let value = try? JSONDecoder().decode([CrashLoopStrike].self, from: data) else { return [] }
        return value
    }

    private func store(_ strikes: [CrashLoopStrike]) {
        if let data = try? JSONEncoder().encode(strikes) { defaults.set(data, forKey: Key.strikes) }
    }

    private func disableExperimentalBarFeatures(build: String) {
        let keys = [ItBarLab.hapticsKey, ItBarLab.scrollTopKey, ItBarLab.keyboardCurveKey,
                    ItBarLab.largeLabelsKey, ItBarLab.searchDrawerKey]
        let enabled = keys.filter { defaults.bool(forKey: $0) }
        enabled.forEach { defaults.set(false, forKey: $0) }
        // A kill while backgrounded, or around an iOS update, can resemble a crash. Requiring two same-build strikes
        // inside 24 hours absorbs that ambiguity before changing any experimental setting.
        AppDiagnostics.shared.record(category: "CrashLoopBreaker",
                                     message: "Two unclean exits within 24 h on build \(build); switched off Bar lab keys: "
                                        + (enabled.isEmpty ? "none were enabled" : enabled.joined(separator: ", ")))
    }
}
#endif
