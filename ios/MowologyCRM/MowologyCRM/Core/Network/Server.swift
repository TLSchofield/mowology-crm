import Foundation

/// The one place the app knows which business server it talks to.
enum Server {
    static let storageKey = "ittai.server.base"
    private static let defaultString = "https://mowology.ca"

    /// Scheme + host, no trailing slash. Invalid stored values never affect routing.
    static var base: URL {
        if let raw = UserDefaults.standard.string(forKey: storageKey),
           let candidate = URL(string: raw), isAllowed(candidate) { return candidate }
        guard let fallback = URL(string: defaultString) else {
            preconditionFailure("The built-in server address is invalid.")
        }
        return fallback
    }

    static var api: URL { base.appendingPathComponent("api") }

    /// Changes routing only after an explicit, validated directory or sign-up result.
    @discardableResult
    static func use(_ url: URL) -> Bool {
        guard isAllowed(url) else { return false }
        UserDefaults.standard.set(url.absoluteString, forKey: storageKey)
        return true
    }

    static func reset() { UserDefaults.standard.removeObject(forKey: storageKey) }

    static func isAllowed(_ url: URL) -> Bool {
        guard let components = URLComponents(url: url, resolvingAgainstBaseURL: false),
              components.scheme?.lowercased() == "https",
              components.user == nil, components.password == nil,
              components.port == nil, components.query == nil, components.fragment == nil,
              components.path.isEmpty,
              let host = components.host?.lowercased(), !host.isEmpty else { return false }
        return host == "mowology.ca" || host == "ittai.ca" || host.hasSuffix(".ittai.ca")
    }

    /// Relative paths follow the current business. API-provided absolute media URLs pass through.
    static func url(_ path: String) -> URL? {
        if path.contains("://") {
            return URL(string: path)
        }
        let rooted = path.hasPrefix("/") ? String(path.dropFirst()) : path
        return base.appendingPathComponent(rooted)
    }
}

struct DirectoryBusiness: Decodable, Equatable, Identifiable {
    let key: String
    let name: String
    let appURL: URL
    let test: Bool
    var id: String { key }

    enum CodingKeys: String, CodingKey { case key, name, appURL = "app_url", test }
}

enum DirectorySignInResult: Equatable {
    case authenticated(business: DirectoryBusiness, token: String, userJSON: Data, expiresIn: Int)
    case choose([DirectoryBusiness])
    case unauthorized
    case rateLimited
    case fallback
    case invalidResponse
}

enum DirectorySignInMapper {
    private struct Success: Decodable {
        let business: DirectoryBusiness
        let token: String
        let user: JSONValue
        let expiresIn: Int
        enum CodingKeys: String, CodingKey { case business, token, user, expiresIn = "expires_in" }
    }
    private struct Choice: Decodable { let choose: Bool; let businesses: [DirectoryBusiness] }

    static func map(status: Int?, data: Data?) -> DirectorySignInResult {
        guard let status else { return .fallback }
        if status == 401 { return .unauthorized }
        if status == 429 { return .rateLimited }
        guard status == 200 else { return .fallback }
        guard let data else { return .invalidResponse }
        let decoder = JSONDecoder()
        if let choice = try? decoder.decode(Choice.self, from: data), choice.choose {
            return choice.businesses.allSatisfy { Server.isAllowed($0.appURL) } ? .choose(choice.businesses) : .invalidResponse
        }
        guard let success = try? decoder.decode(Success.self, from: data),
              !success.token.isEmpty, Server.isAllowed(success.business.appURL),
              let userData = try? JSONEncoder().encode(success.user) else { return .invalidResponse }
        return .authenticated(business: success.business, token: success.token, userJSON: userData, expiresIn: success.expiresIn)
    }
}

struct DirectorySignInClient {
    typealias Fetcher = @Sendable (URLRequest) async throws -> (Data, URLResponse)
    let fetch: Fetcher

    func signIn(body: [String: String]) async -> DirectorySignInResult {
        guard let url = URL(string: "https://ittai.ca/api/sign-in.php"),
              let data = try? JSONSerialization.data(withJSONObject: body) else { return .fallback }
        var request = URLRequest(url: url)
        request.httpMethod = "POST"
        request.httpBody = data
        request.timeoutInterval = 15
        request.httpShouldHandleCookies = false
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        do {
            let (responseData, response) = try await fetch(request)
            return DirectorySignInMapper.map(status: (response as? HTTPURLResponse)?.statusCode, data: responseData)
        } catch { return .fallback }
    }
}

/// Codable JSON used only to carry the directory's user object into the app's existing `User` decoder.
private enum JSONValue: Codable {
    case object([String: JSONValue]), array([JSONValue]), string(String), number(Double), bool(Bool), null
    init(from decoder: Decoder) throws {
        let c = try decoder.singleValueContainer()
        if c.decodeNil() { self = .null }
        else if let v = try? c.decode([String: JSONValue].self) { self = .object(v) }
        else if let v = try? c.decode([JSONValue].self) { self = .array(v) }
        else if let v = try? c.decode(String.self) { self = .string(v) }
        else if let v = try? c.decode(Bool.self) { self = .bool(v) }
        else if let v = try? c.decode(Double.self) { self = .number(v) }
        else { throw DecodingError.dataCorruptedError(in: c, debugDescription: "Unsupported JSON value") }
    }
    func encode(to encoder: Encoder) throws {
        var c = encoder.singleValueContainer()
        switch self {
        case .object(let v): try c.encode(v); case .array(let v): try c.encode(v)
        case .string(let v): try c.encode(v); case .number(let v): try c.encode(v)
        case .bool(let v): try c.encode(v); case .null: try c.encodeNil()
        }
    }
}

enum TenantDataDecision {
    static func shouldClear(storedHost: URL?, newHost: URL) -> Bool {
        guard let storedHost else { return false }
        return storedHost.host?.lowercased() != newHost.host?.lowercased()
    }
}

/// Moves every durable pending-work file and its UserDefaults metadata into one
/// committed quarantine directory. A failed move is rolled back, so callers can
/// safely leave the current tenant active and retry later.
enum TenantDataQuarantine {
    static let metadataFilename = "pending-user-defaults.plist"

    static func commit(
        sources: [URL],
        metadata: [String: Any],
        to destination: URL,
        files: FileManager = .default
    ) throws {
        let parent = destination.deletingLastPathComponent()
        let staging = parent.appendingPathComponent(".staging-\(UUID().uuidString)", isDirectory: true)
        var moved: [(source: URL, staged: URL)] = []

        do {
            try files.createDirectory(at: parent, withIntermediateDirectories: true)
            try files.createDirectory(at: staging, withIntermediateDirectories: false)
            if !metadata.isEmpty {
                let data = try PropertyListSerialization.data(fromPropertyList: metadata, format: .binary, options: 0)
                try data.write(to: staging.appendingPathComponent(metadataFilename), options: [.atomic])
            }
            for source in sources where files.fileExists(atPath: source.path) {
                let staged = staging.appendingPathComponent(source.lastPathComponent)
                try files.moveItem(at: source, to: staged)
                moved.append((source, staged))
            }
            try files.moveItem(at: staging, to: destination)
        } catch {
            for item in moved.reversed() where files.fileExists(atPath: item.staged.path) {
                try? files.moveItem(at: item.staged, to: item.source)
            }
            try? files.removeItem(at: staging)
            throw error
        }
    }
}
