//
//  QuizResultView.swift
//  MowologyCRM
//

import SwiftUI

struct QuizResultView: View {

    let result: QuizFinishResult
    let onPlayAgain: () -> Void
    let onDone: () -> Void

    var body: some View {
        ScrollView {
            VStack(spacing: 28) {

                Spacer(minLength: 24)

                // Score circle
                scoreCircle

                // Stats row
                statsRow

                // Streak
                streakBadge

                // New badges
                if !result.newBadges.isEmpty {
                    newBadgesSection
                }

                // Rank context
                if result.monthlyRank > 0 {
                    rankLine
                }

                // Buttons
                actionButtons

                Spacer(minLength: 32)
            }
            .padding(.horizontal, 24)
        }
        .background(Color(.systemGroupedBackground))
        .navigationBarBackButtonHidden(true)
        .navigationTitle("Results")
        .navigationBarTitleDisplayMode(.inline)
    }

    // MARK: - Score circle

    private var scoreCircle: some View {
        ZStack {
            Circle()
                .stroke(Color(.systemGray5), lineWidth: 12)
                .frame(width: 140, height: 140)
            Circle()
                .trim(from: 0, to: result.scoreFraction)
                .stroke(
                    result.scoreFraction >= 0.7 ? Color.MW.green : .orange,
                    style: StrokeStyle(lineWidth: 12, lineCap: .round)
                )
                .frame(width: 140, height: 140)
                .rotationEffect(.degrees(-90))
                .animation(.easeOut(duration: 0.8), value: result.scoreFraction)

            VStack(spacing: 2) {
                Text("\(result.correct)/\(result.total)")
                    .font(.title.bold())
                    .foregroundStyle(Color.MW.green)
                Text("correct")
                    .font(.caption)
                    .foregroundStyle(.secondary)
            }
        }
    }

    // MARK: - Stats row

    private var statsRow: some View {
        HStack(spacing: 0) {
            resultStat(label: "Score", value: "\(result.scorePercent)%")
            Divider().frame(height: 36)
            resultStat(label: "Points", value: "+\(result.points)")
            Divider().frame(height: 36)
            resultStat(label: "Rank", value: result.monthlyRank > 0 ? "#\(result.monthlyRank)" : "—")
        }
        .padding(.vertical, 14)
        .background(Color(.systemBackground))
        .clipShape(RoundedRectangle(cornerRadius: 14))
        .shadow(color: .black.opacity(0.05), radius: 6, x: 0, y: 2)
    }

    private func resultStat(label: String, value: String) -> some View {
        VStack(spacing: 3) {
            Text(value)
                .font(.title2.bold())
                .foregroundStyle(Color.MW.green)
            Text(label)
                .font(.caption2)
                .foregroundStyle(.secondary)
        }
        .frame(maxWidth: .infinity)
    }

    // MARK: - Streak badge

    private var streakBadge: some View {
        HStack(spacing: 8) {
            Text("🔥")
                .font(.title2)
            VStack(alignment: .leading, spacing: 1) {
                Text("\(result.streak.currentStreak)-day streak")
                    .font(.subheadline.bold())
                if result.streak.newBest {
                    Text("New personal best!")
                        .font(.caption)
                        .foregroundStyle(Color.MW.green)
                } else {
                    Text("Keep it up!")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }
            }
            Spacer()
        }
        .padding(14)
        .background(Color(.systemBackground))
        .clipShape(RoundedRectangle(cornerRadius: 12))
        .shadow(color: .black.opacity(0.04), radius: 4, x: 0, y: 1)
    }

    // MARK: - New badges

    private var newBadgesSection: some View {
        VStack(alignment: .leading, spacing: 10) {
            Text("New Badges Earned!")
                .font(.subheadline.bold())
                .foregroundStyle(Color.MW.green)

            ForEach(result.newBadges, id: \.key) { badge in
                HStack(spacing: 10) {
                    Text(badge.icon)
                        .font(.title2)
                    Text(badge.name)
                        .font(.subheadline)
                }
            }
        }
        .padding(14)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(Color.MW.green.opacity(0.08))
        .clipShape(RoundedRectangle(cornerRadius: 12))
    }

    // MARK: - Rank line

    private var rankLine: some View {
        Text("You're #\(result.monthlyRank) on the monthly leaderboard")
            .font(.caption)
            .foregroundStyle(.secondary)
            .multilineTextAlignment(.center)
    }

    // MARK: - Buttons

    private var actionButtons: some View {
        VStack(spacing: 12) {
            Button {
                onPlayAgain()
            } label: {
                HStack {
                    Image(systemName: "arrow.counterclockwise")
                    Text("Play Again")
                        .font(.headline)
                }
                .frame(maxWidth: .infinity)
                .padding(.vertical, 14)
                .background(Color.MW.green)
                .foregroundStyle(.white)
                .clipShape(RoundedRectangle(cornerRadius: 12))
            }

            Button {
                onDone()
            } label: {
                Text("Done")
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
            }
        }
    }
}
