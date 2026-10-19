//
//  LoginView.swift
//  MowologyCRM
//
//  Created by Mowology on 2026-03-03.
//

import SwiftUI

struct LoginView: View {
    @Environment(\.itAppStyle) private var appStyle

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
    init(authSession: AuthSession) {
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
    }

    // MARK: - Brand Header

    private var brandHeader: some View {
        VStack(spacing: 8) {
            // Leaf icon as a system symbol stand-in for the Mowology logo.
            Image(systemName: "leaf.fill")
                .font(appStyle.font(size: 48, weight: .medium))
                .foregroundStyle(.white.opacity(0.95))
                .padding(.top, 72)
                .padding(.bottom, 4)

            Text("Mowology")
                .font(appStyle.font(size: 34, weight: .bold, design: .rounded))
                .foregroundStyle(.white)

            Text("Field Manager")
                .font(appStyle.font(size: 15, weight: .medium))
                .foregroundStyle(.white.opacity(0.80))
                .padding(.bottom, 40)
        }
        .frame(maxWidth: .infinity)
        .background(
            LinearGradient(
                colors: [
                    appStyle.tenant,  // #2D8659
                    Color.MW.dark   // #1A5F4A
                ],
                startPoint: .topLeading,
                endPoint: .bottomTrailing
            )
        )
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

                TextField("you@mowology.ca", text: $viewModel.email)
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
                            .tint(appStyle.onTenant)
                            .scaleEffect(0.9)
                    }
                    Text(viewModel.isLoading ? "Signing In…" : "Sign In")
                        .font(appStyle.font(.body, weight: .bold))
                }
                .frame(maxWidth: .infinity)
                .padding(.vertical, 14)
                .background(
                    viewModel.isLoading
                        ? appStyle.tenant.opacity(0.7)
                        : appStyle.tenant
                )
                .foregroundStyle(appStyle.onTenant)
                .clipShape(RoundedRectangle(cornerRadius: appStyle.cornerRadius(12)))
            }
            .disabled(viewModel.isLoading)
            .padding(.top, 4)

            // Footer note — no signup link by design.
            Text("Access is restricted to Mowology staff.")
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
