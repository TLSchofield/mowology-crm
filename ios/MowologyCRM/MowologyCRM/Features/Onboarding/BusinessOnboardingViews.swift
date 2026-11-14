//
//  BusinessOnboardingViews.swift
//  MowologyCRM
//
//  The onboarding of a new business (Android OnboardingStepScreens.kt): Kaname's face at the top of a vertical spine
//  of numbered circles (ticks when done, no kanji), one question open beside it, and the meniscus bar below with
//  Kaname's tab alone (OnboardingBarLogic): onboarding is the owner's first lesson in how decisions arrive. Terms is shown where the server puts it in the path and cannot
//  be skipped. Copy is Android's, word for word; the Terms copy is the server's.
//

import SwiftUI

struct BusinessOnboardingView: View {
    @ObservedObject var vm: BusinessOnboardingViewModel
    let chiefName: String
    let businessName: String
    let onDone: () -> Void
    @Environment(\.it) private var it
    @Environment(\.openURL) private var openURL
    @Environment(\.scenePhase) private var scenePhase
    @State private var awaitingOAuth = false
    @State private var keyboardUp = false
    @State private var rolling = false      // the lone bead is rolling to Receipts
    @State private var rolled = false
    @State private var glowHigh = false
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @Environment(\.itAppStyle) private var appStyle
    var showsBar = true

    private var bar: ItMeniscusItem { ItMeniscusItem(id: 0, title: "Kaname", icon: .image(KanameTabIcon.image), iconScale: 1.35,
                                                      beadLabel: donePulses ? "Double tap to begin" : nil,
                                                      beadColor: UIColor(it.color(.brand))) }
    private var barReserve: CGFloat { showsBar && !keyboardUp ? MainTabView.barReserve : 0 }

    private var donePulses: Bool {
        guard let map = vm.map else { return false }
        let key = BusinessOnboardingLogic.screenKey(current: vm.current, map: map)
        return OnboardingBarLogic.pulses(screenKey: key, rolling: rolling)
    }

    private func rollIcon(_ i: Int) -> ItMeniscusIcon {
        [.system("calendar"), .system("magnifyingglass"), .image(KanameTabIcon.image), .system("doc.text.image"), .system("plus")][i]
    }

    /// Kaname's tab alone. On the finished Done step its bead pulses and glows; a tap on it ends onboarding and the bead
    /// rolls (the bar's own slide and turn) from Kaname's centre slot to Receipts, then the app opens there.
    private var onboardingBar: some View {
        let items: [ItMeniscusItem] = rolling
            ? OnboardingBarLogic.rollTitles.enumerated().map { i, t in
                ItMeniscusItem(id: i, title: t, icon: rollIcon(i), iconScale: i == OnboardingBarLogic.rollFromSlot ? 1.35 : 1,
                beadColor: i == OnboardingBarLogic.rollFromSlot ? UIColor(it.color(.brand)) : nil) }
            : OnboardingBarLogic.tabs().map { _ in bar }
        let selected = rolling ? (rolled ? OnboardingBarLogic.rollToSlot : OnboardingBarLogic.rollFromSlot) : OnboardingBarLogic.selectedIndex
        return ItMeniscusBar(items: items, selected: selected, onSelect: { _ in },
                             onReselect: { _ in if donePulses { startRoll() } })
            .overlay(alignment: .top) {
                if donePulses {
                    ZStack {
                        Circle().fill(it.color(.brand).opacity(glowHigh ? 0.38 : 0.1)).frame(width: ItMeniscusGeometry.bead + 26, height: ItMeniscusGeometry.bead + 26)
                            .blur(radius: 9)
                        Circle().stroke(it.color(.brand).opacity(glowHigh ? 0.7 : 0.25), lineWidth: 3).frame(width: ItMeniscusGeometry.bead + 6, height: ItMeniscusGeometry.bead + 6)
                        Circle().fill(it.color(.brand)).frame(width: ItMeniscusGeometry.bead, height: ItMeniscusGeometry.bead)
                            .overlay(Image(uiImage: KanameTabIcon.image).renderingMode(.template).resizable().scaledToFit()
                                .frame(width: ItMeniscusToken.icon * 1.35, height: ItMeniscusToken.icon * 1.35).foregroundStyle(Color.white))
                            .scaleEffect(OnboardingBarLogic.pulseScale(reduceMotion: reduceMotion, high: glowHigh))
                    }
                    .frame(width: ItMeniscusGeometry.bead + 40, height: ItMeniscusGeometry.bead + 40)
                    .offset(y: ItMeniscusGeometry.bead / 2 - (ItMeniscusGeometry.bead + 40) / 2)
                    .allowsHitTesting(false).accessibilityHidden(true)
                    .onAppear {
                        if reduceMotion { glowHigh = true }
                        else { withAnimation(.easeInOut(duration: OnboardingBarLogic.pulseSeconds / 2).repeatForever(autoreverses: true)) { glowHigh = true } }
                    }
                }
            }
    }

    private func startRoll() {
        guard !rolling, !vm.loading else { return }
        Task { @MainActor in
            // The server's own finish call, as the old button made it; the bead rolls only once it says complete.
            if !BusinessOnboardingLogic.finishComplete(vm.map?.steps.first { $0.key == "done" }) {
                await vm.act("done", "finish")
                guard BusinessOnboardingLogic.finishComplete(vm.map?.steps.first { $0.key == "done" }) else { return }
            }
            rolling = true
            try? await Task.sleep(nanoseconds: 80_000_000)
            rolled = true
            try? await Task.sleep(nanoseconds: UInt64((OnboardingBarLogic.rollSeconds + 0.1) * 1_000_000_000))
            onDone()
        }
    }

    var body: some View {
        Group {
            if let map = vm.map { content(map) }
            else {
                VStack(alignment: .leading, spacing: ItSpace.s3) {
                    if let e = vm.error { IttaiNotice(text: e, actionTitle: "Retry") { Task { await vm.load() } } }
                    else { ProgressView().frame(maxWidth: .infinity) }
                    Spacer()
                }.padding(ItSpace.s4)
            }
        }
        .background(it.page.ignoresSafeArea())
        .contentMargins(.bottom, barReserve, for: .scrollContent)
        // Kaname's tab alone: selected, going nowhere. It steps aside for the keyboard, as in the main app.
        .safeAreaInset(edge: .bottom, spacing: 0) {
            if showsBar && !keyboardUp { onboardingBar }
        }
        .onReceive(NotificationCenter.default.publisher(for: UIResponder.keyboardWillShowNotification)) { _ in keyboardUp = true }
        .onReceive(NotificationCenter.default.publisher(for: UIResponder.keyboardWillHideNotification)) { _ in keyboardUp = false }
        .task { await vm.load() }
        .onChange(of: vm.quickBooksURL) { _, url in
            guard let url else { return }
            awaitingOAuth = true; openURL(url); vm.consumeQuickBooksURL()
        }
        .onChange(of: scenePhase) { _, phase in
            if phase == .active, awaitingOAuth { awaitingOAuth = false; Task { await vm.load() } }
        }
    }

    private func content(_ map: BusinessOnboardingLogic.MapState) -> some View {
        let key = BusinessOnboardingLogic.screenKey(current: vm.current, map: map)
        let step = map.steps.first { $0.key == key }
        let marks = BusinessOnboardingLogic.axisMarks(map)
        return ScrollView {
            VStack(spacing: 0) {
                // Kaname at the top of the centre line, exactly as on the Team screen (name left, face, role right).
                SpineHead(slug: "charlie", name: chiefName.isEmpty ? "Kaname" : chiefName)
                ForEach(marks, id: \.key) { m in
                    // The Done step shows no card: its node is ticked and the pulsing Kaname bead is the way forward.
                    let atDone = key == "done" && m.key == "done"
                    let current = m.key == key && !atDone
                    SpineStepNode(number: m.number, title: m.title, detail: (m.state == .done && !current) || atDone ? "Done" : (m.state == .skipped ? "Later" : ""),
                                  done: (m.state == .done && !current) || atDone, current: current) {
                        VStack(alignment: .leading, spacing: ItSpace.s3) {
                            stepBody(key: key, step: step, map: map, mark: m)
                            if let e = vm.stepError { IttaiNotice(text: e) }
                            if vm.loading { ProgressView() }
                            if let step, BusinessOnboardingLogic.skippable(step) {
                                Button("Skip for now") { Task { await vm.skip(step.key) } }
                                    .buttonStyle(IttaiGhostButtonStyle()).disabled(vm.loading)
                            }
                        }
                    }
                }
                if key == "done", let e = vm.stepError { IttaiNotice(text: e).padding(.top, ItSpace.s3) }
                SpineTail()
            }
            .padding(ItSpace.s4)
        }
        .scrollDismissesKeyboard(.interactively)
        .solidStatusBar(it.page)
    }

    @ViewBuilder private func stepBody(key: String, step: BusinessOnboardingLogic.Step?, map: BusinessOnboardingLogic.MapState,
                                       mark: BusinessOnboardingLogic.AxisMark?) -> some View {
        switch key {
        case "name": NameStep(vm: vm, step: step, mark: mark)
        case "terms": TermsStep(vm: vm, step: step, mark: mark)
        case "company": CompanyStep(vm: vm, step: step, mark: mark, fallbackName: businessName)
        case "quickbooks": QuickBooksStep(vm: vm, step: step, mark: mark)
        case "books":
            StepTitle(mark: mark, title: step?.head.flatMap { $0.isEmpty ? nil : $0 } ?? "Penny keeps your books in order from here.", answer: nil)
            PrimaryButton(title: "OK", busy: "Saving…", loading: vm.loading) { Task { await vm.act("books", "ack") } }
        case "accountant": AccountantStep(vm: vm, step: step, mark: mark, business: businessName)
        default: EmptyView()   // Done shows no card: ticked spine, the pulsing Kaname bead is the way on
        }
    }
}

// MARK: - Pieces

/// The timeline: numbered circles that turn into ticks. 28 pt circle, 1 pt border in the brand colour (2 pt and a
/// surface fill on the current step). A pending step shows its number (12 pt semibold); a done step is filled with the
/// brand colour and shows a white 11 pt bold checkmark; skipped steps get a grey (line-colour) border. A 1 pt line in
/// the hairline colour, 18 pt tall, joins each circle to the next. No text beside or under the circles.
private struct StepTitle: View {
    let mark: BusinessOnboardingLogic.AxisMark?
    let title: String
    let answer: String?
    @Environment(\.it) private var it
    @Environment(\.accessibilityReduceMotion) private var reduceMotion
    @State private var typed = 0
    var body: some View {
        VStack(alignment: .leading, spacing: ItSpace.s1) {
            if let mark {
                // The label types itself out letter by letter (owner 2026-11-13); finished steps and Reduce Motion show it whole.
                let full = "\(mark.number)  \(mark.title)"
                let shown = (answer != nil || reduceMotion) ? full : String(full.prefix(typed))
                Text(shown).itText(.meta13, weight: 600).foregroundStyle(it.meta)
                    .frame(maxWidth: .infinity, alignment: .leading)
                    .accessibilityLabel(full)
                    .task(id: full) {
                        typed = 0
                        while typed < full.count && !Task.isCancelled && answer == nil && !reduceMotion {
                            try? await Task.sleep(nanoseconds: 35_000_000); typed += 1
                        }
                    }
            }
            Text(title).itText(.title22).foregroundStyle(it.text).accessibilityAddTraits(.isHeader)
            if let answer { Label(answer, systemImage: "checkmark").itText(.body14).foregroundStyle(it.color(.statusComplete)) }
        }
    }
}

private struct PrimaryButton: View {
    let title: String; var busy = "Saving…"; let loading: Bool; let action: () -> Void
    var body: some View { Button(loading ? busy : title, action: action).buttonStyle(IttaiPrimaryButtonStyle()).disabled(loading) }
}
private func completed(_ step: BusinessOnboardingLogic.Step?) -> String? {
    guard let step, step.state == .done else { return nil }
    return BusinessOnboardingLogic.answer(step)
}

// MARK: - Terms (blocking)

struct TermsStep: View {
    @ObservedObject var vm: BusinessOnboardingViewModel
    let step: BusinessOnboardingLogic.Step?
    let mark: BusinessOnboardingLogic.AxisMark?
    @Environment(\.it) private var it
    @State private var sheetURL: URL?
    var body: some View {
        let data = BusinessOnboardingLogic.termsData(step?.data ?? [:])
        StepTitle(mark: mark, title: BusinessOnboardingLogic.termsQuestion, answer: nil)
        VStack(alignment: .leading, spacing: 0) {
            linkRow("Terms", data.termsURL)
            linkRow("Privacy policy", data.privacyURL)
        }
        .background(it.surface).overlay(RoundedRectangle(cornerRadius: ItRadius.md).stroke(it.line, lineWidth: 1))
        .clipShape(RoundedRectangle(cornerRadius: ItRadius.md))
        // Required: no skip, no "Later". The server alone says when it is accepted.
        PrimaryButton(title: "I accept", busy: "Saving…", loading: vm.loading) { Task { await vm.act("terms", "accept") } }
        .sheet(item: Binding(get: { sheetURL.map { IdentifiedURL(url: $0) } }, set: { sheetURL = $0?.url })) { SafariSheet(url: $0.url).ignoresSafeArea() }
    }
    private func linkRow(_ title: String, _ url: String) -> some View {
        Button { sheetURL = URL(string: url) } label: {
            HStack { Text(title).itText(.body16, weight: 500); Spacer(); Image(systemName: "arrow.up.forward.square") }
                .foregroundStyle(it.brand).padding(.horizontal, ItSpace.s3).frame(minHeight: ItSize.targetPhone)
        }
        .accessibilityHint("Opens in a sheet")
    }
}

// MARK: - About you

private struct NameStep: View {
    @ObservedObject var vm: BusinessOnboardingViewModel
    let step: BusinessOnboardingLogic.Step?
    let mark: BusinessOnboardingLogic.AxisMark?
    @State private var first = ""
    @State private var last = ""
    @State private var preferred = ""
    @State private var mobile = ""
    @State private var channel = "text"
    @State private var from = "20:00"
    @State private var to = "07:00"
    @State private var weekends = false
    @State private var localError: String?
    @State private var loaded = false
    @Environment(\.it) private var it

    var body: some View {
        let d = BusinessOnboardingLogic.nameData(step?.data ?? [:])
        let part = d.nextPart ?? "name"
        Group {
            switch part {
            case "reach":
                StepTitle(mark: mark, title: "How should I reach you, \(d.preferred.isEmpty ? d.first : d.preferred)?", answer: completed(step))
                ForEach([("Text me", "text"), ("Email me", "email"), ("Call me", "call")], id: \.1) { label, key in
                    Button { channel = key } label: {
                        HStack { Image(systemName: channel == key ? "largecircle.fill.circle" : "circle"); Text(label); Spacer() }
                            .itText(.body16).foregroundStyle(it.text).frame(minHeight: ItSize.targetPhone)
                    }
                }
                IttaiField(label: "Cell number", text: $mobile, keyboard: .phonePad)
                if let e = localError { Text(e).itText(.caption12).foregroundStyle(it.color(.statusBlocked)) }
                PrimaryButton(title: "Save", loading: vm.loading) {
                    guard let n = SignUpLogic.normaliseMobile(mobile) else { localError = "Enter your mobile number (10 digits)."; return }
                    localError = nil
                    Task { await vm.act("name", "reach", fields: ["channel": channel, "mobile": n]) }
                }
            case "quiet":
                StepTitle(mark: mark, title: "When should I keep quiet?", answer: completed(step))
                HStack(spacing: ItSpace.s2) {
                    IttaiField(label: "From", text: $from, keyboard: .numbersAndPunctuation)
                    IttaiField(label: "To", text: $to, keyboard: .numbersAndPunctuation)
                }
                Toggle("Weekends too", isOn: $weekends).itText(.body16).foregroundStyle(it.text).tint(it.brand).frame(minHeight: ItSize.targetPhone)
                if let e = localError { Text(e).itText(.caption12).foregroundStyle(it.color(.statusBlocked)) }
                PrimaryButton(title: "Save", loading: vm.loading) {
                    guard BusinessOnboardingLogic.validTime(from), BusinessOnboardingLogic.validTime(to) else { localError = "Use a 24-hour time like 20:00."; return }
                    localError = nil
                    Task { await vm.act("name", "quiet", fields: ["from": from, "to": to, "weekends": weekends]) }
                }
            default:
                StepTitle(mark: mark, title: "What should I call you?", answer: completed(step))
                IttaiField(label: "First name", text: $first, contentType: .givenName, capitalization: .words)
                IttaiField(label: "Last name", text: $last, contentType: .familyName, capitalization: .words)
                IttaiField(label: "Preferred name", text: $preferred)
                Text("Leave it blank to use your first name.").itText(.meta13).foregroundStyle(it.meta)
                PrimaryButton(title: "That's me", loading: vm.loading) {
                    Task { await vm.act("name", "save", fields: ["first": first.trimmingCharacters(in: .whitespaces), "last": last.trimmingCharacters(in: .whitespaces), "preferred": preferred.trimmingCharacters(in: .whitespaces)]) }
                }
            }
        }
        .onAppear {
            guard !loaded else { return }
            loaded = true
            first = d.first; last = d.last; preferred = d.preferred; mobile = d.mobile
            channel = d.channel.isEmpty ? "text" : d.channel
            if let q = d.quiet { from = q.from; to = q.to; weekends = q.weekends }
        }
    }
}

// MARK: - Company

private struct CompanyStep: View {
    @ObservedObject var vm: BusinessOnboardingViewModel
    let step: BusinessOnboardingLogic.Step?
    let mark: BusinessOnboardingLogic.AxisMark?
    let fallbackName: String
    @State private var name = ""
    @State private var seeded = false
    @Environment(\.it) private var it

    var body: some View {
        let d = BusinessOnboardingLogic.companyData(step?.data ?? [:])
        let reply = vm.reply?.key == "company" ? vm.reply?.data : nil
        let matches = BusinessOnboardingLogic.matches(reply ?? step?.data)
        StepTitle(mark: mark, title: "What's your business called? I'll look it up.", answer: completed(step))
        if d.confirmed {
            Text(d.operatingName.isEmpty ? d.legalName : d.operatingName).itText(.body16, weight: 600).foregroundStyle(it.text)
            Label("Confirmed", systemImage: "checkmark").itText(.body14).foregroundStyle(it.color(.statusComplete))
            PrimaryButton(title: "Continue", busy: "One moment…", loading: vm.loading) { vm.continueFrom(next: vm.reply?.next) }
        } else {
            IttaiField(label: "Business name", text: $name, capitalization: .words, submit: .search)
            PrimaryButton(title: "Look up", busy: "Looking…", loading: vm.loading) { Task { await vm.act("company", "search", fields: ["name": name.trimmingCharacters(in: .whitespaces)]) } }
            ForEach(matches, id: \.recordId) { m in
                Button { Task { await vm.act("company", "confirm", fields: ["record_id": m.recordId]) } } label: {
                    VStack(alignment: .leading) {
                        Text(BusinessOnboardingLogic.displayCase(m.name)).itText(.body16, weight: 600).foregroundStyle(it.text)
                        Text("\(m.registrationNumber) · \(m.status)").itText(.meta13).foregroundStyle(it.meta)
                    }
                    .frame(maxWidth: .infinity, minHeight: 64, alignment: .leading).padding(.horizontal, ItSpace.s3)
                    .background(RoundedRectangle(cornerRadius: ItRadius.md).fill(it.surface))
                    .overlay(RoundedRectangle(cornerRadius: ItRadius.md).stroke(it.line, lineWidth: 1))
                }.disabled(vm.loading)
            }
            if let reply {
                if BusinessOnboardingLogic.registryUnreachable(reply) {
                    IttaiNotice(text: "The registry can't be reached right now — enter the name yourself.")
                } else if matches.isEmpty {
                    Text("I couldn't find it in the registry.").itText(.body16).foregroundStyle(it.text)
                }
                Button("It's not listed — use the name I typed") { Task { await vm.act("company", "manual", fields: ["name": name.trimmingCharacters(in: .whitespaces)]) } }
                    .buttonStyle(IttaiGhostButtonStyle()).disabled(vm.loading)
            }
        }
        Color.clear.frame(height: 0).onAppear {
            guard !seeded else { return }
            seeded = true
            name = d.enteredName.isEmpty ? (["Ittai", "Test"].contains { $0.caseInsensitiveCompare(fallbackName) == .orderedSame } ? "" : fallbackName) : d.enteredName
        }
    }
}

// MARK: - Books

private struct QuickBooksStep: View {
    @ObservedObject var vm: BusinessOnboardingViewModel
    let step: BusinessOnboardingLogic.Step?
    let mark: BusinessOnboardingLogic.AxisMark?
    @Environment(\.it) private var it
    var body: some View {
        let d = BusinessOnboardingLogic.quickBooksData(step?.data ?? [:])
        StepTitle(mark: mark, title: "Do you use QuickBooks?", answer: completed(step))
        if d.answer != "yes" {
            PrimaryButton(title: "Yes", loading: vm.loading) { Task { await vm.act("quickbooks", "yes") } }
            Button("No") { Task { await vm.act("quickbooks", "no") } }.buttonStyle(IttaiGhostButtonStyle()).disabled(vm.loading)
        } else if !d.connected {
            Text("You sign in at Intuit; Ittai never sees your QuickBooks password.").itText(.meta13).foregroundStyle(it.meta)
            PrimaryButton(title: "Connect QuickBooks", busy: "Opening Intuit…", loading: vm.loading) { Task { await vm.startQuickBooks() } }
        } else {
            Label("Connected", systemImage: "checkmark").itText(.body14).foregroundStyle(it.color(.statusComplete))
            Button("Continue") { vm.continueFrom(next: vm.map?.next) }.buttonStyle(IttaiGhostButtonStyle())
        }
    }
}

// MARK: - Accountant

private struct AccountantStep: View {
    @ObservedObject var vm: BusinessOnboardingViewModel
    let step: BusinessOnboardingLogic.Step?
    let mark: BusinessOnboardingLogic.AxisMark?
    let business: String
    @State private var name = ""
    @State private var email = ""
    @State private var localError: String?
    @State private var copied = false
    @Environment(\.it) private var it
    var body: some View {
        let invite = BusinessOnboardingLogic.invite(vm.reply?.key == "accountant" ? vm.reply?.data : step?.data)
        StepTitle(mark: mark, title: "Would you like to invite your accountant?", answer: completed(step))
        if let invite {
            Text("Invitation ready for \(invite.name)").itText(.heading18).foregroundStyle(it.text)
            Text("Send it to them — Ittai doesn't email it.").itText(.body16).foregroundStyle(it.text)
            ShareLink(item: BusinessOnboardingLogic.inviteShareText(invite, business: business)) {
                Text("Share").itText(.body16, weight: 600).frame(maxWidth: .infinity, minHeight: 56).foregroundStyle(it.mode == .dark ? it.page : .white)
                    .background(RoundedRectangle(cornerRadius: ItRadius.md).fill(it.brand))
            }
            Button(copied ? "Copied" : "Copy") { UIPasteboard.general.string = invite.link; copied = true }.buttonStyle(IttaiGhostButtonStyle())
            PrimaryButton(title: "Continue", busy: "One moment…", loading: vm.loading) { vm.continueFrom(next: vm.reply?.next) }
        } else {
            IttaiField(label: "Name", text: $name, contentType: .name, capitalization: .words)
            IttaiField(label: "Email", text: $email, contentType: .emailAddress, keyboard: .emailAddress, submit: .done)
            if let e = localError { Text(e).itText(.caption12).foregroundStyle(it.color(.statusBlocked)) }
            PrimaryButton(title: "Invite", busy: "Inviting…", loading: vm.loading) {
                if name.trimmingCharacters(in: .whitespaces).isEmpty || !SignUpLogic.emailValid(email) { localError = "Enter a name and valid email address."; return }
                localError = nil
                Task { await vm.act("accountant", "invite", fields: ["name": name.trimmingCharacters(in: .whitespaces), "email": email.trimmingCharacters(in: .whitespaces)]) }
            }
        }
    }
}

