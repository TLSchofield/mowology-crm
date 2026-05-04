//
//  QuizPlayViewModel.swift
//  MowologyCRM
//
//  PRODUCTION SAFETY — READ BEFORE EDITING
//  ────────────────────────────────────────
//  State machine: .loading → .question → .answered → .finished (or .error).
//  Transitions are one-way within a session — you cannot go from .answered
//  back to .question for the same question; advance() moves to the next question.
//
//  questionLoadedAt is reset at the top of loadQuestion() — it tracks when
//  the question was displayed, not when the answer was submitted. time_taken
//  is clamped to [0, 30] both here and server-side. Do not move the reset
//  to startSession() or time_taken will be inflated by session start latency.
//
//  advance() checks `next <= total` (1-based, inclusive) before calling
//  finishSession(). If you change to `next < total` (< instead of <=), the
//  last question never triggers finish. If you change to `next > total`, the
//  second-to-last question triggers finish one question early.
//
//  sessionLength is passed to the server via the start body. The server
//  validates [3, 5, 10] and defaults to 10 if the value is outside the
//  allowlist. The default of 10 in the initializer is the fallback for hub
//  play — the pre-shift gate always supplies the admin-configured length.
//
//  restart() resets sessionStart and currentQuestionNum to nil/1 before
//  calling startSession(). This ensures the view does not show a stale
//  session ID from the previous run while the new session is loading.

import Foundation

enum QuizPlayState {
    case loading
    case question(QuizQuestionPayload)
    case answered(QuizQuestionPayload, QuizAnswerResult)
    case finished(QuizFinishResult)
    case error(String)
}

@MainActor
final class QuizPlayViewModel: ObservableObject {

    @Published var state: QuizPlayState = .loading
    @Published var sessionStart: QuizSessionStart?
    @Published var currentQuestionNum = 1

    // Track when user tapped an option (for time_taken calculation)
    private var questionLoadedAt: Date = .now

    private let api: APIClient
    let categoryId: Int?
    let categoryName: String
    let sessionLength: Int

    init(authSession: AuthSession, categoryId: Int?, categoryName: String, sessionLength: Int = 10) {
        self.api           = APIClient(authSession: authSession)
        self.categoryId    = categoryId
        self.categoryName  = categoryName
        self.sessionLength = sessionLength
    }

    // MARK: - Start session

    func startSession() async {
        state = .loading
        do {
            var body: [String: Any] = ["session_length": sessionLength, "mode": "seasonal"]
            if let cid = categoryId { body["category_id"] = cid }

            let start: QuizSessionStart = try await api.request(.quizStart, body: body)
            sessionStart        = start
            currentQuestionNum  = 1
            await loadQuestion(num: 1, sessionId: start.sessionId)
        } catch {
            state = .error((error as? APIError)?.localizedDescription ?? error.localizedDescription)
        }
    }

    // MARK: - Load question

    func loadQuestion(num: Int, sessionId: Int) async {
        state              = .loading
        questionLoadedAt   = .now
        currentQuestionNum = num
        do {
            let payload: QuizQuestionPayload = try await api.request(
                .quizQuestion(sessionId: sessionId, q: num)
            )
            state = .question(payload)
        } catch {
            state = .error((error as? APIError)?.localizedDescription ?? error.localizedDescription)
        }
    }

    // MARK: - Submit answer

    func submitAnswer(optionId: Int?, questionId: Int) async {
        guard let session = sessionStart else { return }
        guard case .question(let payload) = state else { return }

        let timeTaken = max(0, min(30, Int(Date.now.timeIntervalSince(questionLoadedAt))))

        var body: [String: Any] = [
            "session_id":        session.sessionId,
            "question_id":       questionId,
            "time_taken_seconds": timeTaken,
        ]
        if let optionId { body["selected_option_id"] = optionId }

        do {
            let result: QuizAnswerResult = try await api.request(.quizAnswer, body: body)
            state = .answered(payload, result)
        } catch {
            state = .error((error as? APIError)?.localizedDescription ?? error.localizedDescription)
        }
    }

    // MARK: - Advance or finish

    func advance() async {
        guard let session = sessionStart else { return }
        let total = session.total
        let next  = currentQuestionNum + 1

        if next <= total {
            await loadQuestion(num: next, sessionId: session.sessionId)
        } else {
            await finishSession()
        }
    }

    func finishSession() async {
        guard let session = sessionStart else { return }
        do {
            let result: QuizFinishResult = try await api.request(
                .quizFinish,
                body: ["session_id": session.sessionId]
            )
            state = .finished(result)
        } catch {
            state = .error((error as? APIError)?.localizedDescription ?? error.localizedDescription)
        }
    }

    // MARK: - Restart

    func restart() async {
        sessionStart       = nil
        currentQuestionNum = 1
        await startSession()
    }
}
