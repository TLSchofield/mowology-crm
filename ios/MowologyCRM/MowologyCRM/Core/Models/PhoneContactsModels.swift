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
    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        ok = try c.decode(Bool.self, forKey: .ok)
        next = try c.decodeIfPresent(String.self, forKey: .next)
        data = try c.decodeIfPresent(OnboardingStepData.self, forKey: .data)
        message = try c.decodeIfPresent(String.self, forKey: .message)
        error = try c.decodeIfPresent(String.self, forKey: .error)
        results = try c.decodeIfPresent([PhoneContactResult].self, forKey: .results) ?? []
    }
    private enum CodingKeys: String, CodingKey { case ok, next, data, message, error, results }
    init(ok: Bool, next: String?, data: OnboardingStepData?, message: String?, error: String?, results: [PhoneContactResult]) {
        self.ok = ok; self.next = next; self.data = data; self.message = message; self.error = error; self.results = results
    }
}
