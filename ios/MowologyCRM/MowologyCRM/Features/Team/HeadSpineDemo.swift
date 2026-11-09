//
//  HeadSpineDemo.swift
//  MowologyCRM
//
//  DEBUG only: `-mwSpineDemo penny|sam|otto|mia|yui|kaname` shows that head's spine with invented data and the real
//  section views, laid out as ItWorkspaceView lays them out (face, Waiting now, the head's sections, learned), without
//  signing in. Add `-mwTimelineField` for field mode. For simulator screenshots (light, dark, large text).
//

#if DEBUG
import SwiftUI

struct HeadSpineDemo: View {
    let head: String

    @StateObject private var penny = PennyCardViewModel(api: PennyDupeDemo.DemoAPI(variant: "payment"))
    @StateObject private var chase = PennyChaseViewModel(api: DemoChaseAPI())
    @StateObject private var sam = SamCardViewModel(api: DemoSalesAPI())
    @StateObject private var card: HeadCardViewModel

    init(head: String) {
        self.head = head
        _card = StateObject(wrappedValue: HeadCardViewModel(head: head == "kaname" ? "charlie" : head, api: DemoTeamAPI()))
    }

    private var slug: String { head == "kaname" ? "charlie" : head }
    private var name: String { TeamHead.all.first { $0.slug == slug }?.name ?? head.capitalized }

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 0) {
                    SpineHead(slug: slug, name: name, state: .approval)
                    sections
                    SpineNode(slug: slug, id: "learned", title: "What \(name) has learned", symbol: "brain",
                              waiting: HeadSpineLogic.count(42, "learned")) {
                        Text("\(name) has learned 42 things about how you work — mostly the work you do most.")
                            .itText(.body14)
                    }
                    SpineTail()
                }
                .padding(16)
            }
            .navigationTitle(name)
            .navigationBarTitleDisplayMode(.inline)
            .itAppScreen()
        }
        .task {
            // A fresh look every launch: no section remembered open or closed from the last run.
            for id in ["now", "missing", "mail", "knows", "business", "learned", "acceptances", "tosend", "approved",
                       "replies", "questions", "leads", "numbers", "missed", "suggestions", "unpinned", "equipment", "post"] {
                UserDefaults.standard.removeObject(forKey: HeadSpineLogic.key(slug, id))
            }
            // `-mwSpineClosed`: every section shut, to see the spine as the head's table of contents.
            if ProcessInfo.processInfo.arguments.contains("-mwSpineClosed") {
                for id in ["now", "missed", "business"] {
                    UserDefaults.standard.set(HeadSpineLogic.stored(open: false), forKey: HeadSpineLogic.key(slug, id))
                }
            }
            switch head {
            case "penny": await penny.load(); await chase.loadAdmin()
            case "sam": await sam.load()
            default: await card.load()
            }
        }
    }

    @ViewBuilder private var sections: some View {
        switch head {
        case "penny":
            PennyCardView(vm: penny, lead: AnyView(guide))
            SpineNode(slug: "penny", id: "missing", title: "Missing receipts", symbol: "doc.badge.clock",
                      waiting: HeadSpineLogic.count(chase.adminItems.count, "to chase")) {
                PennyChaseAdminSection(vm: chase, inSpine: true)
            }
            SpineNode(slug: "penny", id: "knows", title: "What Penny knows", symbol: "lightbulb.fill") {
                Text("Your vendors, categories and the cards you pay with.").itText(.body14)
            }
            SpineNode(slug: "penny", id: "business", title: "Your business", symbol: "building.2.fill",
                      waiting: "to confirm") {
                Text("Getting to know your business").itText(.heading18)
            }
        case "sam":
            SamCardView(vm: sam)
        default:
            HeadCardView(vm: card, showsBrain: false, showsAsk: false, lead: AnyView(guide))
            if head == "otto" {
                SpineNode(slug: "otto", id: "equipment", title: "Equipment", symbol: "wrench.and.screwdriver.fill") {
                    Text("Add equipment: snap the plate.").itText(.body14)
                }
            }
        }
    }

    private var guide: some View {
        Text("I look after this part of the business. When something needs you, I'll walk you through it.")
            .itText(.body16)
            .padding(ItSpace.s4)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(Color.secondary.opacity(0.08), in: RoundedRectangle(cornerRadius: ItRadius.lg))
    }
}

private struct DemoChaseAPI: PennyChaseAPI {
    func mine() async throws -> PennyChaseMineResponse { throw URLError(.notConnectedToInternet) }
    func recent(itemId: Int) async throws -> PennyChaseRecentResponse { throw URLError(.notConnectedToInternet) }
    func admin() async throws -> PennyChaseAdminResponse {
        let json = #"{"ok":true,"ready":true,"summary":"2 receipts missing, $155.85","people":[{"id":1,"name":"Sample Crew"}],"items":[{"id":1,"amount":147.84,"date":"2026-10-22","vendor":"Champion","who":"Sample Crew","nudges":1,"user_id":1},{"id":2,"amount":8.01,"date":"2026-10-07","vendor":"Chevron","who":"","nudges":0}]}"#
        return try JSONDecoder().decode(PennyChaseAdminResponse.self, from: Data(json.utf8))
    }
    func send(_ body: [String: Any]) async throws -> PennyChaseActionResponse { throw URLError(.notConnectedToInternet) }
}

private struct DemoSalesAPI: SalesDeskAPI {
    func loadDesk() async throws -> SalesDeskResponse {
        let json = #"{"ok":true,"name":"Sam","stats":{"won":12,"lost":4},"queue":[{"key":"a","kind":"replied","name":"Sample Client","amount":1890,"days":3,"last_in":"2026-11-06 09:00:00","quotes":[{"id":1,"number":"QUO-2026-0101","service":"fall_cleanup","amount":1890}]},{"key":"b","kind":"stale","name":"Sample Strata","amount":640,"days":9,"viewed":true,"quotes":[{"id":2,"number":"QUO-2026-0102","service":"hedge_trim","amount":640}]}],"leads":[{"id":5,"name":"Sample Lead","services":"Lawn care","age_days":1,"value":400}],"to_send":[],"approved":[],"acceptances":[]}"#
        return try JSONDecoder().decode(SalesDeskResponse.self, from: Data(json.utf8))
    }
    func loadThread(contactId: Int) async throws -> SalesThreadResponse { throw URLError(.notConnectedToInternet) }
    func send(_ body: [String: Any]) async throws -> SalesActionResponse { throw URLError(.notConnectedToInternet) }
}

private struct DemoTeamAPI: TeamHeadAPI {
    func loadCard(head: String) async throws -> HeadCardResponse {
        let items = #"[{"key":"k1","head":"\#(head)","kind":"demo","text":"Sample Client asked to move Thursday's visit.","url":"/crm/","priority":1},{"key":"k2","head":"\#(head)","kind":"demo","text":"Two visits have no crew yet.","url":"/crm/","priority":2}]"#
        let extra: String
        switch head {
        case "otto": extra = #","unpinned":[{"id":7,"address":"100 Sample St","city":"Vancouver","next_visit":"2026-11-10"}]"#
        case "mia": extra = #","can_approve":true,"post":{"title":"Fall cleanup","body":"Leaves down? We are booking fall cleanups now."}"#
        default: extra = ""
        }
        let json = #"{"ok":true,"head":"\#(head)","name":"Demo","headline":"Two things need you today.","waiting":3,"owner":true,"items":\#(items)\#(extra)}"#
        return try JSONDecoder().decode(HeadCardResponse.self, from: Data(json.utf8))
    }
    func send(_ body: [String: Any]) async throws -> HeadActionResponse { throw URLError(.notConnectedToInternet) }
    func ottoSend(_ body: [String: Any]) async throws -> OttoDecideResponse { throw URLError(.notConnectedToInternet) }
    func ottoMissedLoad() async throws -> OttoMissedResponse {
        OttoMissedResponse(ok: true, error: nil, review: nil, waiting: 2, withPick: 2, items: [], reasons: [], tomorrow: nil, today: nil)
    }
}
#endif
