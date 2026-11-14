//
//  BusinessOnboardingLogic.swift
//  MowologyCRM
//
//  Pure logic for the five-step onboarding of a new business: a port of Android OnboardingLogic.kt (same endpoints,
//  same bodies, same step order and labels). Server: GET/POST /api/onboarding/map.php, POST /api/onboarding/step.php,
//  GET /api/onboarding/quickbooks-start.php. No SwiftUI: compiled by the logic checks.
//

import Foundation

enum BusinessOnboardingLogic {
    enum State: String { case done, next, todo, skipped, notNeeded = "not_needed"
        var word: String {
            switch self { case .done: return "Done"; case .next: return "Next"; case .todo: return "To do"
            case .skipped: return "Skipped"; case .notNeeded: return "Not needed" }
        }
    }
    struct Step { let key: String; let title: String; let state: State; let head: String?; let skippable: Bool; let data: [String: Any] }
    struct MapState { let steps: [Step]; let next: String?; let readyToFinish: Bool }
    struct Quiet: Equatable { let from: String, to: String, weekends: Bool }
    struct NameData { let first, last, preferred, mobile, channel: String; let quiet: Quiet?; let nextPart: String? }
    struct CompanyData { let enteredName, legalName, operatingName, registrationNumber, status: String; let confirmed: Bool }
    struct Match: Equatable { let name, registrationNumber, status, recordId: String }
    struct QuickBooksData { let answer: String?; let connected: Bool }
    struct Invite: Equatable { let link, expiresAt, name: String }
    struct AxisMark: Equatable { let key, number, title: String; let state: State }

    /// The new steps this app can show (OnboardingService::NEW_STEPS). Only `terms` for now: the server leaves out a
    /// new step an app does not declare, so gst / yearend / payroll / contacts stay out of the path until built.
    static let knownSteps = "terms"
    static let termsQuestion = "Before we start: here are Ittai's terms and privacy policy. Please read them and accept to carry on."
    static let defaultTermsURL = "https://ittai.ca/terms/"
    static let defaultPrivacyURL = "https://ittai.ca/privacy/"
    struct TermsData: Equatable { let accepted: Bool; let version, termsURL, privacyURL: String }
    static func termsData(_ j: [String: Any]?) -> TermsData {
        let t = str(j, "terms_url"), p = str(j, "privacy_url")
        return TermsData(accepted: bool(j, "accepted"), version: str(j, "version"),
                         termsURL: t.isEmpty ? defaultTermsURL : t, privacyURL: p.isEmpty ? defaultPrivacyURL : p)
    }
    /// Blocking: the terms step has no skip; only the server's own "accepted" lets the person through.
    static func termsAccepted(_ step: Step?) -> Bool { step.map { $0.state == .done || termsData($0.data).accepted } ?? false }
    static func skippable(_ step: Step) -> Bool { step.key != "terms" && step.skippable }

    static let mapPath = "/api/onboarding/map.php"
    static let stepPath = "/api/onboarding/step.php"
    static let quickBooksPath = "/api/onboarding/quickbooks-start.php"

    // JSON helpers: a JSON null must never show as "null" in a field (Android Fable review).
    private static func str(_ o: [String: Any]?, _ key: String) -> String {
        guard let v = o?[key], !(v is NSNull) else { return "" }
        return (v as? String) ?? ""
    }
    private static func nullable(_ o: [String: Any]?, _ key: String) -> String? {
        let s = str(o, key)
        return (s.isEmpty || s == "null") ? nil : s
    }
    private static func bool(_ o: [String: Any]?, _ key: String) -> Bool { (o?[key] as? Bool) ?? ((o?[key] as? NSNumber)?.boolValue ?? false) }

    static func parse(_ json: [String: Any]?) -> MapState? {
        guard let json, bool(json, "ok"), let arr = json["steps"] as? [[String: Any]] else { return nil }
        let steps: [Step] = arr.compactMap { o in
            let key = str(o, "key")
            guard !key.isEmpty, let state = State(rawValue: str(o, "state").lowercased()) else { return nil }
            let title = str(o, "title")
            return Step(key: key, title: title.isEmpty ? key.prefix(1).uppercased() + key.dropFirst() : title, state: state,
                        head: nullable(o, "head"), skippable: bool(o, "skippable"), data: (o["data"] as? [String: Any]) ?? [:])
        }
        return MapState(steps: steps, next: nullable(json, "next"), readyToFinish: bool(json, "ready_to_finish"))
    }

    static func tappable(_ s: Step) -> Bool { s.state == .next || s.state == .todo }
    static func mapBody(key: String, action: String) -> [String: Any] { ["key": key, "do": action, "knows": knownSteps] }
    static func stepBody(key: String, action: String, fields: [String: Any] = [:]) -> [String: Any] {
        var b: [String: Any] = ["key": key, "do": action, "knows": knownSteps]
        for (k, v) in fields { b[k] = v }
        return b
    }

    static func nameData(_ j: [String: Any]?) -> NameData {
        let q = j?["quiet"] as? [String: Any]
        return NameData(first: str(j, "first"), last: str(j, "last"), preferred: str(j, "preferred"), mobile: str(j, "mobile"),
                        channel: str(j, "channel"),
                        quiet: q.map { Quiet(from: str($0, "from"), to: str($0, "to"), weekends: bool($0, "weekends")) },
                        nextPart: nullable(j, "next_part"))
    }
    static func companyData(_ j: [String: Any]?) -> CompanyData {
        CompanyData(enteredName: str(j, "entered_name"), legalName: str(j, "legal_name"), operatingName: str(j, "operating_name"),
                    registrationNumber: str(j, "registration_number"), status: str(j, "status"), confirmed: bool(j, "confirmed"))
    }
    static func matches(_ j: [String: Any]?) -> [Match] {
        (j?["matches"] as? [[String: Any]])?.map {
            Match(name: str($0, "name"), registrationNumber: str($0, "registration_number"), status: str($0, "status"), recordId: str($0, "record_id"))
        } ?? []
    }
    static func quickBooksData(_ j: [String: Any]?) -> QuickBooksData { QuickBooksData(answer: nullable(j, "answer"), connected: bool(j, "connected")) }
    static func invitedName(_ j: [String: Any]?) -> String? { nullable(j, "invited_name") }
    static func invite(_ j: [String: Any]?) -> Invite? {
        guard let o = j?["invite"] as? [String: Any], !str(o, "link").isEmpty else { return nil }
        return Invite(link: str(o, "link"), expiresAt: str(o, "expires_at"), name: str(o, "name"))
    }
    /// "You're set up" only when the server's done step says complete; a missing done step is not finished.
    static func finishComplete(_ done: Step?) -> Bool { done.map { bool($0.data, "complete") } ?? false }
    static func doneReady(_ done: Step?) -> Bool { done.map { bool($0.data, "ready") } ?? false }

    /// The registry could not be reached (anything but "ok" in a search reply that carries a registry word).
    static func registryUnreachable(_ j: [String: Any]?) -> Bool { let r = str(j, "registry"); return !r.isEmpty && r != "ok" }

    /// The screen to show: the chosen step, else the server's next step, else Done.
    static func screenKey(current: String?, map: MapState) -> String { current ?? map.next ?? "done" }
    static func firstScreen(map: MapState, requested: String?) -> String? {
        if let r = requested, r != "done", map.steps.contains(where: { $0.key == r && $0.state != .done }) { return r }
        return map.next
    }

    /// Replies that carry one-use UI data stay on their step until the person taps Continue.
    static func holdsReply(key: String, action: String) -> Bool {
        (key == "company" && ["search", "confirm", "manual"].contains(action)) || (key == "accountant" && action == "invite")
    }

    static func validTime(_ v: String) -> Bool { v.range(of: #"^(?:[01]\d|2[0-3]):[0-5]\d$"#, options: .regularExpression) != nil }

    static func inviteShareText(_ invite: Invite, business: String) -> String {
        "Here's your invitation to \(business) on Ittai: \(invite.link) (expires \(invite.expiresAt))"
    }

    /// English heading when the server gives no title (owner 2026-11-13: no kanji in onboarding).
    static func fallbackTitle(_ key: String) -> String {
        switch key {
        case "name": return "The next parts are all about you."; case "terms": return "Terms"; case "company": return "Company"
        case "quickbooks", "books": return "Books"; case "accountant": return "Accountant"; case "done": return "Done"
        default: return key.prefix(1).uppercased() + key.dropFirst()
        }
    }
    /// Numbers 01, 02, ... (not-needed steps are left out, so "Your books" vanishes when QuickBooks is yes), each with
    /// the server's step title (else the English fallback). The heading reads "01  About you".
    static func axisMarks(_ map: MapState) -> [AxisMark] {
        map.steps.filter { $0.state != .notNeeded }.enumerated().map {
            let t = $0.element.title.trimmingCharacters(in: .whitespaces)
            return AxisMark(key: $0.element.key, number: String(format: "%02d", $0.offset + 1),
                            title: t.isEmpty ? fallbackTitle($0.element.key) : t, state: $0.element.state)
        }
    }

    /// The one-line answer shown under a finished step.
    static func answer(_ step: Step) -> String? {
        switch step.key {
        case "name": let n = nameData(step.data); let a = n.preferred.isEmpty ? n.first : n.preferred; return a.isEmpty ? nil : a
        case "company":
            let c = companyData(step.data)
            let raw = [c.legalName, c.operatingName, c.enteredName].first { !$0.isEmpty } ?? ""
            return raw.isEmpty ? nil : displayCase(raw)
        case "quickbooks": let q = quickBooksData(step.data); return q.connected ? "QuickBooks — connected" : (q.answer == "no" ? "QuickBooks — not used" : nil)
        case "books": return "Penny keeps the books"
        case "accountant": return invitedName(step.data) != nil ? "Accountant invited" : nil
        default: return nil
        }
    }

    private static let preserved: Set<String> = ["BC", "AB", "SK", "MB", "ON", "QC", "NB", "NS", "PE", "NL", "YT", "NT", "NU", "GST", "PST", "HST", "LLP", "LLC", "ULC", "II", "III", "IV", "BCS"]
    /// Registry names come in capitals: "ACME LANDSCAPING LTD." -> "Acme Landscaping Ltd.", keeping BC, LLP, numbers.
    static func displayCase(_ name: String) -> String {
        name.split(separator: " ", omittingEmptySubsequences: true).map { sub -> String in
            let token = String(sub)
            let bare = token.trimmingCharacters(in: CharacterSet.alphanumerics.inverted)
            let roman = !bare.isEmpty && bare.allSatisfy { "IVXLCDM".contains($0) }
            if bare.contains(where: { $0.isNumber }) || preserved.contains(bare) || roman { return token }
            var chars = Array(token.lowercased())
            if let i = chars.firstIndex(where: { $0.isLetter }) { chars[i] = Character(String(chars[i]).uppercased()) }
            if chars.count > 2, chars[1] == "'", chars[0].isLetter { chars[2] = Character(String(chars[2]).uppercased()) }
            return String(chars)
        }.joined(separator: " ")
    }

    struct Head: Equatable { let slug, name, role: String }
    /// "Your team" on the Done step (Android OfficeLogic.heads): the chief's name comes from the sign-up reply.
    static func heads(chiefName: String) -> [Head] {
        let chief = chiefName.trimmingCharacters(in: .whitespaces).isEmpty ? "Kaname" : chiefName
        return [Head(slug: "charlie", name: chief, role: "Chief of Staff"), Head(slug: "yui", name: "Yui", role: "Comms"),
                Head(slug: "sam", name: "Sam", role: "Sales"), Head(slug: "penny", name: "Penny", role: "Bookkeeper"),
                Head(slug: "otto", name: "Otto", role: "Operations"), Head(slug: "mia", name: "Mia", role: "Marketing"),
                Head(slug: "ada", name: "Ada", role: "Technology")]
    }

    static func finishSummary(_ map: MapState) -> [(String, String)] {
        let company = map.steps.first { $0.key == "company" }.flatMap(answer) ?? ""
        let qb = map.steps.first { $0.key == "quickbooks" }.map { quickBooksData($0.data) }
        let acct = map.steps.first { $0.key == "accountant" }.flatMap { invitedName($0.data) }
        var rows: [(String, String)] = []
        if let t = termsSummaryLine(map) { rows.append(t) }
        rows += [("Business", company),
                ("QuickBooks", qb?.connected == true ? "Connected" : (qb?.answer == "no" ? "Not used" : "Later")),
                ("Accountant", acct != nil ? "Invited" : "Later")]
        return rows
    }

    /// The Done review's first row when the terms step is in the path (Android OnboardingTermsLogic.summaryLine).
    static func termsSummaryLine(_ map: MapState) -> (String, String)? {
        guard let s = map.steps.first(where: { $0.key == "terms" && $0.state != .notNeeded }) else { return nil }
        return ("Terms", termsData(s.data).accepted || s.state == .done ? "Accepted" : "Not yet")
    }
}
