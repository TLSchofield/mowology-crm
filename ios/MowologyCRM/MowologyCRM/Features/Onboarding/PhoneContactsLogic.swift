import Foundation

struct PhoneContact: Equatable, Identifiable {
    struct Phone: Equatable { let label: String; let number: String }
    struct Address: Equatable { let street: String; let city: String; let province: String; let postal: String }

    let id: String
    let first: String
    let last: String
    let company: String
    let emails: [String]
    let phones: [Phone]
    let address: Address?
}

struct PhoneContactResult: Decodable, Equatable {
    enum Outcome: String, Decodable { case created, matched, skipped }
    let ref: String
    let outcome: Outcome
    let contactId: Int?
    enum CodingKeys: String, CodingKey { case ref, outcome; case contactId = "contact_id" }
    init(ref: String, outcome: Outcome, contactId: Int?) { self.ref = ref; self.outcome = outcome; self.contactId = contactId }
}

enum PhoneContactsLogic {
    static func displayName(_ contact: PhoneContact) -> String {
        let person = [contact.first, contact.last].filter { !$0.isEmpty }.joined(separator: " ")
        return person.isEmpty ? contact.company : person
    }

    static func matches(_ contact: PhoneContact, search: String) -> Bool {
        let needle = search.trimmingCharacters(in: .whitespacesAndNewlines).lowercased()
        guard !needle.isEmpty else { return true }
        let text = [displayName(contact), contact.company] + contact.emails
        if text.contains(where: { $0.lowercased().contains(needle) }) { return true }
        let digits = needle.filter(\.isNumber)
        return !digits.isEmpty && contact.phones.contains { $0.number.filter(\.isNumber).contains(digits) }
    }

    static func selectingAllShown(selected: Set<String>, shown: [PhoneContact]) -> Set<String> {
        selected.union(shown.map(\.id))
    }

    static func clearingShown(selected: Set<String>, shown: [PhoneContact]) -> Set<String> {
        selected.subtracting(shown.map(\.id))
    }

    static func batches(_ contacts: [PhoneContact], size: Int = 100) -> [[PhoneContact]] {
        guard size > 0 else { return [] }
        return stride(from: 0, to: contacts.count, by: size).map {
            Array(contacts[$0..<min($0 + size, contacts.count)])
        }
    }

    static func wire(_ contact: PhoneContact) -> [String: Any] {
        let address: Any = contact.address.map {
            ["street": $0.street, "city": $0.city, "province": $0.province, "postal": $0.postal]
        } ?? NSNull()
        return [
            "ref": contact.id, "first": contact.first, "last": contact.last, "company": contact.company,
            "emails": contact.emails,
            "phones": contact.phones.map { ["label": $0.label, "number": $0.number] },
            "address": address
        ]
    }

    static func tally(_ results: [PhoneContactResult]) -> PhoneContactTally {
        results.reduce(into: PhoneContactTally()) { tally, result in
            switch result.outcome {
            case .created: tally.created += 1
            case .matched: tally.matched += 1
            case .skipped: tally.skipped += 1
            }
        }
    }

    static func nextBatch(totalBatches: Int, confirmedBatchCount: Int) -> Int? {
        guard confirmedBatchCount >= 0, confirmedBatchCount < totalBatches else { return nil }
        return confirmedBatchCount
    }
}

struct PhoneContactTally: Equatable {
    var created = 0
    var matched = 0
    var skipped = 0
}
