import Foundation

var failures = 0
func check<T: Equatable>(_ got: T, _ want: T, _ label: String) {
    if got != want { failures += 1; print("FAIL \(label): got \(got), want \(want)") }
}
func json(_ s: String) -> [String: Any] { (try! JSONSerialization.jsonObject(with: Data(s.utf8))) as! [String: Any] }
typealias S = SignUpLogic
typealias B = BusinessOnboardingLogic

// Mobile: 10 digits, leading 1 on 11 digits dropped.
check(S.normaliseMobile("(604) 555-0123"), "6045550123", "mobile punctuation")
check(S.normaliseMobile("1 604 555 0123"), "6045550123", "mobile leading 1")
check(S.normaliseMobile("604555012"), nil, "mobile 9 digits")
check(S.normaliseMobile("26045550123"), nil, "mobile 11 digits not 1")
check(S.formattedMobile("6045550123"), "(604) 555-0123", "mobile format")
// Email
check(S.emailValid("a@b.co"), true, "email ok"); check(S.emailValid("@b.co"), false, "email no local")
check(S.emailValid("a@b"), false, "email no dot"); check(S.emailValid("a@.co"), false, "email dot right after @")
// Validation
var i = S.Input(firstName: "Sam", lastName: "Lee", mobile: "6045550123", email: "sam@x.ca", password: "0123456789", confirm: "0123456789")
check(i.termsAccepted, false, "terms unticked by default")
check(S.validate(i), ["terms": "Accept the terms and privacy policy to continue."], "unticked terms is the only error")
check(S.canSubmit(i), false, "button disabled while unticked")
i.termsAccepted = true
check(S.validate(i), [:], "valid input"); check(S.canSubmit(i), true, "button enabled when valid and ticked")
var bad = i; bad.email = "nope"; check(S.canSubmit(bad), false, "ticked but invalid -> disabled")
i.password = "short"; i.confirm = "other"
check(S.validate(i), ["password": "Use at least 10 characters.", "confirm": "Passwords must match."], "password rules")
check(S.validate(S.Input())["first_name"], "Enter your first name.", "first name empty")
check(S.validate(S.Input(firstName: String(repeating: "a", count: 41)))["first_name"], "Enter your first name.", "first name 41")
check(S.validate(S.Input())["mobile"], "Enter your mobile number (10 digits).", "mobile msg")
// Bodies
i = S.Input(firstName: " Sam ", lastName: "Lee", mobile: "1 (604) 555-0123", email: " Sam@X.CA ", password: "0123456789", confirm: "0123456789", termsAccepted: true)
let b = S.body(i)
check(b["email"] as? String, "sam@x.ca", "body email lowercased"); check(b["mobile"] as? String, "6045550123", "body mobile")
check(b["full_name"] as? String, "Sam Lee", "body full name"); check(b["audience"] as? String, "mobile", "body audience")
check(Set(b.keys), ["first_name", "last_name", "full_name", "mobile", "email", "password", "audience", "terms_accepted"], "body keys")
check(b["terms_accepted"] as? Bool, true, "sign-up carries terms_accepted true (JSON boolean)")
let cb = S.confirmBody(i, code: "123456")
check(cb["code"] as? String, "123456", "confirm code"); check(cb["terms_accepted"] as? Bool, true, "confirm carries terms_accepted true"); check(Set(cb.keys), Set(b.keys).union(["code"]), "confirm keys")
// Server messages
check(S.serverMessage(404), "Sign-up is not available on this server yet.", "404")
check(S.serverMessage(409), "This business already has an owner login — sign in instead.", "409")
check(S.serverMessage(502), "The code could not be sent. Try again in a minute.", "502")
check(S.serverMessage(500), S.serverProblem, "500")
check(S.fieldErrors(json(#"{"fields":{"full_name":"Enter your full name.","email":"Use the email address this business was set up with."}}"#)),
      ["first_name": "Enter your full name.", "email": "Use the email address this business was set up with."], "field errors fold full_name")
check(S.fieldErrors(json(#"{"fields":{"full_name":"a","first_name":"b"}}"#)), ["first_name": "b"], "first_name wins")
// Code
check(S.sentWords(S.sent(json(#"{"ok":true,"sent":["email"],"expires_in":900}"#)), email: " A@B.CA "), "We emailed a 6-digit code to a@b.ca", "sent words")
check(S.sentWords(["email", "sms"], email: "a@b.ca"), "We emailed a 6-digit code to a@b.ca and texted your phone", "sent words sms")
check(S.codeValid("123456"), true, "code ok"); check(S.codeValid("12345"), false, "code short"); check(S.codeValid("12345a"), false, "code letter")
check(S.digitsOnly("12 34-5678"), "123456", "digits only caps at 6")
check(S.codeError(json(#"{"ok":false,"error":"code_wrong","message":"That code isn't right."}"#)), "That code isn't right.", "code wrong")
check(S.codeExpired(json(#"{"error":"code_expired","message":"x"}"#)), true, "code expired")
check(S.codeError(json(#"{"error":"server"}"#)), nil, "other error not a code error")
check(S.nextStep(json(#"{"ok":true,"onboarding":{"next":"name"}}"#)), "name", "next step")
check(S.nextStep(json(#"{"ok":true,"onboarding":{"next":null}}"#)), nil, "next null")
check(S.fieldErrors(json(#"{"fields":{"terms":"Accept the terms and privacy policy to continue."}}"#)), ["terms": "Accept the terms and privacy policy to continue."], "server terms error shown")
// Password generator
var seed = 7
let pw = PasswordGen.generate(using: { seed = (seed &* 1103515245 &+ 12345) & 0x7fffffff; return seed })
check(pw.count, 19, "pw length 16 + 3 dashes"); check(pw.split(separator: "-").map(\.count), [4, 4, 4, 4], "pw groups")
check(pw.contains { "01IOl".contains($0) }, false, "pw no look-alikes")
check(pw.contains { $0.isUppercase } && pw.contains { $0.isLowercase } && pw.contains { $0.isNumber }, true, "pw classes")
check(pw.replacingOccurrences(of: "-", with: "").count >= 10, true, "pw passes 10+ rule")

// Onboarding map (the five steps; books present when QuickBooks is No)
let map = B.parse(json(#"""
{"ok":true,"next":"name","ready_to_finish":false,"steps":[
 {"key":"name","title":"About you","state":"next","head":"Kaname","skippable":false,"data":{"first":"Sam","last":"Lee","preferred":"","mobile":"(604) 555-0123","channel":"text","quiet":null,"next_part":"quiet"}},
 {"key":"company","title":"Company","state":"todo","head":"Penny","skippable":false,"data":{"entered_name":null,"legal_name":"ACME LANDSCAPING LTD.","operating_name":null,"confirmed":false}},
 {"key":"quickbooks","title":"QuickBooks","state":"todo","head":"Penny","skippable":true,"data":{"answer":null,"connected":false}},
 {"key":"books","title":"Your books","state":"not_needed","head":"Penny","skippable":false,"data":{"acknowledged":false}},
 {"key":"accountant","title":"Accountant","state":"todo","head":"Penny","skippable":true,"data":{"invited_name":null,"logins":0}},
 {"key":"done","title":"Done","state":"todo","head":"","skippable":false,"data":{"ready":false,"complete":false}}]}
"""#))!
check(map.steps.map(\.key), ["name", "company", "quickbooks", "books", "accountant", "done"], "step order")
check(map.next, "name", "map next")
check(B.axisMarks(map).map { "\($0.number) \($0.title)" }, ["01 About you", "02 Company", "03 QuickBooks", "04 Accountant", "05 Done"], "axis headings: number + server title, not-needed skipped")
check(B.nameData(map.steps[0].data).nextPart, "quiet", "name next part"); check(B.nameData(map.steps[0].data).quiet, nil, "quiet null")
check(B.answer(map.steps[1]), "Acme Landscaping Ltd.", "company answer cased")
check(B.firstScreen(map: map, requested: nil), "name", "first screen"); check(B.firstScreen(map: map, requested: "company"), "company", "requested step")
check(B.firstScreen(map: map, requested: "done"), "name", "done not requestable")
check(B.screenKey(current: nil, map: B.MapState(steps: [], next: nil, readyToFinish: true)), "done", "falls to done")
check(B.parse(json(#"{"ok":false}"#)) == nil, true, "not ok -> nil")
check(B.finishComplete(nil), false, "missing done not complete")
check(B.finishComplete(map.steps[5]), false, "done not complete"); check(B.doneReady(map.steps[5]), false, "not ready")
check(B.holdsReply(key: "company", action: "search"), true, "company search holds"); check(B.holdsReply(key: "quickbooks", action: "yes"), false, "yes advances")
check(B.holdsReply(key: "accountant", action: "invite"), true, "invite holds")
// Bodies (what each step posts)
func same(_ a: [String: Any], _ e: [String: Any]) -> Bool { var e = e; e["knows"] = "terms"; return NSDictionary(dictionary: a).isEqual(to: e) }
check(same(B.stepBody(key: "name", action: "quiet", fields: ["from": "20:00", "to": "07:00", "weekends": true]), ["key": "name", "do": "quiet", "from": "20:00", "to": "07:00", "weekends": true]), true, "quiet body")
check(same(B.stepBody(key: "company", action: "search", fields: ["name": "Acme"]), ["key": "company", "do": "search", "name": "Acme"]), true, "search body")
check(same(B.stepBody(key: "company", action: "confirm", fields: ["record_id": "r1"]), ["key": "company", "do": "confirm", "record_id": "r1"]), true, "confirm body")
check(same(B.stepBody(key: "company", action: "manual", fields: ["name": "Acme"]), ["key": "company", "do": "manual", "name": "Acme"]), true, "manual body")
check(same(B.stepBody(key: "accountant", action: "invite", fields: ["name": "Yui", "email": "y@x.ca"]), ["key": "accountant", "do": "invite", "name": "Yui", "email": "y@x.ca"]), true, "invite body")
check(same(B.stepBody(key: "books", action: "ack"), ["key": "books", "do": "ack"]), true, "books ack body")
check(same(B.stepBody(key: "done", action: "finish"), ["key": "done", "do": "finish"]), true, "finish body")
check(same(B.mapBody(key: "quickbooks", action: "skip"), ["key": "quickbooks", "do": "skip"]), true, "skip body")
check(B.validTime("20:00"), true, "time ok"); check(B.validTime("24:00"), false, "time 24"); check(B.validTime("7:00"), false, "time short")
// Terms step (blocking, declared via knows=terms)
check(B.knownSteps, "terms", "only terms is declared")
check(same(B.stepBody(key: "terms", action: "accept"), ["key": "terms", "do": "accept"]), true, "terms accept body")
let withTerms = B.parse(json(#"{"ok":true,"next":"terms","ready_to_finish":false,"steps":[{"key":"name","title":"About you","state":"done","head":"Kaname","skippable":false,"data":{}},{"key":"terms","title":"Terms","state":"next","head":"Penny","skippable":false,"data":{"accepted":false,"version":"2026-11-06","terms_url":"https://ittai.ca/terms/","privacy_url":"https://ittai.ca/privacy/"}},{"key":"company","title":"Company","state":"todo","head":"Penny","skippable":false,"data":{}}]}"#))!
check(withTerms.steps.map(\.key), ["name", "terms", "company"], "terms sits where the server puts it")
check(B.termsAccepted(withTerms.steps[1]), false, "terms not accepted"); check(B.termsAccepted(nil), false, "missing terms not accepted")
check(B.termsData(withTerms.steps[1].data), B.TermsData(accepted: false, version: "2026-11-06", termsURL: "https://ittai.ca/terms/", privacyURL: "https://ittai.ca/privacy/"), "terms data")
check(B.termsAccepted(B.Step(key: "terms", title: "Terms", state: .done, head: nil, skippable: false, data: ["accepted": true])), true, "terms accepted when done")
check(B.skippable(B.Step(key: "terms", title: "Terms", state: .next, head: nil, skippable: true, data: [:])), false, "terms never skippable even if flagged")
check(B.skippable(map.steps[2]), true, "quickbooks skippable")
check(B.axisMarks(withTerms).map { "\($0.number) \($0.title)" }, ["01 About you", "02 Terms", "03 Company"], "terms numbered in server order")
check(B.fallbackTitle("terms"), "Terms", "fallback title"); check(B.fallbackTitle("done"), "Done", "fallback done")
check(B.axisMarks(withTerms).map { $0.title }.joined().unicodeScalars.allSatisfy { $0.isASCII }, true, "no kanji in headings")
check(B.termsQuestion, "Before we start: here are Ittai's terms and privacy policy. Please read them and accept to carry on.", "terms question")
check(B.heads(chiefName: "").first?.name, "Kaname", "chief default"); check(B.heads(chiefName: "Zen").first?.name, "Zen", "chief name"); check(B.heads(chiefName: "").count, 7, "seven heads")

// Done review: Terms row first, same wording as Android
check(B.finishSummary(withTerms).map { "\($0.0)=\($0.1)" }.first, "Terms=Not yet", "terms row first, not yet")
let acceptedMap = B.parse(json(#"{"ok":true,"next":null,"ready_to_finish":true,"steps":[{"key":"terms","title":"Terms","state":"done","head":"Penny","skippable":false,"data":{"accepted":true}}]}"#))!
check(B.finishSummary(acceptedMap).first.map { "\($0.0)=\($0.1)" }, "Terms=Accepted", "terms row accepted")
check(B.finishSummary(map).map { $0.0 }, ["Business", "QuickBooks", "Accountant"], "no terms row when the step is not in the path")
check(B.finishSummary(acceptedMap).map { $0.0 }, ["Terms", "Business", "QuickBooks", "Accountant"], "order Terms, Business, QuickBooks, Accountant")

// Company search reply
let reply = json(#"{"ok":true,"next":"company","data":{"entered_name":"Acme","matches":[{"name":"Acme Landscaping","registration_number":"BC123","status":"Active","record_id":"r1"}],"registry":"ok"}}"#)
check(B.matches(reply["data"] as? [String: Any]), [B.Match(name: "Acme Landscaping", registrationNumber: "BC123", status: "Active", recordId: "r1")], "matches")
check(B.registryUnreachable(reply["data"] as? [String: Any]), false, "registry ok")
check(B.registryUnreachable(json(#"{"registry":"unavailable"}"#)), true, "registry down")
check(B.invite(json(#"{"invite":{"link":"https://x/i/1","expires_at":"2026-12-01","name":"Yui"}}"#)), B.Invite(link: "https://x/i/1", expiresAt: "2026-12-01", name: "Yui"), "invite parse")
check(B.inviteShareText(B.Invite(link: "L", expiresAt: "E", name: "Yui"), business: "Acme"), "Here's your invitation to Acme on Ittai: L (expires E)", "share text")
check(B.displayCase("O'NEIL BC 2 LTD"), "O'Neil BC 2 Ltd", "display case")

if failures == 0 { print("signup + onboarding logic: all checks passed") } else { print("\(failures) FAILED"); exit(1) }
