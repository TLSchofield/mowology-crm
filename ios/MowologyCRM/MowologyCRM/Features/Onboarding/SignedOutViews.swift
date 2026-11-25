//
//  SignedOutViews.swift
//  MowologyCRM
//
//  Welcome, Create your login, Check your email. Copy and field rules are Android's, word for word
//  (WelcomeScreen.kt, SignUpScreen.kt, SignUpCodeScreen.kt). Ittai look: navy ground, the wordmark with its gold
//  dots and bar; forms on the Ittai page colour. Colours come from the Ittai tokens only.
//

import SwiftUI
import SafariServices

/// The Ittai filled button: brand (navy; the on-navy blue in dark) with the page colour as its label.
struct IttaiPrimaryButtonStyle: ButtonStyle {
    @Environment(\.it) private var it
    @Environment(\.isEnabled) private var enabled
    func makeBody(configuration: Configuration) -> some View {
        configuration.label.itText(.body16, weight: 600)
            .frame(maxWidth: .infinity, minHeight: 56)
            .foregroundStyle(it.mode == .dark ? it.page : Color.white)
            .background(RoundedRectangle(cornerRadius: ItRadius.md).fill(it.brand))
            .opacity(enabled ? (configuration.isPressed ? 0.8 : 1) : 0.55)
            .contentShape(RoundedRectangle(cornerRadius: ItRadius.md))
    }
}
/// A quiet text button.
struct IttaiGhostButtonStyle: ButtonStyle {
    @Environment(\.it) private var it
    @Environment(\.isEnabled) private var enabled
    var onNavy = false
    func makeBody(configuration: Configuration) -> some View {
        configuration.label.itText(.body16, weight: 500)
            .frame(maxWidth: .infinity, minHeight: 48)
            .foregroundStyle(onNavy ? ItOpeningColors.letters : it.brand)
            .opacity(enabled ? (configuration.isPressed ? 0.7 : 1) : 0.55)
            .contentShape(Rectangle())
    }
}

// MARK: - Welcome

/// The words and buttons that fade in under the wordmark once the opening ends (the Welcome screen).
struct IttaiWelcomeContent: View {
    let onSetUp: () -> Void
    let onSignIn: () -> Void
    var body: some View {
        VStack(spacing: ItSpace.s2) {
            Text("One business, working as one.").itText(.body16).foregroundStyle(ItOpeningColors.letters)
                .multilineTextAlignment(.center)
            Spacer().frame(height: ItSpace.s4)
            Button(action: onSetUp) {
                Text("Set up my office").itText(.body16, weight: 600)
                    .frame(maxWidth: .infinity, minHeight: 56)
                    .foregroundStyle(ItOpeningColors.navy)
                    .background(RoundedRectangle(cornerRadius: ItRadius.md).fill(ItOpeningColors.letters))
            }
            Button(action: onSignIn) { Text("I already have an account") }
                .buttonStyle(IttaiGhostButtonStyle(onNavy: true))
        }
        .padding(.horizontal, ItSpace.s4).padding(.vertical, ItSpace.s4)
    }
}

// MARK: - Shared bits

struct IttaiNotice: View {
    let text: String
    var good = false
    var actionTitle: String?
    var action: (() -> Void)?
    @Environment(\.it) private var it
    var body: some View {
        let colour = it.color(good ? .statusComplete : .statusBlocked)
        HStack(alignment: .top, spacing: ItSpace.s2) {
            Image(systemName: good ? "checkmark.circle.fill" : "octagon.fill").foregroundStyle(colour)
            VStack(alignment: .leading, spacing: ItSpace.s1) {
                Text(text).itText(.body14).foregroundStyle(colour).fixedSize(horizontal: false, vertical: true)
                if let actionTitle, let action { Button(actionTitle, action: action).itText(.body14, weight: 600).foregroundStyle(it.brand) }
            }
            Spacer(minLength: 0)
        }
        .padding(ItSpace.s3)
        .background(RoundedRectangle(cornerRadius: ItRadius.md).fill(colour.opacity(0.08)))
        .overlay(RoundedRectangle(cornerRadius: ItRadius.md).stroke(colour.opacity(0.3), lineWidth: 1))
        .accessibilityElement(children: .combine)
    }
}

struct IttaiField<Trailing: View>: View {
    let label: String
    @Binding var text: String
    var error: String?
    var secure = false
    var contentType: UITextContentType?
    var keyboard: UIKeyboardType = .default
    var capitalization: TextInputAutocapitalization = .never
    var submit: SubmitLabel = .next
    var onSubmit: (() -> Void)?
    @ViewBuilder var trailing: Trailing
    @Environment(\.it) private var it

    var body: some View {
        VStack(alignment: .leading, spacing: ItSpace.s1) {
            Text(label).itText(.meta13, weight: 600).foregroundStyle(it.meta)
            HStack {
                Group {
                    if secure { SecureField("", text: $text) } else { TextField("", text: $text) }
                }
                .textContentType(contentType).keyboardType(keyboard).textInputAutocapitalization(capitalization)
                .autocorrectionDisabled().submitLabel(submit).onSubmit { onSubmit?() }
                .itText(.body16).foregroundStyle(it.text)
                trailing
            }
            .padding(.horizontal, ItSpace.s3).frame(minHeight: ItSize.targetPhone)
            .background(RoundedRectangle(cornerRadius: ItRadius.md).fill(it.surface))
            .overlay(RoundedRectangle(cornerRadius: ItRadius.md).stroke(error == nil ? it.line : it.color(.statusBlocked), lineWidth: error == nil ? 1 : 2))
            if let error { Text(error).itText(.caption12).foregroundStyle(it.color(.statusBlocked)) }
        }
    }
}
extension IttaiField where Trailing == EmptyView {
    init(label: String, text: Binding<String>, error: String? = nil, secure: Bool = false, contentType: UITextContentType? = nil,
         keyboard: UIKeyboardType = .default, capitalization: TextInputAutocapitalization = .never,
         submit: SubmitLabel = .next, onSubmit: (() -> Void)? = nil) {
        self.init(label: label, text: text, error: error, secure: secure, contentType: contentType, keyboard: keyboard,
                  capitalization: capitalization, submit: submit, onSubmit: onSubmit, trailing: { EmptyView() })
    }
}

extension View {
    /// A solid strip the height of the top safe area, drawn above the content and not hit-testable, so a scroll view's
    /// content never shows through behind the clock. `color` is the page colour (light, dark and field all work).
    func solidStatusBar(_ color: Color) -> some View {
        overlay(alignment: .top) {
            GeometryReader { p in
                color.frame(height: p.safeAreaInsets.top).offset(y: -p.safeAreaInsets.top)
            }
            .allowsHitTesting(false)
        }
    }
}

struct IdentifiedURL: Identifiable { let url: URL; var id: String { url.absoluteString } }

struct SafariSheet: UIViewControllerRepresentable {
    let url: URL
    func makeUIViewController(context: Context) -> SFSafariViewController { SFSafariViewController(url: url) }
    func updateUIViewController(_ vc: SFSafariViewController, context: Context) {}
}

// MARK: - Create your login

struct SignUpFormView: View {
    @ObservedObject var vm: SignUpViewModel
    let onBack: () -> Void
    let onSignIn: () -> Void
    @Environment(\.it) private var it
    @State private var sheetURL: URL?

    var body: some View {
        ScrollViewReader { proxy in
        ScrollView {
            VStack(alignment: .leading, spacing: ItSpace.s3) {
                HStack { Button(action: onBack) { Label("Back", systemImage: "chevron.left") }.foregroundStyle(it.brand); Spacer() }
                    .itText(.body16, weight: 500)
                Text("Create your login").itText(.title28).foregroundStyle(it.text).accessibilityAddTraits(.isHeader)
                Text("Just you for now — the business comes next.").itText(.body16).foregroundStyle(it.meta)
                // Android on a phone: two full-width stacked fields.
                IttaiField(label: "First name", text: field(\.firstName), error: vm.errors["first_name"], contentType: .givenName, capitalization: .words)
                IttaiField(label: "Last name", text: field(\.lastName), error: vm.errors["last_name"], contentType: .familyName, capitalization: .words)
                IttaiField(label: "Cell number", text: field(\.mobile), error: vm.errors["mobile"], contentType: .telephoneNumber, keyboard: .phonePad)
                IttaiField(label: "Email", text: field(\.email), error: vm.errors["email"], contentType: .emailAddress, keyboard: .emailAddress)
                IttaiField(label: "Password (10+ characters)", text: passwordField(\.password), error: vm.errors["password"], secure: !vm.showPasswords,
                           contentType: .newPassword, trailing: { showHide })
                Button("Make a strong password for me") { vm.generatePassword() }
                    .buttonStyle(IttaiGhostButtonStyle()).disabled(vm.loading)
                if let help = vm.passwordHelp { Text(help).itText(.meta13).foregroundStyle(it.meta) }
                IttaiField(label: "Confirm password", text: passwordField(\.confirm), error: vm.errors["confirm"], secure: !vm.showPasswords,
                           contentType: .newPassword, submit: .done, onSubmit: { Task { await vm.submit() } }, trailing: { showHide })
                termsRow
                if let e = vm.error {
                    IttaiNotice(text: e, actionTitle: e.localizedCaseInsensitiveContains("sign in") ? "Sign in" : nil, action: onSignIn)
                }
                Button(vm.loading ? "Creating…" : "Create my login") { Task { await vm.submit() } }
                    .buttonStyle(IttaiPrimaryButtonStyle()).disabled(vm.loading || !SignUpLogic.canSubmit(vm.input))
                Button("Already have a login? Sign in", action: onSignIn).buttonStyle(IttaiGhostButtonStyle()).disabled(vm.loading)
            }
            .padding(ItSpace.s4)
        }
        .scrollDismissesKeyboard(.interactively)
        .background(it.page.ignoresSafeArea())
        .solidStatusBar(it.page)
        #if DEBUG
        // `-mwScrollDemo`: scroll the form so its top slides toward the clock (proof that nothing shows behind it).
        .onAppear { if ProcessInfo.processInfo.arguments.contains("-mwScrollDemo") { DispatchQueue.main.asyncAfter(deadline: .now() + 0.5) { proxy.scrollTo("termsRow", anchor: .bottom) } } }
        #endif
        }
    }

    /// Required, never pre-ticked. "Terms" and "Privacy Policy" open in an in-app Safari sheet.
    private var termsRow: some View {
        VStack(alignment: .leading, spacing: ItSpace.s1) {
            // Android TermsAgree: a row at least 48 high; the checkbox sits in its own 48 pt touch target (box 18 pt
            // centred in it), then the text. Nothing extra above or below: the column's 12 pt gap does the spacing.
            HStack(spacing: 0) {
                Button { vm.input.termsAccepted.toggle(); vm.edited() } label: {
                    Image(systemName: vm.input.termsAccepted ? "checkmark.square.fill" : "square").font(.system(size: 22))
                        .foregroundStyle(vm.input.termsAccepted ? it.brand : it.meta).frame(width: 48, height: 48)
                }
                .disabled(vm.loading)
                .accessibilityLabel("I agree to the Terms and Privacy Policy")
                .accessibilityValue(vm.input.termsAccepted ? "ticked" : "not ticked")
                (Text("I agree to the ")
                 + Text("[Terms](\(SignUpLogic.termsURL))").underline()
                 + Text(" and ")
                 + Text("[Privacy Policy](\(SignUpLogic.privacyURL))").underline())
                    .itText(.body16).foregroundStyle(it.text).tint(it.brand)
                    .environment(\.openURL, OpenURLAction { url in sheetURL = url; return .handled })
                    .frame(maxWidth: .infinity, alignment: .leading)
            }
            .frame(minHeight: 48)
            if let e = vm.errors["terms"] { Text(e).itText(.caption12).foregroundStyle(it.color(.statusBlocked)) }
        }
        .id("termsRow")
        .sheet(item: Binding(get: { sheetURL.map { IdentifiedURL(url: $0) } }, set: { sheetURL = $0?.url })) { SafariSheet(url: $0.url).ignoresSafeArea() }
    }

    private var showHide: some View {
        Button(vm.showPasswords ? "Hide" : "Show") { vm.showPasswords.toggle() }
            .itText(.meta13, weight: 600).foregroundStyle(it.brand)
    }
    private func field(_ k: WritableKeyPath<SignUpLogic.Input, String>) -> Binding<String> {
        Binding(get: { vm.input[keyPath: k] }, set: { vm.input[keyPath: k] = $0; vm.edited() })
    }
    private func passwordField(_ k: WritableKeyPath<SignUpLogic.Input, String>) -> Binding<String> {
        Binding(get: { vm.input[keyPath: k] }, set: { vm.input[keyPath: k] = $0; vm.edited(); vm.passwordEdited() })
    }
}

// MARK: - Check your email

struct SignUpCodeView: View {
    @ObservedObject var vm: SignUpViewModel
    @Environment(\.it) private var it
    @FocusState private var focused: Bool

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: ItSpace.s3) {
                Text("Check your email").itText(.title28).foregroundStyle(it.text).accessibilityAddTraits(.isHeader)
                Text(SignUpLogic.sentWords(vm.sent, email: vm.input.email)).itText(.body16).foregroundStyle(it.text)
                // Six boxes, one digit each, over ONE invisible field that owns the keyboard, one-time-code autofill and paste.
                ZStack {
                    HStack(spacing: ItSpace.s2) {
                        ForEach(0..<SignUpLogic.codeLength, id: \.self) { i in
                            let active = i == min(vm.code.count, SignUpLogic.codeLength - 1)
                            let stroke = vm.codeError != nil ? it.color(.statusBlocked) : (active ? it.brand : it.line)
                            Text(digit(i)).itText(.title22, weight: 600).foregroundStyle(it.text)
                                .frame(maxWidth: .infinity, minHeight: 56)
                                .background(RoundedRectangle(cornerRadius: ItRadius.md).fill(it.surface))
                                .overlay(RoundedRectangle(cornerRadius: ItRadius.md).stroke(stroke, lineWidth: active || vm.codeError != nil ? 2 : 1))
                        }
                    }
                    TextField("", text: Binding(get: { vm.code }, set: { vm.setCode($0) }))
                        .keyboardType(.numberPad).textContentType(.oneTimeCode).focused($focused)
                        .opacity(0.02).tint(.clear).foregroundStyle(.clear)
                        .accessibilityLabel("6-digit code")
                }
                .contentShape(Rectangle()).onTapGesture { focused = true }
                if let e = vm.codeError { Text(e).itText(.caption12).foregroundStyle(it.color(.statusBlocked)) }
                if let n = vm.notice { IttaiNotice(text: n, good: true) }
                if let e = vm.error { IttaiNotice(text: e) }
                Button(vm.loading ? "Checking…" : "Continue") { Task { await vm.confirm() } }
                    .buttonStyle(IttaiPrimaryButtonStyle()).disabled(vm.loading || !SignUpLogic.codeValid(vm.code))
                Button("Send a new code") { Task { await vm.resend() } }
                    .buttonStyle(IttaiGhostButtonStyle()).disabled(vm.loading)
                Button("Change email") { vm.changeEmail() }.buttonStyle(IttaiGhostButtonStyle()).disabled(vm.loading)
            }
            .padding(ItSpace.s4)
        }
        .background(it.page.ignoresSafeArea())
        .solidStatusBar(it.page)
        .onAppear { focused = true }
        .onChange(of: vm.code) { _, c in if SignUpLogic.codeValid(c) { focused = false } }
    }
    private func digit(_ i: Int) -> String {
        let a = Array(vm.code)
        return i < a.count ? String(a[i]) : ""
    }
}
