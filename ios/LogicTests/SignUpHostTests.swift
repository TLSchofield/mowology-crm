import Foundation

enum Server {
    static let base = URL(string: "https://ittai.ca")!
}

@MainActor
final class AuthSession {
    var token: String?
    func logout() {}
}

final class AppDiagnostics {
    static let shared = AppDiagnostics()
    func record(category: String, message: String) {}
}

enum ErrorDetails {
    static func describe(_ error: Error) -> String { String(describing: error) }
}

private func expect(_ condition: @autoclosure () -> Bool, _ message: String) {
    guard condition() else {
        FileHandle.standardError.write(Data("FAIL: \(message)\n".utf8))
        exit(1)
    }
}

private func resolve(_ resolver: DirectorySignUpHost) async -> Result<URL, SignUpHostError> {
    await resolver.baseURL(forEmail: "owner@example.test")
}

@main
struct SignUpHostTests {
    static func main() async {
        expect(DirectorySignUpHost.validatedBaseURL("https://claudia.ittai.ca")?.absoluteString == "https://claudia.ittai.ca", "accept tenant HTTPS host")
        for rejected in [
            "http://claudia.ittai.ca",
            "https://evil.com",
            "https://ittai.ca.evil.com",
            "https://claudia.ittai.ca:443",
            "https://placeholder@claudia.ittai.ca",
            "https://claudia.ittai.ca/path",
            ""
        ] {
            expect(DirectorySignUpHost.validatedBaseURL(rejected) == nil, "reject \(rejected)")
        }

        let successJSON = Data(#"{"business":{"key":"placeholder","name":"Placeholder","app_url":"https://claudia.ittai.ca","test":false}}"#.utf8)
        let success = await resolve(DirectorySignUpHost(fetch: { request in
            expect(request.httpMethod == "POST", "lookup uses POST")
            expect(request.timeoutInterval == 15, "lookup request timeout")
            expect(request.value(forHTTPHeaderField: "Authorization") == nil, "lookup has no authorization")
            return (successJSON, 200)
        }))
        expect(success == .success(URL(string: "https://claudia.ittai.ca")!), "map valid 200")

        let notAvailable = await resolve(DirectorySignUpHost(fetch: { _ in (Data(), 404) }))
        expect(notAvailable == .failure(.notAvailable), "map 404")
        let rateLimited = await resolve(DirectorySignUpHost(fetch: { _ in (Data(), 429) }))
        expect(rateLimited == .failure(.rateLimited), "map 429")

        enum PlaceholderError: Error { case failed }
        let network = await resolve(DirectorySignUpHost(fetch: { _ in throw PlaceholderError.failed }))
        expect(network == .failure(.network), "map network error")

        expect(SignUpLogic.businessNotAvailable == "We couldn't find a business set up for this email. Use the email your invitation went to.", "business message")
        expect(SignUpLogic.rateLimited == "Too many attempts. Try again in 10 minutes.", "rate-limit message")
        expect(SignUpLogic.network == "Could not reach the server. Check your connection and try again.", "network message")

        print("SignUpHostTests passed")
    }
}
