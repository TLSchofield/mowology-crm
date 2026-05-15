//
//  LoginView.swift
//  MowologyCRM
//
//  Created by Mowology on 2026-03-03.
//

import SwiftUI

struct LoginView: View {

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
        .background(Color(.systemGroupedBackground))
        .ignoresSafeArea(edges: .top)
        .scrollDismissesKeyboard(.interactively)
        .onTapGesture { focusedField = nil }
    }

    // MARK: - Brand Header

    private var brandHeader: some View {
        VStack(spacing: 8) {
            // Leaf icon as a system symbol stand-in for the Mowology logo.
            Image(systemName: "leaf.fill")
                .font(.system(size: 48, weight: .medium))
                .foregroundStyle(.white.opacity(0.95))
                .padding(.top, 72)
                .padding(.bottom, 4)

            Text("Mowology")
                .font(.system(size: 34, weight: .bold, design: .rounded))
                .foregroundStyle(.white)

            Text("Field Manager")
                .font(.system(size: 15, weight: .medium))
                .foregroundStyle(.white.opacity(0.80))
                .padding(.bottom, 40)
        }
        .frame(maxWidth: .infinity)
        .background(
            LinearGradient(
                colors: [
                    Color.MW.green,  // #2D8659
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
                .font(.title2.bold())
                .foregroundStyle(.primary)
                .padding(.top, 4)

            // Error Banner
            if let message = viewModel.errorMessage {
                errorBanner(message: message)
            }

            // Identifier Field (email OR username)
            VStack(alignment: .leading, spacing: 6) {
                Label("Email or Username", systemImage: "person")
                    .font(.footnote.weight(.semibold))
                    .foregroundStyle(.secondary)

                TextField("Email or username", text: $viewModel.email)
                    .keyboardType(.default)
                    .textContentType(.username)
                    .autocapitalization(.none)
                    .autocorrectionDisabled()
                    .focused($focusedField, equals: .email)
                    .submitLabel(.next)
                    .onSubmit { focusedField = .password }
                    .onChange(of: viewModel.email) { _, _ in viewModel.clearError() }
                    .padding(12)
                    .background(Color(.systemBackground))
                    .clipShape(RoundedRectangle(cornerRadius: 10))
                    .overlay(
                        RoundedRectangle(cornerRadius: 10)
                            .stroke(Color(.systemGray4), lineWidth: 1)
                    )
            }

            // Password Field
            VStack(alignment: .leading, spacing: 6) {
                Label("Password", systemImage: "lock")
                    .font(.footnote.weight(.semibold))
                    .foregroundStyle(.secondary)

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
                    .background(Color(.systemBackground))
                    .clipShape(RoundedRectangle(cornerRadius: 10))
                    .overlay(
                        RoundedRectangle(cornerRadius: 10)
                            .stroke(Color(.systemGray4), lineWidth: 1)
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
                            .tint(.white)
                            .scaleEffect(0.9)
                    }
                    Text(viewModel.isLoading ? "Signing In…" : "Sign In")
                        .font(.body.bold())
                }
                .frame(maxWidth: .infinity)
                .padding(.vertical, 14)
                .background(
                    viewModel.isLoading
                        ? Color.MW.green.opacity(0.7)
                        : Color.MW.green
                )
                .foregroundStyle(.white)
                .clipShape(RoundedRectangle(cornerRadius: 12))
            }
            .disabled(viewModel.isLoading)
            .padding(.top, 4)

            // Footer note — no signup link by design.
            Text("Access is restricted to Mowology staff.")
                .font(.caption)
                .foregroundStyle(.tertiary)
                .frame(maxWidth: .infinity, alignment: .center)
                .padding(.bottom, 4)
        }
        .padding(24)
        .background(Color(.systemBackground))
        .clipShape(RoundedRectangle(cornerRadius: 16))
        .shadow(color: .black.opacity(0.08), radius: 12, x: 0, y: 4)
    }

    // MARK: - Error Banner

    private func errorBanner(message: String) -> some View {
        HStack(alignment: .top, spacing: 10) {
            Image(systemName: "exclamationmark.triangle.fill")
                .foregroundStyle(.red)
                .font(.subheadline)
                .padding(.top, 1)

            Text(message)
                .font(.subheadline)
                .foregroundStyle(.red)
                .fixedSize(horizontal: false, vertical: true)

            Spacer()
        }
        .padding(12)
        .background(Color.red.opacity(0.08))
        .clipShape(RoundedRectangle(cornerRadius: 10))
        .overlay(
            RoundedRectangle(cornerRadius: 10)
                .stroke(Color.red.opacity(0.25), lineWidth: 1)
        )
    }
}

#Preview {
    LoginView(authSession: AuthSession())
}
