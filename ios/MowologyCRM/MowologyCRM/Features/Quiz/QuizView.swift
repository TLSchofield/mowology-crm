//
//  QuizView.swift
//  MowologyCRM
//
//  Full-screen pre-shift knowledge quiz gate.
//  Presented as a .fullScreenCover from ScheduleView until the user passes (≥70%).
//
//  Flow:
//    loading → question → feedback → (next question | finished)
//    finished(passed: true)  → onPass() called → cover dismissed
//    finished(passed: false) → retry button shown
//    error                   → retry button shown (avoids the stuck-loading trap)
//

import SwiftUI

struct QuizView: View {
    @Environment(\.itAppStyle) private var appStyle

    @StateObject private var viewModel: QuizViewModel
    let onPass: () -> Void

    init(authSession: AuthSession, onPass: @escaping () -> Void) {
        let model = QuizViewModel(authSession: authSession)
        #if DEBUG
        if ProcessInfo.processInfo.arguments.contains("-mwQuizDemo") { model.demoScript = QuizDemoQuestion.script }
        #endif
        _viewModel = StateObject(wrappedValue: model)
        self.onPass = onPass
    }

    var body: some View {
        NavigationStack {
            ZStack {
                appStyle.page.ignoresSafeArea()

                switch viewModel.phase {
                case .loading:
                    loadingView
                case .question, .feedback:
                    // One screen for asking and answering, so nothing moves when the answer lands.
                    if let q = viewModel.currentQuestion {
                        QuizQuestionScreen(question: q,
                                           state: viewModel.answerState,
                                           isBusy: viewModel.isLoading,
                                           onAnswer: { id in Task { await viewModel.submitAnswer(optionId: id) } },
                                           onNext: { Task { await viewModel.advance() } })
                            .id(q.question.id)
                    }
                case .finished(let passed):
                    finishedView(passed: passed)
                case .error:
                    errorView
                }
            }
            .toolbar(.hidden, for: .navigationBar)   // the quiz draws its own one-line header
            .alert("Error", isPresented: .init(
                get: { viewModel.errorMessage != nil },
                set: { if !$0 { viewModel.errorMessage = nil } }  // FIX: was a no-op, kept alert stuck open
            )) {
                Button("Retry") { Task { await viewModel.start() } }
                Button("Dismiss", role: .cancel) { viewModel.errorMessage = nil }
            } message: {
                Text(viewModel.errorMessage ?? "")
            }
        }
        .task { await viewModel.start() }
    }

    // MARK: - Loading

    private var loadingView: some View {
        VStack(spacing: 16) {
            ProgressView()
                .scaleEffect(1.4)
            Text("Loading your pre-shift quiz…")
                .font(appStyle.font(.subheadline))
                .foregroundStyle(appStyle.meta)
        }
    }

    // MARK: - Error (explicit retry — avoids the stuck-loading state)

    private var errorView: some View {
        VStack(spacing: 24) {
            Spacer()

            Image(systemName: "wifi.exclamationmark")
                .font(appStyle.font(size: 56))
                .foregroundStyle(appStyle.attention)

            VStack(spacing: 8) {
                Text("Could Not Load Quiz")
                    .font(appStyle.font(.title2, weight: .bold))

                Text("Check your connection and try again.")
                    .font(appStyle.font(.subheadline))
                    .foregroundStyle(appStyle.meta)
                    .multilineTextAlignment(.center)
            }

            Button {
                Task { await viewModel.start() }
            } label: {
                Label("Try Again", systemImage: "arrow.clockwise")
                    .font(appStyle.font(.headline))
                    .frame(maxWidth: .infinity)
                    .padding()
                    .background(appStyle.tenant)
                    .foregroundStyle(appStyle.onTenant)
                    .clipShape(RoundedRectangle(cornerRadius: appStyle.cornerRadius(12)))
            }
            .padding(.horizontal)
            .disabled(viewModel.isLoading)

            Spacer()
        }
        .padding()
    }

    // MARK: - Finished

    private func finishedView(passed: Bool) -> some View {
        VStack(spacing: 32) {
            Spacer()

            Image(systemName: passed ? "rosette" : "arrow.clockwise.circle.fill")
                .font(appStyle.font(size: 72))
                .foregroundStyle(passed ? appStyle.complete : appStyle.attention)

            VStack(spacing: 8) {
                Text(passed ? "Quiz Passed!" : "Not Quite")
                    .font(appStyle.font(.largeTitle, weight: .bold))

                if let r = viewModel.result {
                    Text("\(r.correct) of \(r.total) correct (\(r.passMark) needed to pass)")
                        .font(appStyle.font(.subheadline))
                        .foregroundStyle(appStyle.meta)
                }
            }

            if passed {
                Button {
                    onPass()
                } label: {
                    Label("Start My Day", systemImage: "calendar")
                        .font(appStyle.font(.headline))
                        .frame(maxWidth: .infinity)
                        .padding()
                        .background(appStyle.tenant)
                        .foregroundStyle(appStyle.onTenant)
                        .clipShape(RoundedRectangle(cornerRadius: appStyle.cornerRadius(14)))
                }
                .padding(.horizontal)
            } else {
                Button {
                    Task { await viewModel.start() }
                } label: {
                    Label("Try Again", systemImage: "arrow.clockwise")
                        .font(appStyle.font(.headline))
                        .frame(maxWidth: .infinity)
                        .padding()
                        .background(appStyle.attention)
                        .foregroundStyle(appStyle.onTenant)
                        .clipShape(RoundedRectangle(cornerRadius: appStyle.cornerRadius(14)))
                }
                .padding(.horizontal)
                Button("Continue to Schedule", action: onPass)
                    .font(appStyle.font(.subheadline))
                    .foregroundStyle(appStyle.meta)
                    .padding(.top, 4)
            }

            Spacer()
        }
        .padding()
    }
}

// MARK: - Hex Color Helper

private extension Color {
    init?(hex: String) {
        var hex = hex.trimmingCharacters(in: .whitespacesAndNewlines)
        if hex.hasPrefix("#") { hex = String(hex.dropFirst()) }
        guard hex.count == 6,
              let value = UInt64(hex, radix: 16) else { return nil }
        let r = Double((value >> 16) & 0xFF) / 255
        let g = Double((value >> 8)  & 0xFF) / 255
        let b = Double(value         & 0xFF) / 255
        self.init(red: r, green: g, blue: b)
    }
}


