//
//  SignUpLogic.swift
//  MowologyCRM
//
//  Pure logic for the iPhone sign-up, a port of the Android SignUpLogic.kt / PasswordGen.kt (same rules, same exact
//  strings). No SwiftUI, no network: the logic checks compile this file on the Mac.
//  Server: POST /api/auth/signup.php (code by email) and /api/auth/signup-confirm.php (code -> claim -> token).
//

import Foundation

enum SignUpLogic {
    struct Input: Equatable {
        var firstName = "", lastName = "", mobile = "", email = "", password = "", confirm = ""
        /// "I agree to the Terms and Privacy Policy": required, never pre-ticked.
        var termsAccepted = false
    }

    static let codeLength = 6
    static let serverProblem = "The server had a problem. Nothing you entered is wrong — try again in a minute."
    /// The same wording sign-in uses for a 429 (Android NativeAuthLogic.SignInError.RATE_LIMITED / NETWORK).
    static let rateLimited = "Too many attempts. Try again in 10 minutes."
    static let network = "Could not reach the server. Check your connection and try again."

    /// 10 digits, a leading country 1 on 11 digits dropped; nil when it is not a 10-digit number.
    static func normaliseMobile(_ raw: String) -> String? {
        var digits = raw.filter { $0.isASCII && $0.isNumber }
        if digits.count == 11 && digits.hasPrefix("1") { digits.removeFirst() }
        return digits.count == 10 ? digits : nil
    }

    /// "(604) 555-0123" as the server stores it, for display while typing; digits only otherwise.
    static func formattedMobile(_ raw: String) -> String {
        guard let d = normaliseMobile(raw) else { return raw }
        let a = Array(d)
        return "(\(String(a[0..<3]))) \(String(a[3..<6]))-\(String(a[6..<10]))"
    }

    static func emailValid(_ raw: String) -> Bool {
        let email = raw.trimmingCharacters(in: .whitespacesAndNewlines)
        guard let at = email.firstIndex(of: "@") else { return false }
        let atPos = email.distance(from: email.startIndex, to: at)
        guard atPos > 0 else { return false }
        // Android: email.indexOf('.', at + 2) > at + 1
        let chars = Array(email)
        let from = atPos + 2
        guard from < chars.count else { return false }
        return chars[from...].contains(".")
    }

    /// Per-field messages keyed first_name, last_name, mobile, email, password, confirm.
    static func validate(_ i: Input) -> [String: String] {
        var e: [String: String] = [:]
        let f = i.firstName.trimmingCharacters(in: .whitespacesAndNewlines).count
        let l = i.lastName.trimmingCharacters(in: .whitespacesAndNewlines).count
        if !(1...40).contains(f) { e["first_name"] = "Enter your first name." }
        if !(1...40).contains(l) { e["last_name"] = "Enter your last name." }
        if normaliseMobile(i.mobile) == nil { e["mobile"] = "Enter your mobile number (10 digits)." }
        if !emailValid(i.email) { e["email"] = "Enter a valid email address." }
        if i.password.count < 10 { e["password"] = "Use at least 10 characters." }
        if i.confirm != i.password { e["confirm"] = "Passwords must match." }
        if !i.termsAccepted { e["terms"] = termsMessage }
        return e
    }

    static let termsMessage = "Accept the terms and privacy policy to continue."
    static let termsURL = "https://ittai.ca/terms/"
    static let privacyURL = "https://ittai.ca/privacy/"
    /// "Create my login" is enabled only when every field is valid AND the box is ticked.
    static func canSubmit(_ i: Input) -> Bool { validate(i).isEmpty }

    private static func names(_ i: Input) -> (String, String, String) {
        let first = i.firstName.trimmingCharacters(in: .whitespacesAndNewlines)
        let last = i.lastName.trimmingCharacters(in: .whitespacesAndNewlines)
        return (first, last, "\(first) \(last)")
    }

    static func normalisedEmail(_ raw: String) -> String { raw.trimmingCharacters(in: .whitespacesAndNewlines).lowercased() }

    /// signup.php body. `audience: "mobile"` (the same 30-day token as sign-in).
    static func body(_ i: Input) -> [String: Any] {
        let (first, last, full) = names(i)
        return ["first_name": first, "last_name": last, "full_name": full, "mobile": normaliseMobile(i.mobile) ?? "",
                "email": normalisedEmail(i.email), "password": i.password, "audience": "mobile", "terms_accepted": i.termsAccepted]
    }

    /// signup-confirm.php body: the sign-up fields again plus the code.
    static func confirmBody(_ i: Input, code: String) -> [String: Any] {
        let (first, last, full) = names(i)
        return ["email": normalisedEmail(i.email), "code": code, "first_name": first, "last_name": last, "full_name": full,
                "mobile": normaliseMobile(i.mobile) ?? "", "password": i.password, "audience": "mobile", "terms_accepted": i.termsAccepted]
    }

    static func serverMessage(_ status: Int) -> String {
        switch status {
        case 404: return "Sign-up is not available on this server yet."
        case 409: return "This business already has an owner login — sign in instead."
        case 429: return rateLimited
        case 502: return "The code could not be sent. Try again in a minute."
        case 0: return network
        default: return serverProblem
        }
    }

    /// `fields` from a 400 reply; full_name folds into first_name (first_name wins), as on Android.
    static func fieldErrors(_ json: [String: Any]?) -> [String: String] {
        guard let fields = json?["fields"] as? [String: Any] else { return [:] }
        var result: [String: String] = [:]
        for key in fields.keys.sorted() {
            guard let value = fields[key] as? String, !value.trimmingCharacters(in: .whitespaces).isEmpty else { continue }
            let field = (key == "full_name" || key == "first_name") ? "first_name" : key
            if result[field] == nil || key == "first_name" { result[field] = value }
        }
        return result
    }

    static func sent(_ json: [String: Any]?) -> [String] {
        (json?["sent"] as? [Any])?.compactMap { ($0 as? String).flatMap { $0.isEmpty ? nil : $0 } } ?? []
    }

    static func sentWords(_ sent: [String], email: String) -> String {
        let e = normalisedEmail(email)
        return sent.contains("sms") ? "We emailed a 6-digit code to \(e) and texted your phone" : "We emailed a 6-digit code to \(e)"
    }

    static func codeValid(_ value: String) -> Bool { value.count == codeLength && value.allSatisfy { $0.isASCII && $0.isNumber } }
    static func digitsOnly(_ value: String) -> String { String(value.filter { $0.isASCII && $0.isNumber }.prefix(codeLength)) }

    /// A wrong or expired code shows the server's own message under the boxes.
    static func codeError(_ json: [String: Any]?) -> String? {
        guard let err = json?["error"] as? String, err == "code_wrong" || err == "code_expired",
              let m = json?["message"] as? String, !m.isEmpty else { return nil }
        return m
    }
    static func codeExpired(_ json: [String: Any]?) -> Bool { (json?["error"] as? String) == "code_expired" }
    /// Sign-up success is HTTP 201 with `token`; `onboarding.next` names the first step to show.
    static func nextStep(_ json: [String: Any]?) -> String? {
        guard let n = (json?["onboarding"] as? [String: Any])?["next"] as? String, !n.isEmpty, n != "null" else { return nil }
        return n
    }
}

/// "Make a strong password for me": 16 characters, no look-alikes (no I, O, l, 0, 1), at least one upper, lower and
/// digit, shown as four groups of four. The caller supplies the randomness so the checks can be deterministic.
enum PasswordGen {
    static let upper = Array("ABCDEFGHJKLMNPQRSTUVWXYZ")
    static let lower = Array("abcdefghijkmnpqrstuvwxyz")
    static let digits = Array("23456789")

    static func generate(using next: () -> Int = { Int.random(in: 0..<Int.max) }) -> String {
        let alphabet = upper + lower + digits
        while true {
            let raw = (0..<16).map { _ in alphabet[next() % alphabet.count] }
            if raw.contains(where: upper.contains) && raw.contains(where: lower.contains) && raw.contains(where: digits.contains) {
                return stride(from: 0, to: 16, by: 4).map { String(raw[$0..<$0 + 4]) }.joined(separator: "-")
            }
        }
    }
}
