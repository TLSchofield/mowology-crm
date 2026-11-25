import Foundation

private var failures = 0
private func expect(_ condition: @autoclosure () -> Bool, _ message: String) {
    if !condition() { failures += 1; print("FAIL: \(message)") }
}
private func url(_ value: String) -> URL {
    guard let result = URL(string: value) else { preconditionFailure("Bad test URL") }
    return result
}
private func response(_ status: Int) -> HTTPURLResponse {
    guard let result = HTTPURLResponse(url: url("https://ittai.ca/api/sign-in.php"), statusCode: status,
                                       httpVersion: nil, headerFields: nil) else {
        preconditionFailure("Could not make test response")
    }
    return result
}

@main
struct ServerHostTests {
    static func main() async {
        let accepted = ["https://mowology.ca", "https://ittai.ca", "https://garden.ittai.ca"]
        let rejected = [
            "http://mowology.ca", "https://evil.ca", "https://ittai.ca.evil.ca",
            "https://user@ittai.ca", "https://ittai.ca:443", "https://ittai.ca/",
            "https://ittai.ca/path", "https://ittai.ca?q=x", "https://ittai.ca#x"
        ]
        accepted.forEach { expect(Server.isAllowed(url($0)), "accept \($0)") }
        rejected.forEach { expect(!Server.isAllowed(url($0)), "reject \($0)") }

        UserDefaults.standard.removeObject(forKey: Server.storageKey)
        expect(Server.base.host == "mowology.ca", "default host")
        expect(Server.use(url("https://acme.ittai.ca")), "store valid host")
        expect(Server.base.host == "acme.ittai.ca", "read stored host")
        Server.reset()
        expect(Server.base.host == "mowology.ca", "reset host")
        UserDefaults.standard.set("https://evil.ca", forKey: Server.storageKey)
        expect(Server.base.host == "mowology.ca", "ignore invalid stored host")
        Server.reset()

        let success = #"{"business":{"key":"one","name":"One","app_url":"https://one.ittai.ca","test":false},"token":"placeholder","user":{"id":1,"name":"Placeholder"},"expires_in":3600}"#.data(using: .utf8) ?? Data()
        let choose = #"{"choose":true,"businesses":[{"key":"one","name":"One","app_url":"https://one.ittai.ca","test":false}]}"#.data(using: .utf8) ?? Data()
        await check(status: 200, data: success) { if case .authenticated = $0 { return true }; return false }
        await check(status: 200, data: choose) { if case .choose = $0 { return true }; return false }
        await check(status: 401, data: Data()) { $0 == .unauthorized }
        await check(status: 429, data: Data()) { $0 == .rateLimited }
        await check(status: 503, data: Data()) { $0 == .fallback }

        struct NetworkFailure: Error {}
        let network = DirectorySignInClient { _ in throw NetworkFailure() }
        let networkResult = await network.signIn(body: ["password": "placeholder"])
        expect(networkResult == .fallback, "network falls back")

        expect(!TenantDataDecision.shouldClear(storedHost: url("https://one.ittai.ca"), newHost: url("https://one.ittai.ca")), "same host keeps data")
        expect(TenantDataDecision.shouldClear(storedHost: url("https://one.ittai.ca"), newHost: url("https://two.ittai.ca")), "different host clears and quarantines")
        checkQuarantineCommit()
        checkQuarantineRollback()

        if failures > 0 { exit(1) }
        print("ServerHostTests passed")
    }

    private static func checkQuarantineCommit() {
        let files = FileManager.default
        let root = files.temporaryDirectory.appendingPathComponent("tenant-quarantine-success-\(UUID().uuidString)", isDirectory: true)
        defer { try? files.removeItem(at: root) }
        let source = root.appendingPathComponent("UploadQueue", isDirectory: true)
        let payload = source.appendingPathComponent("receipt.jpg")
        let destination = root.appendingPathComponent("Quarantine/old-host", isDirectory: true)
        do {
            try files.createDirectory(at: source, withIntermediateDirectories: true)
            try Data("receipt".utf8).write(to: payload)
            try TenantDataQuarantine.commit(
                sources: [source],
                metadata: ["mw.receipt.queue.v1": Data("record".utf8)],
                to: destination,
                files: files
            )
            expect(!files.fileExists(atPath: source.path), "successful quarantine removes live payload directory")
            expect(files.fileExists(atPath: destination.appendingPathComponent("UploadQueue/receipt.jpg").path), "successful quarantine preserves payload")
            let metadataURL = destination.appendingPathComponent(TenantDataQuarantine.metadataFilename)
            let metadataData = try Data(contentsOf: metadataURL)
            let plist = try PropertyListSerialization.propertyList(from: metadataData, options: [], format: nil) as? [String: Any]
            expect(plist?["mw.receipt.queue.v1"] as? Data == Data("record".utf8), "successful quarantine preserves queue metadata")
        } catch {
            expect(false, "successful quarantine threw: \(error)")
        }
    }

    private static func checkQuarantineRollback() {
        let files = FileManager.default
        let root = files.temporaryDirectory.appendingPathComponent("tenant-quarantine-failure-\(UUID().uuidString)", isDirectory: true)
        defer { try? files.removeItem(at: root) }
        let first = root.appendingPathComponent("one/queue.json")
        let second = root.appendingPathComponent("two/queue.json")
        let destination = root.appendingPathComponent("Quarantine/old-host", isDirectory: true)
        do {
            try files.createDirectory(at: first.deletingLastPathComponent(), withIntermediateDirectories: true)
            try files.createDirectory(at: second.deletingLastPathComponent(), withIntermediateDirectories: true)
            try Data("first".utf8).write(to: first)
            try Data("second".utf8).write(to: second)
            do {
                try TenantDataQuarantine.commit(sources: [first, second], metadata: [:], to: destination, files: files)
                expect(false, "colliding quarantine sources must fail")
            } catch {
                expect(files.fileExists(atPath: first.path), "failed quarantine restores already-moved payload")
                expect(files.fileExists(atPath: second.path), "failed quarantine leaves unmoved payload")
                expect(!files.fileExists(atPath: destination.path), "failed quarantine does not publish partial data")
            }
        } catch {
            expect(false, "rollback test setup threw: \(error)")
        }
    }

    private static func check(status: Int, data: Data, matches: @escaping (DirectorySignInResult) -> Bool) async {
        let client = DirectorySignInClient { _ in (data, response(status)) }
        let result = await client.signIn(body: ["password": "placeholder"])
        expect(matches(result), "directory status \(status)")
    }
}
