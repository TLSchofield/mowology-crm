//
//  RenderMain.swift — renders the Ittai design layer to PNGs on the Mac (no simulator needed).
//  Built and run by ios/DesignRender/run.sh together with Core/Design/Ittai/*.swift.
//  SYNTHETIC DATA ONLY (design rule 9): sample names from the v1.2 prototype, never real clients.
//

import SwiftUI
import AppKit
import CoreText

let outDir = CommandLine.arguments.count > 1 ? CommandLine.arguments[1] : "."
let fontDir = CommandLine.arguments.count > 2 ? CommandLine.arguments[2] : "."

func registerFonts() {
    for n in ["Inter-Regular", "Inter-Medium", "Inter-SemiBold"] {
        let url = URL(fileURLWithPath: "\(fontDir)/\(n).dataset/\(n).ttf")
        var err: Unmanaged<CFError>?
        if !CTFontManagerRegisterFontsForURL(url as CFURL, .process, &err) { print("font FAIL", n) }
    }
}

@MainActor func save<V: View>(_ view: V, _ name: String, scale: CGFloat = 2) {
    let r = ImageRenderer(content: view); r.scale = scale
    guard let img = r.nsImage, let tiff = img.tiffRepresentation, let rep = NSBitmapImageRep(data: tiff),
          let png = rep.representation(using: .png, properties: [:]) else { print("render FAIL", name); return }
    try! png.write(to: URL(fileURLWithPath: "\(outDir)/\(name).png"))
    print("wrote \(name).png \(Int(img.size.width))×\(Int(img.size.height))")
}

func applyCVD(_ name:String, matrix:[[Double]]) {
    let url=URL(fileURLWithPath:"\(outDir)/\(name).png")
    guard let image=NSImage(contentsOf:url),let data=image.tiffRepresentation,let bitmap=NSBitmapImageRep(data:data) else{return}
    guard bitmap.bitsPerSample == 8,bitmap.samplesPerPixel >= 3,let bytes=bitmap.bitmapData else{return}
    for y in 0..<bitmap.pixelsHigh { for x in 0..<bitmap.pixelsWide { let i=y*bitmap.bytesPerRow+x*bitmap.samplesPerPixel;let v=[Double(bytes[i])/255,Double(bytes[i+1])/255,Double(bytes[i+2])/255];let o=matrix.map{row in max(0,min(1,zip(row,v).map{$0.0*$0.1}.reduce(0,+)))};bytes[i]=UInt8(o[0]*255);bytes[i+1]=UInt8(o[1]*255);bytes[i+2]=UInt8(o[2]*255) } }
    if let png=bitmap.representation(using:.png,properties:[:]){try! png.write(to:url)}
}

// MARK: sample data (synthetic)

func sampleSides(withStates: Bool) -> [ItOfficeMember] {
    [
        ItOfficeMember(id: "yui", name: "Yui", department: "Communications", state: withStates ? .working : nil, topItem: "Triaged 11 messages; 2 need a reply", count: 11),
        ItOfficeMember(id: "sam", name: "Sam", department: "Sales", state: withStates ? .approval : nil, topItem: "Bob Birch · QUO-2026-0002 · $1,407", count: 6),
        ItOfficeMember(id: "penny", name: "Penny", department: "Bookkeeper", state: withStates ? .approval : nil, topItem: "INV-2026-0233 · $546 is 21 days overdue", count: 4),
        ItOfficeMember(id: "otto", name: "Otto", department: "Operations", state: withStates ? .approval : nil, topItem: "Cedar Crew 18 min late at 2140 Larch St", count: 3),
        ItOfficeMember(id: "mia", name: "Mia", department: "Marketing", state: withStates ? .idle : nil, topItem: nil, count: 0),
        ItOfficeMember(id: "ada", name: "Ada", department: "Technology", available: false),
    ]
}

func sampleKnowledge(_ count:Int = 40) -> KnowledgeResponse {
    let vendors: [[String:Any]] = (0..<count).map { i in
        let stage = i % 7 == 0 ? "mastered" : (i % 5 == 0 ? "understood" : (i % 3 == 0 ? "identified" : "recognized"))
        return ["key":"vendor:\(i)","slot":i,"label":"Sample Vendor \(i+1)","stage":stage,
                "evidence":["receipts_approved":i+1,"transactions_matched":i/2,"items_recognized":i+3,"items_new":i%4,
                            "classification_accuracy":0.92,"classification_decided":12,"unchanged_streak":3,
                            "last_verified":"Oct 12, 2026","exceptions":i==5 ? [["kind":"question","text":"Which category should this receipt use?"]] : []],
                "next":[stage == "recognized" ? "Approve 1 receipt from this vendor" : "Approve and match one receipt from this vendor."]]
    }
    let counts=Dictionary(grouping:vendors,by:{$0["stage"] as! String}).mapValues(\.count)
    let root:[String:Any] = ["ok":true,"head":"penny","kind":"vendor","generated_at":"2026-10-17T12:00:00Z",
       "counts":["recognized":counts["recognized",default:0],"identified":counts["identified",default:0],"understood":counts["understood",default:0],"mastered":counts["mastered",default:0]],"items":vendors]
    return try! JSONDecoder().decode(KnowledgeResponse.self,from:JSONSerialization.data(withJSONObject:root))
}

struct KnowledgeRender: View { let response:KnowledgeResponse;var yaw=ItKnowledgeGeometry.restingYaw;var body:some View { ItKnowledgeBrain(response:response,staticYaw:yaw) } }
struct RecognizedTriangleRender: View {
    var body: some View { ItKnowledgeMark(stage:.recognized,size:96,key:"business-onboarding")
        .frame(maxWidth:.infinity,minHeight:130) }
}
struct OfficeBarRender: View {
    let members = [
        ItOfficeMember(id:"ada",name:"Ada",department:"",state:.working),
        ItOfficeMember(id:"mia",name:"Mia",department:"",state:.idle),
        ItOfficeMember(id:"otto",name:"Otto",department:"",state:.blocked),
        ItOfficeMember(id:"charlie",name:"Charlie",department:"",state:.working),
        ItOfficeMember(id:"penny",name:"Penny",department:"",state:.approval),
        ItOfficeMember(id:"sam",name:"Sam",department:"",state:.idle),
        ItOfficeMember(id:"yui",name:"Yui",department:"",state:.working),
    ]
    var body: some View { ItOfficeBar(members:members,onSelect:{_ in}) }
}
func registryMatch(_ id:String, _ legal:String, operating:[String] = []) -> BusinessRegistryMatch {
    let o:[String:Any] = ["record_id":id,"legal_name":legal,"operating_names":operating,
                          "registration_number":"BC1234567","registration_type":"bc_registration",
                          "legal_structure":"BC Company","status":"Active","jurisdiction":"British Columbia"]
    return try! JSONDecoder().decode(BusinessRegistryMatch.self,from:JSONSerialization.data(withJSONObject:o))
}
struct OnboardingRender:View {
    let state:ItPennyOnboardingState
    var busy = false
    var error:String? = nil
    var contacts:BusinessContactsState = .idle
    var body:some View { ItPennyOnboarding(state:state,stage:stage,attribution:"Contains information licensed under the Open Government Licence – British Columbia.",isBusy:busy || state == .searching,canEdit:true,initialName:"Harbour Lawn Care",showsOtherMatches:state.hasSeveral,error:error,contactsState:contacts) }
    private var stage:KnowledgeStage { if case .confirmed = state { return .identified }; return .recognized }
}
private extension ItPennyOnboardingState { var hasSeveral:Bool { if case .several(let matches,_) = self { return matches.count > 1 }; return false } }
struct KnowledgeDetailRender:View {let vendor:KnowledgeVendor;@Environment(\.it)private var it;var body:some View{VStack(alignment:.leading,spacing:20){HStack{ItKnowledgeMark(stage:vendor.stage,size:40,key:vendor.key);VStack(alignment:.leading){Text(vendor.label).itText(.title22,weight:600);Text(vendor.stage.title).itText(.body14).foregroundStyle(it.meta)}};Group{Text("Receipts processed: \(vendor.evidence.receiptsApproved)");Text("Transactions matched: \(vendor.evidence.transactionsMatched)");Text("Items recognised: \(vendor.evidence.itemsRecognized)");Text("\(vendor.evidence.itemsNew) items new to Penny");Text("92% · 12 decided");Text("Last verified: \(vendor.evidence.lastVerified ?? "Not verified yet")")}.itText(.body14);Text("Open questions").itText(.heading16);Text("No open questions").itText(.body14);if let next=vendor.stage.nextTitle{Text("To reach \(next)").itText(.heading16);ForEach(vendor.next,id:\.self){Text($0).itText(.body14)}};Text("Items").itText(.heading16);item(.mastered,"Fuel · seen 8, confirmed 4");item(.understood,"Blade oil · seen 3, confirmed 1");item(.recognized,"Work gloves · seen 1, confirmed 0")}.padding(16).frame(maxWidth:.infinity,alignment:.leading).background(it.surface,in:RoundedRectangle(cornerRadius:14)).foregroundStyle(it.text)};private func item(_ stage:KnowledgeStage,_ text:String)->some View{HStack{ItKnowledgeMark(stage:stage,size:22,key:text);Text(text).itText(.body14)}}}
let sampleCharlie = { (withStates: Bool) in
    ItOfficeMember(id: "charlie", name: "Charlie", department: "Chief of Staff", state: withStates ? .working : nil,
                   topItem: "3 decisions held for you", count: 27)
}

struct Screen<Content: View>: View {
    let mode: ItMode
    var width: CGFloat = 390
    @ViewBuilder var content: Content
    var body: some View {
        let it = ItPalette(mode)
        VStack(alignment: .leading, spacing: 0) {
            Text("\(mode.rawValue.capitalized) · synthetic data").font(.system(size: 11)).foregroundStyle(it.meta)
                .padding(.horizontal, 16).padding(.top, 10)
            content.padding(16)
        }
        .frame(width: width, alignment: .topLeading)
        .background(it.page)
        .environment(\.it, it)
        .environment(\.colorScheme, it.colorScheme)
    }
}

struct ComponentsSheet: View {
    @Environment(\.it) private var it
    var body: some View {
        VStack(alignment: .leading, spacing: ItSpace.s6) {
            HStack(spacing: ItSpace.s4) {
                ForEach([16, 24, 32, 47, 48, 64], id: \.self) { s in
                    VStack(spacing: 4) {
                        ItSymbol(size: CGFloat(s))
                        Text("\(s)").font(.system(size: 10)).foregroundStyle(it.meta)
                    }
                }
            }
            VStack(alignment: .leading, spacing: ItSpace.s3) {
                Button("Send reminder") {}.buttonStyle(.it(.primary))
                HStack { Button("Send follow-up") {}.buttonStyle(.it(.outline)); Button("Not now") {}.buttonStyle(.it(.ghost)) }
                Button("Approving…") {}.buttonStyle(.it(.primary)).disabled(true)
            }
            VStack(alignment: .leading, spacing: 6) {
                ForEach(ItTaskStatus.allCases, id: \.self) { ItTaskStatusLabel(status: $0, detail: $0 == .failed ? "no email on file" : nil) }
            }
            .padding(ItSpace.s3).background(it.surface)
            VStack(alignment: .leading, spacing: 6) {
                ForEach(ItEmployeeState.allCases, id: \.self) { ItEmployeeStateLabel(state: $0) }
                HStack { Text("Penny").itText(.body16, weight: 600).foregroundStyle(it.text); ItAITag() }
            }
            .padding(ItSpace.s3).background(it.surface)
        }
    }
}

struct OfficeScreen: View {
    let withStates: Bool
    @State var selected: String? = "penny"
    @Environment(\.it) private var it
    var body: some View {
        ItAIOfficeView(brief: "The day is on track. Three decisions need you; the office is coordinating the rest.",
                       briefTime: "9:18 AM", charlie: sampleCharlie(withStates), sides: sampleSides(withStates: withStates),
                       selected: $selected) { id in
            Text("(\(id)'s existing card, restyled lightly, goes here)").itText(.body14).foregroundStyle(it.meta)
                .frame(maxWidth: .infinity, minHeight: 80).background(it.surface)
        }
    }
}

/// Regression fixture: each head card failed, but Charlie's ready brief already supplied the row.
struct OfficeBriefWinsRender: View {
    @State private var selected: String? = "penny"
    var body: some View {
        let failedWithBrief = sampleSides(withStates: true).map { resolve($0, cardLoadFailed: true) }
        ItAIOfficeView(brief: "The office is ready.", briefTime: "9:18 AM",
                       charlie: sampleCharlie(true), sides: failedWithBrief, selected: $selected) { _ in EmptyView() }
    }

    private func resolve(_ brief: ItOfficeMember, cardLoadFailed: Bool) -> ItOfficeMember {
        var row = brief
        let briefProduced = brief.state != nil || brief.topItem != nil || brief.count != nil
        row.loadFailed = cardLoadFailed && !briefProduced
        return row
    }
}

struct HeadScreen: View {
    @Environment(\.it) private var it
    @Environment(\.dynamicTypeSize) private var dynamicTypeSize
    var body: some View {
        VStack(alignment: .leading, spacing: ItSpace.s6) {
            Group {
                if dynamicTypeSize.isAccessibilitySize {
                    VStack(alignment: .leading, spacing: ItSpace.s3) { portrait; identity }
                } else {
                    HStack(spacing: ItSpace.s3) { portrait; identity }
                }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            VStack(alignment: .leading, spacing: ItSpace.s4) {
                Text("I look after your receipts and your books. When something needs you, I'll walk you through it one step at a time.").itText(.body16)
                step(1, "What happened", "INV-2026-0233 · $546 is 21 days overdue")
                step(2, "What I recommend", "Open the invoice in the CRM and decide what to do.")
                step(3, "When you tap Open in CRM", "It opens the invoice in the CRM. Nothing is sent to the customer.")
                step(4, "Not ready?", "Not now brings it back tomorrow at 8.")
            }.padding(ItSpace.s4).background(it.surface, in: RoundedRectangle(cornerRadius: ItRadius.lg))
            DisclosureGroup("Penny has learned 41 things about how you work — mostly receipt matching.  See details") {
                Text("Existing brain statistics").itText(.body14).foregroundStyle(it.meta).padding(.top, 8)
            }.itText(.body14)
            Text("Existing card content").itText(.heading18)
            Rectangle().fill(it.surface).frame(height: 180)
        }.foregroundStyle(it.text)
    }
    private var portrait: some View {
        ItPortrait(name: "Penny", url: nil, state: .approval, size: 56)
    }
    private var identity: some View {
        VStack(alignment: .leading, spacing: 2) {
            HStack { Text("Penny").itText(.title22, weight: 600).fixedSize(horizontal: false, vertical: true); ItAITag() }
            Text("Bookkeeper").itText(.meta13).foregroundStyle(it.meta).fixedSize(horizontal: false, vertical: true)
            Text("Kanjō · 勘定 — the steward").itText(.meta13).foregroundStyle(it.meta).fixedSize(horizontal: false, vertical: true)
        }.frame(maxWidth: .infinity, alignment: .leading)
    }
    private func step(_ n: Int, _ title: String, _ detail: String) -> some View {
        Group {
            if dynamicTypeSize.isAccessibilitySize {
                VStack(alignment: .leading, spacing: ItSpace.s2) { number(n); stepWords(title, detail) }
            } else {
                HStack(alignment: .top, spacing: ItSpace.s3) { number(n); stepWords(title, detail) }
            }
        }
        .frame(maxWidth: .infinity, alignment: .leading)
    }
    private func number(_ n: Int) -> some View {
        Text("\(n)").itText(.body14, weight: 600).frame(minWidth: 24, minHeight: 24).background(it.sunk, in: Circle())
    }
    private func stepWords(_ title: String, _ detail: String) -> some View {
        VStack(alignment: .leading) {
            Text(title).itText(.body14, weight: 600).fixedSize(horizontal: false, vertical: true)
            Text(detail).itText(.body14).foregroundStyle(it.meta).fixedSize(horizontal: false, vertical: true)
        }.frame(maxWidth: .infinity, alignment: .leading)
    }
}

struct BriefStateCard: View {
    let state: ItLoadState
    @Environment(\.it) private var it
    var body: some View {
        VStack(alignment: .leading, spacing: ItSpace.s3) {
            Text("Charlie's brief").itText(.meta13, weight: 600)
            ItLoadStateLine(state: state)
        }.padding(ItSpace.s4).background(it.surface, in: RoundedRectangle(cornerRadius: ItRadius.lg))
    }
}

@MainActor func run() {
    registerFonts()
    let one=registryMatch("one","HARBOUR LANDSCAPING LTD.",operating:["Harbour Lawn Care"])
    let several=[one,registryMatch("two","HARBOUR GARDENS INC."),registryMatch("three","HARBOUR YARD SERVICES LTD.")]
    let onboarding:[(String,ItPennyOnboardingState)]=[("opening",.opening),("searching",.searching),("one-match-single",.oneMatch(one)),("several",.several(several,selected:nil)),("none-manual",.none(manual:true)),("unavailable",.unavailable)]
    for (name,state) in onboarding { save(Screen(mode:.light,width:393){OnboardingRender(state:state)},"penny-onboarding-\(name)-393-light") }
    save(Screen(mode:.light,width:393){OnboardingRender(state:.confirmed(one.legalName),contacts:.loading)},"penny-onboarding-confirm-success-393-light")
    save(Screen(mode:.light,width:393){OnboardingRender(state:.oneMatch(one),error:"I couldn't save those business details.")},"penny-onboarding-confirm-failure-393-light")
    let contacts:[BusinessContactSuggestion] = [
        BusinessContactSuggestion(field:.website,value:"harbourlawn.ca",source:.yourSettings,status:"suggested"),
        BusinessContactSuggestion(field:.phone,value:"604-555-0142",source:.yourSettings,status:"suggested"),
        BusinessContactSuggestion(field:.address,value:"42 Harbour Way, Victoria, BC",source:.googlePlaces,status:"suggested")]
    save(Screen(mode:.light,width:393){OnboardingRender(state:.confirmed(one.legalName),contacts:.available(contacts))},"penny-onboarding-contacts-step-393-light")
    save(Screen(mode:.dark,width:393){OnboardingRender(state:.oneMatch(one))},"penny-onboarding-one-match-single-393-dark")
    save(Screen(mode:.light,width:393){RecognizedTriangleRender()},"onboarding-recognized-triangle")
    for mode in [ItMode.light,.dark] { for width in [375,430] {
        save(Screen(mode:mode,width:CGFloat(width)){OfficeBarRender()},"office-bar-\(width)-\(mode.rawValue)-mixed")
    } }
    for m in ItMode.allCases {
        save(Screen(mode: m) { ComponentsSheet() }, "components-\(m.rawValue)")
        for width in [375, 393, 430] {
            save(Screen(mode: m, width: CGFloat(width)) { OfficeScreen(withStates: true) }
                .environment(\.dynamicTypeSize, .large), "office-\(width)-large-\(m.rawValue)")
            save(Screen(mode: m, width: CGFloat(width)) { OfficeScreen(withStates: true) }
                .environment(\.dynamicTypeSize, .accessibility3), "office-\(width)-ax3-\(m.rawValue)")
            save(Screen(mode: m, width: CGFloat(width)) { HeadScreen() }
                .environment(\.dynamicTypeSize, .large), "head-penny-\(width)-large-\(m.rawValue)")
            save(Screen(mode: m, width: CGFloat(width)) { HeadScreen() }
                .environment(\.dynamicTypeSize, .accessibility3), "head-penny-\(width)-ax3-\(m.rawValue)")
        }
        if m == .dark { save(Screen(mode:m,width:393){KnowledgeRender(response:sampleKnowledge(40))},"knowledge-brain-40-393-dark") }
    }
    for count in [1,6,20,40] { save(Screen(mode:.light,width:393){KnowledgeRender(response:sampleKnowledge(count))},"knowledge-brain-\(count)-393-light") }
    let detail=sampleKnowledge().items[3]
    save(Screen(mode:.light,width:393){KnowledgeDetailRender(vendor:detail)},"knowledge-detail-393-light")
    // Machado 2009, severity 1.0, applied pixel-by-pixel to the light brain.
    let cvd:[String:[[Double]]]=["protanopia":[[0.152286,1.052583,-0.204868],[0.114503,0.786281,0.099216],[-0.003882,-0.048116,1.051998]],"deuteranopia":[[0.367322,0.860646,-0.227968],[0.280085,0.672501,0.047413],[-0.011820,0.042940,0.968881]],"tritanopia":[[1.255528,-0.076749,-0.178779],[-0.078411,0.930809,0.147602],[0.004733,0.691367,0.303900]]]
    for (kind,matrix) in cvd {
        let name="knowledge-brain-40-393-light-\(kind)";save(Screen(mode:.light,width:393){KnowledgeRender(response:sampleKnowledge(40))},name);applyCVD(name,matrix:matrix)
    }
    save(Screen(mode: .light) { OfficeScreen(withStates: true) }, "office-light-with-states")
    save(Screen(mode: .light, width: 393) { OfficeBriefWinsRender() }, "office-rows-card-failed-brief-ready-393-light")
    let states: [(String, ItLoadState)] = [("loading", .loading), ("offline", .offline("8:42 AM")),
                                            ("error", .error), ("unavailable", .unavailable)]
    for (name, state) in states { save(Screen(mode: .light) { BriefStateCard(state: state) }, "brief-\(name)") }
    // The symbol alone at real 1× pixel sizes, for the pixel comparison against the design-system SVGs.
    for s in [16, 24, 32, 47, 48, 64, 128] {
        save(ItSymbol(size: CGFloat(s)).frame(width: CGFloat(s), height: CGFloat(s)).background(Color.white)
                .environment(\.it, ItPalette(.light)), "symbol-\(s)", scale: 1)
    }
}

Task { @MainActor in run(); exit(0) }
RunLoop.main.run()
