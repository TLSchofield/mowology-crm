//
//  QuizModels.swift
//  MowologyCRM
//

import Foundation

// MARK: - Session Start

struct QuizStartResponse: Decodable {
    let success: Bool
    let sessionId: Int
    let total: Int

    enum CodingKeys: String, CodingKey {
        case success
        case sessionId = "session_id"
        case total
    }
}

// MARK: - Question

struct QuizOption: Decodable, Identifiable {
    let id: Int
    let optionText: String

    enum CodingKeys: String, CodingKey {
        case id
        case optionText = "option_text"
    }
}

struct QuizQuestionDetail: Decodable {
    let id: Int
    let text: String
    let difficulty: String?
    let categoryName: String
    let categoryColour: String?
    let masteryLevel: Int
    let masteryName: String

    enum CodingKeys: String, CodingKey {
        case id
        case text
        case difficulty
        case categoryName   = "category_name"
        case categoryColour = "category_colour"
        case masteryLevel   = "mastery_level"
        case masteryName    = "mastery_name"
    }
}

struct QuizQuestionResponse: Decodable {
    let success: Bool
    let questionNum: Int
    let total: Int
    let question: QuizQuestionDetail
    let options: [QuizOption]

    enum CodingKeys: String, CodingKey {
        case success
        case questionNum = "question_num"
        case total
        case question
        case options
    }
}

// MARK: - Answer

struct QuizAnswerResponse: Decodable {
    let success: Bool
    let isCorrect: Bool
    let correctOptionId: Int?
    let pointsEarned: Int

    enum CodingKeys: String, CodingKey {
        case success
        case isCorrect      = "is_correct"
        case correctOptionId = "correct_option_id"
        case pointsEarned   = "points_earned"
    }
}

// MARK: - Finish

struct QuizFinishResponse: Decodable {
    let success: Bool
    let correct: Int
    let total: Int
    let passed: Bool
    let passMark: Int

    enum CodingKeys: String, CodingKey {
        case success
        case correct
        case total
        case passed
        case passMark = "pass_mark"
    }
}

// MARK: - Local State

struct QuizAnswerState {
    let optionId: Int?
    let isCorrect: Bool
    let correctOptionId: Int?
}
