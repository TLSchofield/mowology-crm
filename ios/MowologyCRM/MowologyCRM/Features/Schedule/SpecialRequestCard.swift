//
//  SpecialRequestCard.swift
//  MowologyCRM
//
//  The client's special request on a visit:
//   • SpecialRequestCard — inline at the top of the visit card in VisitDetailView, with
//     Done / Not done (reason) / Extra work done (what + minutes).
//   • SpecialRequestGateView — the full-screen "read it first" view VisitDetailView presents
//     from ITSELF (never MainTabView) when Start / a photo is tapped before "Got it". It never
//     opens the camera or starts the timer: after Got it the crew tap again.
//

import SwiftUI

struct SpecialRequestCard: View {
    @Environment(\.itAppStyle) private var appStyle

    let request: SpecialRequest
    let isSaving: Bool
    let onRead: () -> Void
    let onAnswer: (_ outcome: String, _ reason: String, _ description: String, _ minutes: Int) async -> Bool

    @State private var form: String?          // "not_done" | "extra_done"
    @State private var reason = ""
    @State private var extraDescription = ""
    @State private var extraMinutes = ""

    var body: some View {
        VStack(alignment: .leading, spacing: 8) {
            HStack(alignment: .center, spacing: 10) {
                HeadFaceView(slug: request.headSlug, name: request.headName, size: 44)
                VStack(alignment: .leading, spacing: 2) {
                    HStack(spacing: 6) {
                        Text(request.headLine)
                            .font(appStyle.font(.subheadline, weight: .semibold))
                            .foregroundStyle(appStyle.color(.text, legacy: Color.MW.forest))
                        Text("SPECIAL REQUEST")
                            .font(appStyle.font(.caption2, weight: .heavy))
                            .padding(.horizontal, 7).padding(.vertical, 2)
                            .background(request.isOpen ? appStyle.attention : appStyle.meta)
                            .foregroundStyle(appStyle.onTenant)
                            .clipShape(Capsule())
                    }
                    Text(request.fromLine)
                        .font(appStyle.font(.caption2))
                        .foregroundStyle(appStyle.meta)
                        .lineLimit(2)
                }
            }

            if !request.clientWords.isEmpty {
                Text("“\(request.clientWords)”")
                    .font(appStyle.font(.footnote).italic())
                    .lineLimit(6)
                    .padding(8)
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .background(appStyle.surface)
                    .overlay(Rectangle().frame(width: 3).foregroundStyle(appStyle.attention), alignment: .leading)
            }

            SpecialRequestItems(request: request)

            Text(request.acks.isEmpty ? "Not read yet" : "Read by " + request.acks.map(\.name).joined(separator: ", "))
                .font(appStyle.font(.caption2))
                .foregroundStyle(appStyle.meta)

            if request.isOpen {
                actions
            } else if let o = request.outcome {
                Text(outcomeLine(o))
                    .font(appStyle.font(.footnote, weight: .semibold))
                    .foregroundStyle(appStyle.color(.text, legacy: Color.MW.forest))
            }
        }
        .padding(12)
        .background(request.isOpen ? appStyle.attention.opacity(0.10) : appStyle.sunk)
        .clipShape(RoundedRectangle(cornerRadius: appStyle.cornerRadius(12)))
        .overlay(RoundedRectangle(cornerRadius: appStyle.cornerRadius(12)).stroke(request.isOpen ? appStyle.attention : appStyle.line, lineWidth: request.isOpen ? 2 : 1))
    }

    @ViewBuilder
    private var actions: some View {
        if let form {
            VStack(alignment: .leading, spacing: 6) {
                if form == "not_done" {
                    TextField("Why wasn't it done?", text: $reason, axis: .vertical)
                        .textFieldStyle(.roundedBorder)
                } else {
                    TextField("What did you do?", text: $extraDescription)
                        .textFieldStyle(.roundedBorder)
                    TextField("Minutes it took", text: $extraMinutes)
                        .keyboardType(.numberPad)
                        .textFieldStyle(.roundedBorder)
                }
                HStack {
                    Button("Cancel") { self.form = nil }
                        .buttonStyle(.bordered)
                    Button(isSaving ? "Saving…" : "Save") {
                        Task {
                            let ok = await onAnswer(form, reason, extraDescription, Int(extraMinutes) ?? 0)
                            if ok { self.form = nil }
                        }
                    }
                    .buttonStyle(.borderedProminent)
                    .tint(appStyle.tenant)
                    .disabled(isSaving)
                }
            }
        } else {
            ViewThatFits {
                HStack(spacing: 8) { buttons }
                VStack(alignment: .leading, spacing: 8) { buttons }
            }
        }
    }

    @ViewBuilder
    private var buttons: some View {
        if request.needsMyAck {
            Button("Read it", action: onRead)
                .buttonStyle(.borderedProminent)
                .tint(appStyle.tenant)
        }
        Button("Done") { Task { _ = await onAnswer("done", "", "", 0) } }
            .buttonStyle(.bordered)
            .disabled(isSaving)
        Button("Not done") { form = "not_done" }
            .buttonStyle(.bordered)
        if !request.extra.isEmpty {
            Button("Extra work done") {
                extraDescription = request.extra.first ?? ""
                form = "extra_done"
            }
            .buttonStyle(.bordered)
            .tint(appStyle.attention)
        }
    }

    private func outcomeLine(_ o: SpecialRequestOutcome) -> String {
        let label = ["done": "Done", "not_done": "Not done", "extra_done": "Extra work done"][o.status] ?? o.status
        var s = label
        if let r = o.reason, !r.isEmpty { s += " — \(r)" }
        if o.status == "extra_done" { s += " — \(o.extraDescription ?? "") (\(o.extraMinutes ?? 0) min)" }
        if let by = o.byName, !by.isEmpty { s += " · \(by)" }
        return s
    }
}

/// "Part of today's work" vs "Extra — decide on site".
struct SpecialRequestItems: View {
    @Environment(\.itAppStyle) private var appStyle
    let request: SpecialRequest

    var body: some View {
        VStack(alignment: .leading, spacing: 4) {
            if !request.included.isEmpty {
                Text("PART OF TODAY'S WORK").font(appStyle.font(.caption2, weight: .bold)).foregroundStyle(appStyle.tenant)
                ForEach(request.included, id: \.self) { Label($0, systemImage: "checkmark.circle").font(appStyle.font(.footnote)) }
            }
            if !request.extra.isEmpty {
                Text("EXTRA — DECIDE ON SITE").font(appStyle.font(.caption2, weight: .bold)).foregroundStyle(appStyle.attention)
                ForEach(request.extra, id: \.self) { Label($0, systemImage: "plus.circle").font(appStyle.font(.footnote)) }
            }
        }
    }
}

struct SpecialRequestGateView: View {
    @Environment(\.itAppStyle) private var appStyle
    let request: SpecialRequest
    let isSaving: Bool
    let onGotIt: () -> Void
    let onBack: () -> Void

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 14) {
                // The head who raised it, face first — the way the heads appear on the Team tab.
                VStack(spacing: 6) {
                    HeadFaceView(slug: request.headSlug, name: request.headName, size: 112)
                    Text(request.headLine).font(appStyle.font(.title3, weight: .semibold)).foregroundStyle(appStyle.color(.text, legacy: Color.MW.forest))
                    Text("SPECIAL REQUEST").font(appStyle.font(.caption, weight: .heavy)).foregroundStyle(appStyle.attention)
                }
                .frame(maxWidth: .infinity)
                VStack(alignment: .leading, spacing: 2) {
                    Text(request.address).font(appStyle.font(.title3, weight: .bold)).foregroundStyle(appStyle.color(.text, legacy: Color.MW.forest))
                    Text(request.fromLine).font(appStyle.font(.caption)).foregroundStyle(appStyle.meta)
                }
                if !request.clientWords.isEmpty {
                    Text("“\(request.clientWords)”")
                        .font(appStyle.font(.body).italic())
                        .padding(10)
                        .frame(maxWidth: .infinity, alignment: .leading)
                        .background(appStyle.sunk)
                        .clipShape(RoundedRectangle(cornerRadius: appStyle.cornerRadius(10)))
                }
                SpecialRequestItems(request: request)
                Text("Read it before you start. After Got it, tap Start or the camera again.")
                    .font(appStyle.font(.footnote))
                    .foregroundStyle(appStyle.meta)
                HStack(spacing: 12) {
                    Button(action: onBack) {
                        Text("Back").frame(maxWidth: .infinity).frame(height: 52)
                    }
                    .buttonStyle(.bordered)
                    Button(action: onGotIt) {
                        Text(isSaving ? "Saving…" : "Got it").font(appStyle.font(.headline)).frame(maxWidth: .infinity).frame(height: 52)
                    }
                    .buttonStyle(.borderedProminent)
                    .tint(appStyle.tenant)
                    .disabled(isSaving)
                }
            }
            .padding(20)
        }
        .background(appStyle.surface)
        .interactiveDismissDisabled()
    }
}
