import Foundation

struct AppVersionInfo: Decodable {
    let version: String?
    let minVersion: String?
    let forceUpdate: Bool?
    let releaseNotes: String?
    let storeUrl: String?

    enum CodingKeys: String, CodingKey {
        case version
        case minVersion = "min_version"
        case forceUpdate = "force_update"
        case releaseNotes = "release_notes"
        case storeUrl = "store_url"
    }
}

struct VersionCheckResult {
    let mustUpdate: Bool
    let latestVersion: String
    let releaseNotes: String?
    let storeUrl: String?
}

final class VersionCheckService {
    static let shared = VersionCheckService()
    private init() {}

    func check() async -> VersionCheckResult {
        guard let url = URL(string: "https://mowology.ca/crm/api/app-version.php") else {
            return .noUpdate
        }

        do {
            let (data, _) = try await URLSession.shared.data(from: url)

            // Try nested format { "ios": { ... } }
            if let outer = try? JSONDecoder().decode([String: AppVersionInfo].self, from: data),
               let ios = outer["ios"] {
                return evaluate(ios)
            }

            // Flat format fallback
            if let flat = try? JSONDecoder().decode(AppVersionInfo.self, from: data) {
                return evaluate(flat)
            }
        } catch {}

        return .noUpdate
    }

    private func evaluate(_ info: AppVersionInfo) -> VersionCheckResult {
        let installed = Bundle.main.infoDictionary?["CFBundleShortVersionString"] as? String ?? "0.0.0"
        let minimum   = info.minVersion ?? info.version ?? "0.0.0"
        let force     = info.forceUpdate ?? false
        let mustUpdate = force || semverLessThan(installed, minimum)

        return VersionCheckResult(
            mustUpdate: mustUpdate,
            latestVersion: info.version ?? "",
            releaseNotes: info.releaseNotes,
            storeUrl: info.storeUrl
        )
    }

    private func semverLessThan(_ a: String, _ b: String) -> Bool {
        let av = a.split(separator: ".").compactMap { Int($0) }
        let bv = b.split(separator: ".").compactMap { Int($0) }
        for i in 0..<3 {
            let ai = i < av.count ? av[i] : 0
            let bi = i < bv.count ? bv[i] : 0
            if ai < bi { return true }
            if ai > bi { return false }
        }
        return false
    }
}

private extension VersionCheckResult {
    static let noUpdate = VersionCheckResult(mustUpdate: false, latestVersion: "", releaseNotes: nil, storeUrl: nil)
}
