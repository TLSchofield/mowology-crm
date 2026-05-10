//
//  LeaderboardView.swift
//  MowologyCRM
//
//  Weekly crew team leaderboard. Accessible from the Account tab for all roles.
//

import SwiftUI

struct LeaderboardView: View {

    @StateObject private var viewModel: LeaderboardViewModel

    init(authSession: AuthSession) {
        _viewModel = StateObject(wrappedValue: LeaderboardViewModel(authSession: authSession))
    }

    var body: some View {
        ScrollView {
            VStack(spacing: 16) {
                if viewModel.isLoading {
                    loadingView
                } else if let err = viewModel.errorMessage {
                    errorView(err)
                } else if let resp = viewModel.response {
                    leaderboardContent(resp)
                }
            }
            .padding()
        }
        .navigationTitle("Leaderboard")
        .navigationBarTitleDisplayMode(.inline)
        .toolbar {
            ToolbarItem(placement: .navigationBarTrailing) {
                Button {
                    Task { await viewModel.load() }
                } label: {
                    Image(systemName: "arrow.clockwise")
                }
                .disabled(viewModel.isLoading)
            }
        }
        .task { await viewModel.load() }
    }

    // MARK: - Content

    private func leaderboardContent(_ resp: LeaderboardResponse) -> some View {
        VStack(alignment: .leading, spacing: 16) {
            // Week header
            Text(resp.weekDisplay)
                .font(.subheadline)
                .foregroundStyle(.secondary)
                .frame(maxWidth: .infinity, alignment: .leading)

            // Top 3 podium (if enough teams)
            if resp.leaderboard.count >= 2 {
                PodiumView(entries: Array(resp.leaderboard.prefix(3)))
            }

            // Full ranked list
            VStack(spacing: 10) {
                ForEach(resp.leaderboard) { entry in
                    LeaderboardCard(entry: entry)
                }
            }
        }
    }

    // MARK: - States

    private var loadingView: some View {
        VStack(spacing: 12) {
            ProgressView()
            Text("Loading leaderboard…")
                .font(.subheadline)
                .foregroundStyle(.secondary)
        }
        .frame(maxWidth: .infinity)
        .padding(.vertical, 60)
    }

    private func errorView(_ message: String) -> some View {
        VStack(spacing: 12) {
            Image(systemName: "exclamationmark.triangle")
                .font(.largeTitle)
                .foregroundStyle(.orange)
            Text(message)
                .font(.subheadline)
                .foregroundStyle(.secondary)
                .multilineTextAlignment(.center)
            Button("Retry") { Task { await viewModel.load() } }
                .buttonStyle(.bordered)
        }
        .frame(maxWidth: .infinity)
        .padding(.vertical, 40)
    }
}

// MARK: - Podium

private struct PodiumView: View {
    let entries: [LeaderboardEntry]

    private func entry(at rank: Int) -> LeaderboardEntry? {
        entries.first { $0.rank == rank }
    }

    var body: some View {
        HStack(alignment: .bottom, spacing: 12) {
            // 2nd place
            if let second = entry(at: 2) {
                PodiumColumn(entry: second, height: 60)
            }
            // 1st place
            if let first = entry(at: 1) {
                PodiumColumn(entry: first, height: 80)
            }
            // 3rd place
            if let third = entry(at: 3) {
                PodiumColumn(entry: third, height: 44)
            }
        }
        .frame(maxWidth: .infinity)
        .padding()
        .background(Color(.secondarySystemGroupedBackground))
        .clipShape(RoundedRectangle(cornerRadius: 12))
    }
}

private struct PodiumColumn: View {
    let entry: LeaderboardEntry
    let height: CGFloat

    private var medal: String {
        switch entry.rank {
        case 1: return "🥇"
        case 2: return "🥈"
        default: return "🥉"
        }
    }

    var body: some View {
        VStack(spacing: 4) {
            Text(entry.teamEmoji ?? medal)
                .font(.title2)
            Text(entry.teamName)
                .font(.caption.weight(.semibold))
                .lineLimit(1)
            Text(entry.scoreDisplay)
                .font(.caption)
                .foregroundStyle(.secondary)
            RoundedRectangle(cornerRadius: 6)
                .fill(Color(hex: entry.teamColour) ?? Color.MW.green)
                .frame(height: height)
            Text(medal)
                .font(.caption2)
        }
        .frame(maxWidth: .infinity)
    }
}

// MARK: - Leaderboard Card

private struct LeaderboardCard: View {
    let entry: LeaderboardEntry

    var body: some View {
        HStack(spacing: 14) {
            // Rank badge
            ZStack {
                Circle()
                    .fill(rankColor)
                    .frame(width: 36, height: 36)
                Text("\(entry.rank)")
                    .font(.subheadline.weight(.bold))
                    .foregroundStyle(entry.rank <= 3 ? .white : .primary)
            }

            // Team info
            VStack(alignment: .leading, spacing: 3) {
                HStack {
                    if let emoji = entry.teamEmoji {
                        Text(emoji).font(.body)
                    }
                    Text(entry.teamName)
                        .font(.subheadline.weight(.semibold))
                }
                Text(entry.members.joined(separator: ", "))
                    .font(.caption)
                    .foregroundStyle(.secondary)
                    .lineLimit(1)

                // Score bar
                GeometryReader { geo in
                    let fraction = (entry.totalScore ?? 0) / 100.0
                    ZStack(alignment: .leading) {
                        RoundedRectangle(cornerRadius: 3)
                            .fill(Color(.systemFill))
                            .frame(height: 5)
                        RoundedRectangle(cornerRadius: 3)
                            .fill(Color(hex: entry.teamColour) ?? Color.MW.green)
                            .frame(width: geo.size.width * CGFloat(max(0, min(1, fraction))), height: 5)
                    }
                }
                .frame(height: 5)
                .padding(.top, 2)
            }

            Spacer()

            // Score + badges
            VStack(alignment: .trailing, spacing: 2) {
                Text(entry.scoreDisplay)
                    .font(.title3.weight(.bold))
                    .foregroundStyle(Color(hex: entry.teamColour) ?? Color.MW.green)
                if entry.badgeCount > 0 {
                    Label("\(entry.badgeCount)", systemImage: "medal.fill")
                        .font(.caption2)
                        .foregroundStyle(.orange)
                }
            }
        }
        .padding(12)
        .background(Color(.secondarySystemGroupedBackground))
        .clipShape(RoundedRectangle(cornerRadius: 12))
    }

    private var rankColor: Color {
        switch entry.rank {
        case 1: return .yellow
        case 2: return Color(white: 0.7)
        case 3: return Color(red: 0.8, green: 0.5, blue: 0.2)
        default: return Color(.systemFill)
        }
    }
}

// MARK: - Hex Color helper (local — avoids conflicts)

private extension Color {
    init?(hex: String) {
        var clean = hex.trimmingCharacters(in: .whitespacesAndNewlines)
        if clean.hasPrefix("#") { clean = String(clean.dropFirst()) }
        guard clean.count == 6, let value = UInt64(clean, radix: 16) else { return nil }
        let r = Double((value >> 16) & 0xFF) / 255
        let g = Double((value >>  8) & 0xFF) / 255
        let b = Double( value        & 0xFF) / 255
        self.init(red: r, green: g, blue: b)
    }
}

