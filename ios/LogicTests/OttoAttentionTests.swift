import Foundation

@main
struct OttoAttentionChecks {
    static func main() throws {
        func desk(_ json: String) throws -> OttoDesk {
            try JSONDecoder().decode(OttoDesk.self, from: Data(json.utf8))
        }
        func check(_ condition: Bool, _ message: String) {
            guard condition else { fatalError(message) }
        }
        check(try desk("{}").scheduleAttention == nil, "Missing data must stay hidden")
        check(try desk(#"{"total":9,"autolog":{"line":"Visits logged","rows":[]}}"#).scheduleAttention == nil,
              "Counts and completed work must not summon Otto")
        check(try desk(#"{"items":[{"id":0,"text":"Unrecorded"},{"id":2,"text":"  "}]}"#).scheduleAttention == nil,
              "Malformed suggestions must stay hidden")
        let pending = try desk(#"{"items":[{"id":"4","priority":2,"text":"Review visit duration"},{"id":5,"priority":1,"text":"Assign today's crew"}]}"#)
        check(pending.scheduleAttention?.id == 5, "Show the most urgent real pending decision")
        let rejection = try JSONDecoder().decode(HeadCardResponse.self, from: Data(#"{"ok":false,"message":"Permission jobs.edit required"}"#.utf8))
        check(rejection.error == "Permission jobs.edit required", "Preserve the server's exact rejection message")
        print("Otto attention checks passed")
    }
}
