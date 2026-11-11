import Foundation

/// The one place the app knows which server it talks to. Today a constant; the shared sign-in
/// will supply it per business (one app for every business, step 7, 2026-11-09).
enum Server {
    /// Scheme + host, no trailing slash.
    static let base = URL(string: "https://mowology.ca")!

    /// Absolute URL for a web-root path such as "/crm/img/heads/otto.jpg". A path that already
    /// starts with "http" is returned as is (photo URLs from the API come both ways).
    static func url(_ path: String) -> URL? {
        if path.hasPrefix("http") { return URL(string: path) }
        let rooted = path.hasPrefix("/") ? path : "/" + path
        return URL(string: base.absoluteString + rooted)
    }

    /// Base of the JSON API: `base` + "/api".
    static var api: URL { base.appendingPathComponent("api") }
}
