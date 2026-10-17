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
    }
    save(Screen(mode: .light) { OfficeScreen(withStates: true) }, "office-light-with-states")
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
