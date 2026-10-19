//
//  QuizViewModel.swift
//  MowologyCRM
//
//  Manages a single quiz session: start → question loop → finish.
//  On passing (≥70% correct), writes today's ISO date to UserDefaults
//  so ScheduleViewModel can lift the gate.
//

import Foundation

private let kQuizPassedDate   = "mw.quizPassedDate"
private let kQuizAttemptedDate = "mw.quizAttemptedDate"

@MainActor
final class QuizViewModel: ObservableObject {

    // MARK: - Published

    @Published private(set) var phase: Phase = .loading
    @Published private(set) var currentQuestion: QuizQuestionResponse?
    @Published private(set) var answerState: QuizAnswerState?
    @Published private(set) var result: QuizFinishResponse?
    @Published private(set) var isLoading = false
    @Published var errorMessage: String?

    // MARK: - Types

    enum Phase {
        case loading
        case question(num: Int, of: Int)
        case feedback
        case finished(passed: Bool)
        case error   // explicit error state — shows a retry button without being stuck in .loading
    }

    // MARK: - Private

    private let apiClient: APIClient
    private var sessionId: Int?
    private var totalQuestions = 0
    private var currentNum    = 1
    private var questionStartTime: Date = .now

    // MARK: - Init

    init(authSession: AuthSession) {
        self.apiClient = APIClient(authSession: authSession)
    }

    // MARK: - Public API

    /// True if the quiz was passed today (for UI badges / score display).
    static func hasPassedToday() -> Bool {
        let stored = UserDefaults.standard.string(forKey: kQuizPassedDate) ?? ""
        return stored == todayISO()
    }

    /// True if the quiz was attempted today — used to decide whether to show the gate.
    /// Clears once per day so Nigel always gets one quiz per shift, not one per pass.
    static func hasAttemptedToday() -> Bool {
        let stored = UserDefaults.standard.string(forKey: kQuizAttemptedDate) ?? ""
        return stored == todayISO()
    }

    /// Start a new 10-question quiz session.
    func start() async {
        isLoading    = true
        errorMessage = nil
        phase        = .loading

        do {
            let response: QuizStartResponse = try await apiClient.request(
                .quizAction,
                body: ["action": "start", "session_length": 10]
            )
            sessionId      = response.sessionId
            totalQuestions = response.total
            currentNum     = 1
            await loadQuestion(num: 1)
        } catch {
            errorMessage = "Could not start quiz. Check your connection."
            isLoading    = false
            phase        = .error   // FIX: was left as .loading, trapping the user forever
        }
    }

    /// Submit the selected answer for the current question.
    func submitAnswer(optionId: Int?) async {
        guard let sid = sessionId,
              let q   = currentQuestion else { return }

        let timeTaken = Int(Date().timeIntervalSince(questionStartTime))
        isLoading     = true

        do {
            var body: [String: Any] = [
                "action":              "answer",
                "session_id":          sid,
                "question_id":         q.question.id,
                "time_taken_seconds":  min(timeTaken, 30),
            ]
            if let oid = optionId {
                body["selected_option_id"] = oid
            }

            let response: QuizAnswerResponse = try await apiClient.request(.quizAction, body: body)
            answerState = QuizAnswerState(
                optionId:        optionId,
                isCorrect:       response.isCorrect,
                correctOptionId: response.correctOptionId
            )
            phase = .feedback
        } catch {
            errorMessage = "Could not submit answer. Try again."
        }

        isLoading = false
    }

    /// Advance to the next question, or finish the session.
    func advance() async {
        answerState = nil
        let nextNum = currentNum + 1
        if nextNum > totalQuestions {
            await finish()
        } else {
            currentNum = nextNum
            await loadQuestion(num: nextNum)
        }
    }

    // MARK: - Private

    private func loadQuestion(num: Int) async {
        guard let sid = sessionId else { return }
        isLoading     = true
        errorMessage  = nil

        do {
            let response: QuizQuestionResponse = try await apiClient.request(
                .quizQuestion(sessionId: sid, q: num)
            )
            currentQuestion   = response
            questionStartTime = .now
            phase             = .question(num: num, of: totalQuestions)
        } catch {
            errorMessage = "Could not load question. Try again."
            phase        = .error
        }

        isLoading = false
    }

    private func finish() async {
        guard let sid = sessionId else { return }
        isLoading = true

        do {
            let response: QuizFinishResponse = try await apiClient.request(
                .quizAction,
                body: ["action": "finish", "session_id": sid]
            )
            result = response

            UserDefaults.standard.set(Self.todayISO(), forKey: kQuizAttemptedDate)
            if response.passed {
                UserDefaults.standard.set(Self.todayISO(), forKey: kQuizPassedDate)
            }

            phase = .finished(passed: response.passed)
        } catch {
            errorMessage = "Could not finalize quiz. Your answers are saved."
            phase        = .finished(passed: false)
        }

        isLoading = false
    }

    private static func todayISO() -> String {
        let f = ISO8601DateFormatter()
        f.formatOptions = [.withFullDate]
        return f.string(from: .now)
    }
}
