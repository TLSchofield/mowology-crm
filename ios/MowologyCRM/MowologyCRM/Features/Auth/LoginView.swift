//
//  LoginView.swift
//  MowologyCRM
//
//  Created by Mowology on 2026-03-03.
//

import SwiftUI

struct LoginView: View {
    @Environment(\.itAppStyle) private var appStyle
    @Environment(\.it) private var it
    /// When the person arrived from the Welcome screen, a Back button returns there.
    var onBack: (() -> Void)?

    @EnvironmentObject private var authSession: AuthSession
    @StateObject private var viewModel: LoginViewModel

    // Focus management for keyboard dismissal.
    @FocusState private var focusedField: Field?

    private enum Field: Hashable {
        case email, password
    }

    // Initialise the view model with the environment AuthSession.
    // We use _viewModel + StateObject(wrappedValue:) so the VM is created
    // once and shares the same authSession reference.
    init() {
        // Temporary placeholder — replaced in body via onAppear.
        // We cannot access @EnvironmentObject in init, so we work around this
        // by creating the VM lazily via .task or by injecting via a parent.
        // Best practice: pass authSession explicitly from RootView.
        _viewModel = StateObject(wrappedValue: LoginViewModel(authSession: AuthSession()))
    }

    // Designated initialiser used by RootView which supplies the real session.
    init(authSession: AuthSession, onBack: (() -> Void)? = nil) {
        self.onBack = onBack
        _viewModel = StateObject(wrappedValue: LoginViewModel(authSession: authSession))
    }

    // MARK: - Body

    var body: some View {
        ScrollView {
            VStack(spacing: 0) {

                // MARK: Brand Header
                brandHeader

                // MARK: Card
                loginCard
                    .padding(.horizontal, 24)
                    .padding(.top, 8)

                Spacer(minLength: 40)
            }
        }
        .background(appStyle.page)
        .ignoresSafeArea(edges: .top)
        .scrollDismissesKeyboard(.interactively)
        .onTapGesture { focusedField = nil }
        .solidStatusBar(ItOpeningColors.navy)
    }

    // MARK: - Brand Header

    /// The Ittai look: navy ground and the wordmark (gold dots on the i's, gold bar across the t's), drawn by the
    /// same frozen geometry as the opening's end state. No business name: the phone does not know it before sign-in.
    private var brandHeader: some View {
        ZStack(alignment: .topLeading) {
            ItOpeningColors.navy
            ItOpeningMark(frame: ItOpeningMotion.frame(ItOpeningMotion.T), zoom: 0.78)
                .frame(height: 150)
                .padding(.top, 76).padding(.bottom, 24)
            if let onBack {
                Button(action: onBack) { Label("Back", systemImage: "chevron.left").foregroundStyle(ItOpeningColors.letters) }
                    .padding(.top, 60).padding(.leading, 20)
            }
        }
        .frame(maxWidth: .infinity)
        .accessibilityElement(children: .ignore)
        .accessibilityLabel("Ittai")
    }

    // MARK: - Login Card

    private var loginCard: some View {
        VStack(alignment: .leading, spacing: 20) {

            Text("Sign In")
                .font(appStyle.font(.title2, weight: .bold))
                .foregroundStyle(appStyle.text)
                .padding(.top, 4)

            // Error Banner
            if let message = viewModel.errorMessage {
                errorBanner(message: message)
            }

            // Email Field
            VStack(alignment: .leading, spacing: 6) {
                Label("Email", systemImage: "envelope")
                    .font(appStyle.font(.footnote, weight: .semibold))
                    .foregroundStyle(appStyle.meta)

                TextField("Email address", text: $viewModel.email)
                    .keyboardType(.emailAddress)
                    .textContentType(.emailAddress)
                    .autocapitalization(.none)
                    .autocorrectionDisabled()
                    .focused($focusedField, equals: .email)
                    .submitLabel(.next)
                    .onSubmit { focusedField = .password }
                    .onChange(of: viewModel.email) { _, _ in viewModel.clearError() }
                    .padding(12)
                    .background(appStyle.surface)
                    .clipShape(RoundedRectangle(cornerRadius: appStyle.cornerRadius(10)))
                    .overlay(
                        RoundedRectangle(cornerRadius: appStyle.cornerRadius(10))
                            .stroke(appStyle.line, lineWidth: 1)
                    )
            }

            // Password Field
            VStack(alignment: .leading, spacing: 6) {
                Label("Password", systemImage: "lock")
                    .font(appStyle.font(.footnote, weight: .semibold))
                    .foregroundStyle(appStyle.meta)

                SecureField("Password", text: $viewModel.password)
                    .textContentType(.password)
                    .focused($focusedField, equals: .password)
                    .submitLabel(.done)
                    .onSubmit {
                        focusedField = nil
                        Task { await viewModel.login() }
                    }
                    .onChange(of: viewModel.password) { _, _ in viewModel.clearError() }
                    .padding(12)
                    .background(appStyle.surface)
                    .clipShape(RoundedRectangle(cornerRadius: appStyle.cornerRadius(10)))
                    .overlay(
                        RoundedRectangle(cornerRadius: appStyle.cornerRadius(10))
                            .stroke(appStyle.line, lineWidth: 1)
                    )
            }

            // Sign In Button
            Button {
                focusedField = nil
                Task { await viewModel.login() }
            } label: {
                HStack(spacing: 8) {
                    if viewModel.isLoading {
                        ProgressView()
                            .tint(it.mode == .dark ? it.page : Color.white)
                            .scaleEffect(0.9)
                    }
                    Text(viewModel.isLoading ? "Signing In…" : "Sign In")
                        .font(appStyle.font(.body, weight: .bold))
                }
                .frame(maxWidth: .infinity)
                .padding(.vertical, 14)
                .background(
                    viewModel.isLoading
                        ? it.brand.opacity(0.7)
                        : it.brand
                )
                .foregroundStyle(it.mode == .dark ? it.page : Color.white)
                .clipShape(RoundedRectangle(cornerRadius: appStyle.cornerRadius(12)))
            }
            .disabled(viewModel.isLoading)
            .padding(.top, 4)

            Text("Sign in with the login your business gave you.")
                .font(appStyle.font(.caption))
                .foregroundStyle(.tertiary)
                .frame(maxWidth: .infinity, alignment: .center)
                .padding(.bottom, 4)
        }
        .padding(24)
        .background(appStyle.surface)
        .clipShape(RoundedRectangle(cornerRadius: appStyle.cornerRadius(16)))
        .shadow(color: appStyle.shadow(0.08), radius: 12, x: 0, y: 4)
    }

    // MARK: - Error Banner

    private func errorBanner(message: String) -> some View {
        HStack(alignment: .top, spacing: 10) {
            Image(systemName: "exclamationmark.triangle.fill")
                .foregroundStyle(appStyle.blocked)
                .font(appStyle.font(.subheadline))
                .padding(.top, 1)

            Text(message)
                .font(appStyle.font(.subheadline))
                .foregroundStyle(appStyle.blocked)
                .fixedSize(horizontal: false, vertical: true)

            Spacer()
        }
        .padding(12)
        .background(appStyle.blocked.opacity(0.08))
        .clipShape(RoundedRectangle(cornerRadius: appStyle.cornerRadius(10)))
        .overlay(
            RoundedRectangle(cornerRadius: appStyle.cornerRadius(10))
                .stroke(appStyle.blocked.opacity(0.25), lineWidth: 1)
        )
    }
}

#Preview {
    LoginView(authSession: AuthSession())
}
