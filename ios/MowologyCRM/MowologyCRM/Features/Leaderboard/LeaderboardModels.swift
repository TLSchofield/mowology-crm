//
//  LeaderboardModels.swift
//  MowologyCRM
//
//  Decodable models for the /api/gamification/leaderboard JWT endpoint.
//

import Foundation

// MARK: - Badge

struct LeaderboardBadge: Decodable, Identifiable {
    let id = UUID()
    let name: String
    let icon: String
    let colour: String
    let tier: String
    let awardedAt: String

    enum CodingKeys: String, CodingKey {
        case name, icon, colour, tier
        case awardedAt = "awarded_at"
    }
}

// MARK: - Leaderboard entry

struct LeaderboardEntry: Decodable, Identifiable {
    let teamId: Int
    let teamName: String
    let teamColour: String
    let teamEmoji: String?
    let totalScore: Double?
    let rank: Int
    let badgeCount: Int
    let members: [String]
    let recentBadges: [LeaderboardBadge]

    // Score breakdowns (optional — may be null when week has no data yet)
    let scoreOcrAccuracy: Double?
    let scoreSpeed: Double?
    let scorePhotos: Double?
    let scoreAnomalyFree: Double?
    let scoreBudget: Double?
    let expensesSubmitted: Int?
    let expensesWithPhoto: Int?
    let expensesAnomalous: Int?

    var id: Int { teamId }

    var scoreDisplay: String {
        guard let s = totalScore else { return "—" }
        return String(format: "%.1f", s)
    }

    enum CodingKeys: String, CodingKey {
        case teamId          = "team_id"
        case teamName        = "team_name"
        case teamColour      = "team_colour"
        case teamEmoji       = "team_emoji"
        case totalScore      = "total_score"
        case rank
        case badgeCount      = "badge_count"
        case members
        case recentBadges    = "recent_badges"
        case scoreOcrAccuracy  = "score_ocr_accuracy"
        case scoreSpeed        = "score_speed"
        case scorePhotos       = "score_photos"
        case scoreAnomalyFree  = "score_anomaly_free"
        case scoreBudget       = "score_budget"
        case expensesSubmitted = "expenses_submitted"
        case expensesWithPhoto = "expenses_with_photo"
        case expensesAnomalous = "expenses_anomalous"
    }
}

// MARK: - Leaderboard response

struct LeaderboardResponse: Decodable {
    let success: Bool
    let week: String
    let leaderboard: [LeaderboardEntry]

    var weekDisplay: String {
        let formatter = DateFormatter()
        formatter.dateFormat = "yyyy-MM-dd"
        guard let date = formatter.date(from: week) else { return week }
        let out = DateFormatter()
        out.dateStyle = .medium
        return "Week of \(out.string(from: date))"
    }
}
