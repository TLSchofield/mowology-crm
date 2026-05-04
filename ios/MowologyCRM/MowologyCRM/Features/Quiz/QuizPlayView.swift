//
//  QuizPlayView.swift
//  MowologyCRM
//

import SwiftUI

struct QuizPlayView: View {

    @StateObject private var viewModel: QuizPlayViewModel
    @Environment(\.dismiss) private var dismiss

    /// Called when a session finishes — passes the session ID.
    /// Used by the pre-shift gate to mark the daily quiz complete.
    var onFinished: ((Int) -> Void)?

    init(authSession: AuthSession, categoryId: Int?, categoryName: String, onFinished: ((Int) -> Void)? = nil) {
        _viewModel      = StateObject(wrappedValue: QuizPlayViewModel(
            authSession: authSession,
            categoryId: categoryId,
            categoryName: categoryName
        ))
        self.onFinished = onFinished
    }

    var body: some View {
        Group {
            switch viewModel.state {
            case .loading:
                loadingView
            case .question(let payload):
                questionView(payload, answerResult: nil)
            case .answered(let payload, let result):
                questionView(payload, answerResult: result)
            case .finished(let result):
                QuizResultView(result: result) {
                    Task { await viewModel.restart() }
                } onDone: {
                    dismiss()
                }
                .onAppear {
                    if let sid = viewModel.sessionStart?.sessionId {
                        onFinished?(sid)
                    }
                }
            case .error(let msg):
                errorView(msg)
            }
        }
        .navigationBarTitleDisplayMode(.inline)
        .navigationTitle(viewModel.categoryName)
        .task { await viewModel.startSession() }
    }

    // MARK: - Loading

    private var loadingView: some View {
        VStack(spacing: 16) {
            ProgressView()
                .scaleEffect(1.4)
            Text("Loading question…")
                .font(.subheadline)
                .foregroundStyle(.secondary)
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity)
        .background(Color(.systemGroupedBackground))
    }

    // MARK: - Error

    private func errorView(_ msg: String) -> some View {
        VStack(spacing: 20) {
            Image(systemName: "exclamationmark.triangle.fill")
                .font(.system(size: 44))
                .foregroundStyle(.orange)
            Text(msg)
                .multilineTextAlignment(.center)
                .foregroundStyle(.secondary)
            Button("Try Again") { Task { await viewModel.startSession() } }
                .buttonStyle(.borderedProminent)
                .tint(Color.MW.green)
        }
        .padding()
        .frame(maxWidth: .infinity, maxHeight: .infinity)
        .background(Color(.systemGroupedBackground))
    }

    // MARK: - Question view

    private func questionView(_ payload: QuizQuestionPayload, answerResult: QuizAnswerResult?) -> some View {
        let isAnswered = answerResult != nil

        return ScrollView {
            VStack(spacing: 20) {

                // Progress + season
                progressHeader(payload)
                    .padding(.horizontal)

                // Question image
                if let url = payload.question.firstImageURL {
                    AsyncImage(url: url) { phase in
                        switch phase {
                        case .success(let img):
                            img.resizable()
                                .aspectRatio(contentMode: .fit)
                                .clipShape(RoundedRectangle(cornerRadius: 12))
                        default:
                            EmptyView()
                        }
                    }
                    .padding(.horizontal)
                }

                // Question text
                Text(payload.question.text)
                    .font(.title3.bold())
                    .multilineTextAlignment(.center)
                    .padding(.horizontal)

                // Mastery badge
                masteryBadge(payload.question)

                // Options
                VStack(spacing: 10) {
                    ForEach(payload.options) { option in
                        optionButton(
                            option: option,
                            answerResult: answerResult,
                            isAnswered: isAnswered
                        )
                    }
                }
                .padding(.horizontal)
                .disabled(isAnswered)

                // Mastery feedback after answer
                if let result = answerResult {
                    masteryFeedback(result)
                        .padding(.horizontal)
                }

                // Next / Finish button (shown after answer)
                if isAnswered {
                    advanceButton(payload: payload)
                        .padding(.horizontal)
                }

                Spacer(minLength: 32)
            }
            .padding(.top, 20)
        }
        .background(Color(.systemGroupedBackground))
    }

    // MARK: - Progress header

    private func progressHeader(_ payload: QuizQuestionPayload) -> some View {
        HStack {
            Text("Q \(payload.questionNum) of \(payload.total)")
                .font(.subheadline.bold())
                .foregroundStyle(Color.MW.green)

            Spacer()

            if let season = viewModel.sessionStart {
                Text(season.seasonIcon)
                    .font(.headline)
            }
        }
    }

    // MARK: - Mastery badge

    private func masteryBadge(_ question: QuizQuestion) -> some View {
        HStack(spacing: 4) {
            Circle()
                .fill(masteryColor(question.masteryLevel))
                .frame(width: 8, height: 8)
            Text(question.masteryName)
                .font(.caption)
                .foregroundStyle(.secondary)
        }
    }

    private func masteryColor(_ level: Int) -> Color {
        switch level {
        case 0:  return .gray
        case 1:  return .orange
        case 2:  return .yellow
        case 3:  return .blue
        case 4:  return Color.MW.green
        case 5:  return .purple
        default: return .gray
        }
    }

    // MARK: - Option button

    private func optionButton(option: QuizOption, answerResult: QuizAnswerResult?, isAnswered: Bool) -> some View {
        let isSelected = answerResult?.correctOptionId != nil
            ? false  // highlight is driven by correct_option_id logic below
            : false
        let isCorrect  = answerResult.map { $0.correctOptionId == option.id } ?? false
        let wasChosen  = answerResult.map { ($0.isCorrect ? $0.correctOptionId : $0.correctOptionId.map { _ in option.id }) != nil } ?? false

        var bg: Color = Color(.systemBackground)
        var fg: Color = .primary
        var border: Color = Color(.separator)

        if isAnswered {
            if isCorrect {
                bg     = Color.MW.green.opacity(0.15)
                border = Color.MW.green
                fg     = Color.MW.green
            } else if let result = answerResult, !result.isCorrect, result.correctOptionId != option.id {
                // Other wrong options fade out
                bg = Color(.systemBackground).opacity(0.5)
                fg = .secondary
            }
        }

        return Button {
            Task { await viewModel.submitAnswer(optionId: option.id, questionId: viewModel.currentQuestionPayload?.question.id ?? 0) }
        } label: {
            HStack {
                Text(option.optionText)
                    .font(.subheadline)
                    .foregroundStyle(fg)
                    .multilineTextAlignment(.leading)
                Spacer()
                if isAnswered && isCorrect {
                    Image(systemName: "checkmark.circle.fill")
                        .foregroundStyle(Color.MW.green)
                }
            }
            .padding(14)
            .background(bg)
            .clipShape(RoundedRectangle(cornerRadius: 12))
            .overlay(
                RoundedRectangle(cornerRadius: 12)
                    .stroke(border, lineWidth: 1.5)
            )
        }
        .disabled(isAnswered)
        // suppress the unused variable warning
        .onChange(of: isSelected) { _ in }
        .onChange(of: wasChosen)  { _ in }
    }

    // MARK: - Mastery feedback

    private func masteryFeedback(_ result: QuizAnswerResult) -> some View {
        HStack(spacing: 12) {
            Image(systemName: result.isCorrect ? "checkmark.circle.fill" : "xmark.circle.fill")
                .font(.title2)
                .foregroundStyle(result.isCorrect ? Color.MW.green : .red)
            VStack(alignment: .leading, spacing: 2) {
                Text(result.isCorrect ? "Correct! +\(result.pointsEarned) pts" : "Wrong answer")
                    .font(.subheadline.bold())
                    .foregroundStyle(result.isCorrect ? Color.MW.green : .red)
                Text(result.mastery.deltaText)
                    .font(.caption)
                    .foregroundStyle(.secondary)
            }
            Spacer()
        }
        .padding(14)
        .background(result.isCorrect ? Color.MW.green.opacity(0.08) : Color.red.opacity(0.08))
        .clipShape(RoundedRectangle(cornerRadius: 12))
    }

    // MARK: - Advance button

    private func advanceButton(payload: QuizQuestionPayload) -> some View {
        let isLast  = payload.questionNum >= payload.total
        let label   = isLast ? "See Results" : "Next Question"
        let icon    = isLast ? "flag.checkered" : "arrow.right"

        return Button {
            Task { await viewModel.advance() }
        } label: {
            HStack {
                Text(label).font(.headline)
                Image(systemName: icon)
            }
            .frame(maxWidth: .infinity)
            .padding(.vertical, 14)
            .background(Color.MW.green)
            .foregroundStyle(.white)
            .clipShape(RoundedRectangle(cornerRadius: 12))
        }
    }
}

// Helper accessor to get current question payload from any state
private extension QuizPlayViewModel {
    var currentQuestionPayload: QuizQuestionPayload? {
        switch state {
        case .question(let p):        return p
        case .answered(let p, _):     return p
        default:                       return nil
        }
    }
}
