//
//  QuizModels.swift
//  MowologyCRM
//

import Foundation

// MARK: - Category

struct QuizCategory: Codable, Identifiable {
    let id: Int
    let name: String
    let description: String?
    let icon: String?
    let colour: String?
    let sortOrder: Int
    let questionCount: Int
    let masteredCount: Int
    let learningCount: Int
    let unseenCount: Int
    let dueCount: Int

    enum CodingKeys: String, CodingKey {
        case id, name, description, icon, colour
        case sortOrder    = "sort_order"
        case questionCount = "question_count"
        case masteredCount = "mastered_count"
        case learningCount = "learning_count"
        case unseenCount   = "unseen_count"
        case dueCount      = "due_count"
    }

    var masteryFraction: Double {
        guard questionCount > 0 else { return 0 }
        return Double(masteredCount) / Double(questionCount)
    }
}

// MARK: - Categories response

struct QuizCategoriesResponse: Decodable {
    let success: Bool
    let categories: [QuizCategory]
    let season: QuizSeasonInfo
}

struct QuizSeasonInfo: Decodable {
    let seasonLabel: String
    let seasonIcon: String
    let seasonTagline: String

    enum CodingKeys: String, CodingKey {
        case seasonLabel   = "season_label"
        case seasonIcon    = "season_icon"
        case seasonTagline = "season_tagline"
    }
}

// MARK: - Session start

struct QuizSessionStart: Decodable {
    let success: Bool
    let sessionId: Int
    let total: Int
    let seasonLabel: String
    let seasonIcon: String
    let seasonTagline: String

    enum CodingKeys: String, CodingKey {
        case success
        case sessionId     = "session_id"
        case total
        case seasonLabel   = "season_label"
        case seasonIcon    = "season_icon"
        case seasonTagline = "season_tagline"
    }
}

// MARK: - Question

struct QuizImageItem: Decodable {
    let imagePath: String
    let sortOrder: Int
    let caption: String

    enum CodingKeys: String, CodingKey {
        case imagePath  = "image_path"
        case sortOrder  = "sort_order"
        case caption
    }
}

struct QuizOption: Decodable, Identifiable {
    let id: Int
    let optionText: String

    enum CodingKeys: String, CodingKey {
        case id
        case optionText = "option_text"
    }
}

struct QuizQuestion: Decodable {
    let id: Int
    let text: String
    let images: [QuizImageItem]
    let difficulty: String?
    let categoryName: String
    let categoryColour: String?
    let masteryLevel: Int
    let masteryName: String

    enum CodingKeys: String, CodingKey {
        case id, text, images, difficulty
        case categoryName   = "category_name"
        case categoryColour = "category_colour"
        case masteryLevel   = "mastery_level"
        case masteryName    = "mastery_name"
    }

    var firstImageURL: URL? {
        guard let path = images.first?.imagePath, !path.isEmpty else { return nil }
        return URL(string: "https://mowology.ca\(path)")
    }
}

struct QuizQuestionPayload: Decodable {
    let success: Bool
    let questionNum: Int
    let total: Int
    let alreadyAnswered: Bool
    let question: QuizQuestion
    let options: [QuizOption]

    enum CodingKeys: String, CodingKey {
        case success
        case questionNum     = "question_num"
        case total
        case alreadyAnswered = "already_answered"
        case question, options
    }
}

// MARK: - Answer result

struct QuizMasteryUpdate: Decodable {
    let oldLevel: Int
    let newLevel: Int
    let delta: Int
    let levelName: String
    let streak: Int

    enum CodingKeys: String, CodingKey {
        case oldLevel  = "old_level"
        case newLevel  = "new_level"
        case delta
        case levelName = "level_name"
        case streak
    }

    var deltaText: String {
        guard delta != 0 else { return levelName }
        let arrow = delta > 0 ? "↑" : "↓"
        return "\(delta > 0 ? "+" : "")\(delta) \(arrow) \(levelName)"
    }
}

struct QuizAnswerResult: Decodable {
    let success: Bool
    let isCorrect: Bool
    let correctOptionId: Int?
    let pointsEarned: Int
    let mastery: QuizMasteryUpdate

    enum CodingKeys: String, CodingKey {
        case success
        case isCorrect      = "is_correct"
        case correctOptionId = "correct_option_id"
        case pointsEarned   = "points_earned"
        case mastery
    }
}

// MARK: - Finish result

struct QuizStreakResult: Decodable {
    let currentStreak: Int
    let longestStreak: Int
    let newBest: Bool

    enum CodingKeys: String, CodingKey {
        case currentStreak = "current_streak"
        case longestStreak = "longest_streak"
        case newBest       = "new_best"
    }
}

struct QuizBadge: Decodable {
    let key: String
    let name: String
    let icon: String
}

struct QuizFinishResult: Decodable {
    let success: Bool
    let correct: Int
    let total: Int
    let points: Int
    let monthlyRank: Int
    let monthlyTotal: Int
    let streak: QuizStreakResult
    let newBadges: [QuizBadge]

    enum CodingKeys: String, CodingKey {
        case success, correct, total, points, streak
        case monthlyRank  = "monthly_rank"
        case monthlyTotal = "monthly_total"
        case newBadges    = "new_badges"
    }

    var scoreFraction: Double {
        guard total > 0 else { return 0 }
        return Double(correct) / Double(total)
    }

    var scorePercent: Int { Int(scoreFraction * 100) }
}

// MARK: - Stats

struct QuizRankTier: Decodable {
    let tier: Int
    let name: String
    let icon: String
    let nextAt: Int?
    let nextName: String?

    enum CodingKeys: String, CodingKey {
        case tier, name, icon
        case nextAt   = "next_at"
        case nextName = "next_name"
    }
}

struct QuizCategoryAccuracy: Decodable, Identifiable {
    let name: String
    let colour: String?
    let total: Int
    let correct: Int
    let pct: Int

    var id: String { name }
}

struct QuizStats: Decodable {
    let success: Bool
    let gamesPlayed: Int
    let monthlyPoints: Int
    let totalCorrect: Int
    let totalQuestions: Int
    let bestGame: Int
    let totalMastered: Int
    let currentStreak: Int
    let longestStreak: Int
    let badgeCount: Int
    let totalBadges: Int
    let rankTier: QuizRankTier
    let categoryAccuracy: [QuizCategoryAccuracy]

    enum CodingKeys: String, CodingKey {
        case success
        case gamesPlayed      = "games_played"
        case monthlyPoints    = "monthly_points"
        case totalCorrect     = "total_correct"
        case totalQuestions   = "total_questions"
        case bestGame         = "best_game"
        case totalMastered    = "total_mastered"
        case currentStreak    = "current_streak"
        case longestStreak    = "longest_streak"
        case badgeCount       = "badge_count"
        case totalBadges      = "total_badges"
        case rankTier         = "rank_tier"
        case categoryAccuracy = "category_accuracy"
    }

    var accuracyPercent: Int {
        guard totalQuestions > 0 else { return 0 }
        return Int(Double(totalCorrect) / Double(totalQuestions) * 100)
    }
}

// MARK: - Pre-shift gate

struct QuizPreshiftStatus: Decodable {
    let success: Bool
    let required: Bool
    let completedToday: Bool
    let sessionLength: Int

    enum CodingKeys: String, CodingKey {
        case success, required
        case completedToday = "completed_today"
        case sessionLength  = "session_length"
    }
}

struct QuizPreshiftComplete: Decodable {
    let success: Bool
    let message: String
}
