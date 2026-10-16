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

/// One picture on a question; our own site photos carry where they grow ("Seen at … · Front bed", 2026-10-16).
struct QuizQuestionImage: Decodable, Hashable {
    let imagePath: String
    let caption: String?

    enum CodingKeys: String, CodingKey {
        case imagePath = "image_path"
        case caption
    }

    var url: URL? { URL(string: imagePath.hasPrefix("http") ? imagePath : "https://mowology.ca" + imagePath) }
}

struct QuizQuestionDetail: Decodable {
    let id: Int
    let text: String
    let difficulty: String?
    let categoryName: String
    let categoryColour: String?
    let masteryLevel: Int
    let masteryName: String
    /// Missing from an older server → no pictures (decoded leniently).
    let images: [QuizQuestionImage]

    enum CodingKeys: String, CodingKey {
        case id
        case text
        case difficulty
        case categoryName   = "category_name"
        case categoryColour = "category_colour"
        case masteryLevel   = "mastery_level"
        case masteryName    = "mastery_name"
        case images
        case imagePath      = "image_path"
    }

    init(from decoder: Decoder) throws {
        let c = try decoder.container(keyedBy: CodingKeys.self)
        id             = try c.decode(Int.self, forKey: .id)
        text           = try c.decode(String.self, forKey: .text)
        difficulty     = try c.decodeIfPresent(String.self, forKey: .difficulty)
        categoryName   = try c.decode(String.self, forKey: .categoryName)
        categoryColour = try c.decodeIfPresent(String.self, forKey: .categoryColour)
        masteryLevel   = try c.decode(Int.self, forKey: .masteryLevel)
        masteryName    = try c.decode(String.self, forKey: .masteryName)
        let list       = (try? c.decodeIfPresent([QuizQuestionImage].self, forKey: .images)) ?? []
        // An older server sends only image_path: still show that picture (Codex review 2026-10-16).
        if list.isEmpty, let one = try? c.decodeIfPresent(String.self, forKey: .imagePath), !one.isEmpty {
            images = [QuizQuestionImage(imagePath: one, caption: nil)]
        } else {
            images = list
        }
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
