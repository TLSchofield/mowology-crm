import Foundation

struct OnboardingMapResponse: Decodable {
    let ok: Bool
    let steps: [OnboardingMapStep]
    let next: String?
    let readyToFinish: Bool
    let error: String?
    let message: String?
    enum CodingKeys: String, CodingKey { case ok, steps, next, error, message; case readyToFinish = "ready_to_finish" }
}

/// One row of the onboarding map. The server sends one `data` shape per step (and an empty `[]` when a step has
/// none), and every step shares this list, so nothing here may fail on a step this screen does not own.
struct OnboardingMapStep: Decodable {
    /// `next` is the first unfinished step, `skipped` one the owner skipped; anything new decodes as `.other`.
    enum State: Equatable {
        case todo, next, done, skipped, notNeeded, other
        init(raw: String) {
            switch raw {
            case "todo": self = .todo
            case "next": self = .next
            case "done": self = .done
            case "skipped": self = .skipped
            case "not_needed": self = .notNeeded
            default: self = .other
            }
        }
    }
    let key: String
    let title: String
    let state: State
    let head: String?
    let skippable: Bool
    let data: OnboardingStepData?

    private enum CodingKeys: String, CodingKey { case key, title, state, head, skippable, data }
    init(key: String, title: String, state: State, head: String?, skippable: Bool, data: OnboardingStepData?) {
        self.key = key; self.title = title; self.state = state; self.head = head; self.skippable = skippable; self.data = data
    }
    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        key = try c.decode(String.self, forKey: .key)
        title = (try? c.decode(String.self, forKey: .title)) ?? ""
        state = State(raw: (try? c.decode(String.self, forKey: .state)) ?? "")
        head = try? c.decodeIfPresent(String.self, forKey: .head)
        skippable = (try? c.decode(Bool.self, forKey: .skippable)) ?? false
        data = try? c.decodeIfPresent(OnboardingStepData.self, forKey: .data)
    }
}

/// Only the two fields this step reads; each is optional because other steps' data has neither.
struct OnboardingStepData: Decodable {
    let answer: String?
    let count: Int?

    private enum CodingKeys: String, CodingKey { case answer, count }
    init(answer: String?, count: Int?) { self.answer = answer; self.count = count }
    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        answer = try? c.decodeIfPresent(String.self, forKey: .answer)
        count = try? c.decodeIfPresent(Int.self, forKey: .count)
    }
}

struct OnboardingStepResponse: Decodable {
    let ok: Bool
    let next: String?
    let data: OnboardingStepData?
    let message: String?
    let error: String?
    let results: [PhoneContactResult]
    let url: String?
    let nonce: String?
    let contacts: [PhoneContactWire]
    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        ok = try c.decode(Bool.self, forKey: .ok)
        next = try c.decodeIfPresent(String.self, forKey: .next)
        data = try c.decodeIfPresent(OnboardingStepData.self, forKey: .data)
        message = try c.decodeIfPresent(String.self, forKey: .message)
        error = try c.decodeIfPresent(String.self, forKey: .error)
        results = try c.decodeIfPresent([PhoneContactResult].self, forKey: .results) ?? []
        url = try? c.decodeIfPresent(String.self, forKey: .url)
        nonce = try? c.decodeIfPresent(String.self, forKey: .nonce)
        contacts = (try? c.decodeIfPresent([PhoneContactWire].self, forKey: .contacts)) ?? []
    }
    private enum CodingKeys: String, CodingKey { case ok, next, data, message, error, results, url, nonce, contacts }
    init(ok: Bool, next: String?, data: OnboardingStepData?, message: String?, error: String?, results: [PhoneContactResult],
         url: String? = nil, nonce: String? = nil, contacts: [PhoneContactWire] = []) {
        self.ok = ok; self.next = next; self.data = data; self.message = message; self.error = error; self.results = results
        self.url = url; self.nonce = nonce; self.contacts = contacts
    }
}

struct PhoneContactWire: Decodable, Equatable {
    struct Phone: Decodable, Equatable {
        let label: String
        let number: String

        private enum CodingKeys: String, CodingKey { case label, number }
        init(label: String, number: String) { self.label = label; self.number = number }
        init(from decoder: Decoder) throws {
            let container = try decoder.container(keyedBy: CodingKeys.self)
            label = (try? container.decode(String.self, forKey: .label)) ?? ""
            number = (try? container.decode(String.self, forKey: .number)) ?? ""
        }
    }

    struct Address: Decodable, Equatable {
        let street: String
        let city: String
        let province: String
        let postal: String

        private enum CodingKeys: String, CodingKey { case street, city, province, postal }
        init(street: String, city: String, province: String, postal: String) {
            self.street = street; self.city = city; self.province = province; self.postal = postal
        }
        init(from decoder: Decoder) throws {
            let container = try decoder.container(keyedBy: CodingKeys.self)
            street = (try? container.decode(String.self, forKey: .street)) ?? ""
            city = (try? container.decode(String.self, forKey: .city)) ?? ""
            province = (try? container.decode(String.self, forKey: .province)) ?? ""
            postal = (try? container.decode(String.self, forKey: .postal)) ?? ""
        }
    }

    let ref: String
    let first: String
    let last: String
    let company: String
    let emails: [String]
    let phones: [Phone]
    let address: Address?

    private enum CodingKeys: String, CodingKey { case ref, first, last, company, emails, phones, address }
    init(ref: String, first: String, last: String, company: String, emails: [String], phones: [Phone], address: Address?) {
        self.ref = ref; self.first = first; self.last = last; self.company = company
        self.emails = emails; self.phones = phones; self.address = address
    }
    init(from decoder: Decoder) throws {
        let container = try decoder.container(keyedBy: CodingKeys.self)
        ref = (try? container.decode(String.self, forKey: .ref)) ?? ""
        first = (try? container.decode(String.self, forKey: .first)) ?? ""
        last = (try? container.decode(String.self, forKey: .last)) ?? ""
        company = (try? container.decode(String.self, forKey: .company)) ?? ""
        emails = (try? container.decode([String].self, forKey: .emails)) ?? []
        phones = (try? container.decode([Phone].self, forKey: .phones)) ?? []
        address = try? container.decodeIfPresent(Address.self, forKey: .address)
    }

    var phoneContact: PhoneContact {
        PhoneContact(id: ref, first: first, last: last, company: company, emails: emails,
                     phones: phones.map { .init(label: $0.label, number: $0.number) },
                     address: address.map { .init(street: $0.street, city: $0.city, province: $0.province, postal: $0.postal) })
    }
}
