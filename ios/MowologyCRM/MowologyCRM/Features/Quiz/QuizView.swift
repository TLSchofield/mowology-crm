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
        _viewModel = StateObject(wrappedValue: QuizViewModel(authSession: authSession))
        self.onPass = onPass
    }

    var body: some View {
        NavigationStack {
            ZStack {
                appStyle.page.ignoresSafeArea()

                switch viewModel.phase {
                case .loading:
                    loadingView
                case .question(let num, let of):
                    questionView(num: num, of: of)
                case .feedback:
                    if let q = viewModel.currentQuestion, let state = viewModel.answerState {
                        feedbackView(question: q, state: state)
                    }
                case .finished(let passed):
                    finishedView(passed: passed)
                case .error:
                    errorView
                }
            }
            .navigationTitle("Pre-Shift Quiz")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .navigationBarTrailing) {
                    if viewModel.isLoading {
                        ProgressView()
                    }
                }
            }
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

    // MARK: - Question

    private func questionView(num: Int, of total: Int) -> some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 24) {
                progressHeader(num: num, of: total)

                if let q = viewModel.currentQuestion {
                    questionCard(q: q)
                    optionsStack(q: q)
                }
            }
            .padding()
        }
    }

    private func progressHeader(num: Int, of total: Int) -> some View {
        VStack(alignment: .leading, spacing: 6) {
            HStack {
                Text("Question \(num) of \(total)")
                    .font(appStyle.font(.subheadline, weight: .semibold))
                    .foregroundStyle(appStyle.tenant)
                Spacer()
                Text("\(Int(Double(num - 1) / Double(total) * 100))%")
                    .font(appStyle.font(.caption))
                    .foregroundStyle(appStyle.meta)
            }
            ProgressView(value: Double(num - 1), total: Double(total))
                .tint(appStyle.tenant)
        }
    }

    private func questionCard(q: QuizQuestionResponse) -> some View {
        VStack(alignment: .leading, spacing: 8) {
            if let colour = q.question.categoryColour {
                Text(q.question.categoryName)
                    .font(appStyle.font(.caption, weight: .semibold))
                    .padding(.horizontal, 10)
                    .padding(.vertical, 4)
                    .background(Color(hex: colour)?.opacity(0.15) ?? appStyle.tenant.opacity(0.15))
                    .foregroundStyle(Color(hex: colour) ?? appStyle.tenant)
                    .clipShape(Capsule())
            }

            if !q.question.images.isEmpty {
                QuizImagePager(images: q.question.images)
            }

            Text(q.question.text)
                .font(appStyle.font(.title3, weight: .semibold))
                .fixedSize(horizontal: false, vertical: true)
        }
        .padding()
        .background(appStyle.surface)
        .clipShape(RoundedRectangle(cornerRadius: appStyle.cornerRadius(12)))
    }

    private func optionsStack(q: QuizQuestionResponse) -> some View {
        VStack(spacing: 10) {
            ForEach(q.options) { option in
                Button {
                    Task { await viewModel.submitAnswer(optionId: option.id) }
                } label: {
                    HStack {
                        Text(option.optionText)
                            .font(appStyle.font(.body))
                            .multilineTextAlignment(.leading)
                            .fixedSize(horizontal: false, vertical: true)
                        Spacer()
                    }
                    .padding()
                    .background(appStyle.surface)
                    .clipShape(RoundedRectangle(cornerRadius: appStyle.cornerRadius(10)))
                    .overlay(
                        RoundedRectangle(cornerRadius: appStyle.cornerRadius(10))
                            .stroke(appStyle.tenant.opacity(0.3), lineWidth: 1)
                    )
                }
                .buttonStyle(.plain)
                .disabled(viewModel.isLoading)
            }
        }
    }

    // MARK: - Feedback

    private func feedbackView(question: QuizQuestionResponse, state: QuizAnswerState) -> some View {
        ScrollView {
            VStack(spacing: 24) {
                feedbackBanner(correct: state.isCorrect)
                questionCard(q: question)
                answeredOptionsStack(q: question, state: state)

                Button {
                    Task { await viewModel.advance() }
                } label: {
                    Label("Next Question", systemImage: "arrow.right")
                        .font(appStyle.font(.headline))
                        .frame(maxWidth: .infinity)
                        .padding()
                        .background(appStyle.tenant)
                        .foregroundStyle(appStyle.onTenant)
                        .clipShape(RoundedRectangle(cornerRadius: appStyle.cornerRadius(12)))
                }
                .disabled(viewModel.isLoading)
            }
            .padding()
        }
    }

    private func feedbackBanner(correct: Bool) -> some View {
        HStack(spacing: 10) {
            Image(systemName: correct ? "checkmark.circle.fill" : "xmark.circle.fill")
                .font(appStyle.font(.title2))
            Text(correct ? "Correct!" : "Not quite — see below")
                .font(appStyle.font(.headline))
        }
        .padding()
        .frame(maxWidth: .infinity)
        .background((correct ? appStyle.complete : appStyle.blocked).opacity(0.15))
        .foregroundStyle(correct ? appStyle.complete : appStyle.blocked)
        .clipShape(RoundedRectangle(cornerRadius: appStyle.cornerRadius(10)))
    }

    private func answeredOptionsStack(q: QuizQuestionResponse, state: QuizAnswerState) -> some View {
        VStack(spacing: 10) {
            ForEach(q.options) { option in
                let isSelected = option.id == state.optionId
                let isCorrect  = option.id == state.correctOptionId

                HStack {
                    Text(option.optionText)
                        .font(appStyle.font(.body))
                        .multilineTextAlignment(.leading)
                    Spacer()
                    if isCorrect {
                        Image(systemName: "checkmark.circle.fill")
                            .foregroundStyle(appStyle.tenant)
                    } else if isSelected {
                        Image(systemName: "xmark.circle.fill")
                            .foregroundStyle(appStyle.blocked)
                    }
                }
                .padding()
                .background(
                    isCorrect ? appStyle.complete.opacity(0.12) :
                    isSelected ? appStyle.blocked.opacity(0.10) :
                    appStyle.surface
                )
                .overlay(
                    RoundedRectangle(cornerRadius: appStyle.cornerRadius(10))
                        .stroke(
                            isCorrect ? appStyle.complete :
                            isSelected ? appStyle.blocked :
                            Color.clear,
                            lineWidth: 1.5
                        )
                )
                .clipShape(RoundedRectangle(cornerRadius: appStyle.cornerRadius(10)))
            }
        }
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


/// The question's pictures: swipe between them; a site photo says where it grows (2026-10-16).
private struct QuizImagePager: View {
    @Environment(\.itAppStyle) private var appStyle
    let images: [QuizQuestionImage]
    @State private var page = 0

    var body: some View {
        VStack(spacing: 6) {
            TabView(selection: $page) {
                ForEach(Array(images.enumerated()), id: \.offset) { i, img in
                    AsyncImage(url: img.url) { phase in
                        switch phase {
                        case .success(let image): image.resizable().scaledToFit()
                        case .failure: Image(systemName: "photo").font(appStyle.font(.largeTitle)).foregroundStyle(appStyle.meta)
                        default: ProgressView()
                        }
                    }
                    .frame(maxWidth: .infinity)
                    .tag(i)
                }
            }
            .tabViewStyle(.page(indexDisplayMode: images.count > 1 ? .automatic : .never))
            .frame(height: 240)
            .clipShape(RoundedRectangle(cornerRadius: appStyle.cornerRadius(10)))

            if let cap = images[min(page, images.count - 1)].caption, !cap.isEmpty {
                Label(cap, systemImage: "mappin.and.ellipse")
                    .font(appStyle.font(.caption))
                    .foregroundStyle(appStyle.tenant)
            }
        }
    }
}
