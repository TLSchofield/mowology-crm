//
//  TimeClockView.swift
//  MowologyCRM
//

import SwiftUI

struct TimeClockView: View {

    @EnvironmentObject private var authSession: AuthSession
    @StateObject private var viewModel: TimeClockViewModel


    // MARK: - Init

    init(authSession: AuthSession) {
        _viewModel = StateObject(wrappedValue: TimeClockViewModel(authSession: authSession))
    }

    // MARK: - Body

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(spacing: 24) {

                    if let error = viewModel.errorMessage {
                        errorBanner(error)
                            .padding(.horizontal, 16)
                    }

                    clockCard
                        .padding(.horizontal, 16)

                    if let job = viewModel.activeJob {
                        activeJobCard(job)
                            .padding(.horizontal, 16)
                    }

                    Spacer(minLength: 32)
                }
                .padding(.top, 24)
            }
            .background(Color(.systemGroupedBackground))
            .navigationTitle("Time Clock")
            .navigationBarTitleDisplayMode(.inline)
            .task { await viewModel.loadStatus() }
            .refreshable { await viewModel.loadStatus() }
        }
    }

    // MARK: - Clock Card

    private var clockCard: some View {
        VStack(spacing: 20) {

            // Status icon
            ZStack {
                Circle()
                    .fill(viewModel.clockedIn ? Color.MW.green.opacity(0.12) : Color(.systemGray5))
                    .frame(width: 100, height: 100)

                Image(systemName: viewModel.clockedIn ? "clock.fill" : "clock")
                    .font(.system(size: 44))
                    .foregroundStyle(viewModel.clockedIn ? Color.MW.green : Color(.systemGray2))
            }

            // Status label
            Text(viewModel.clockedIn ? "Clocked In" : "Clocked Out")
                .font(.title2.bold())
                .foregroundStyle(viewModel.clockedIn ? Color.MW.green : .primary)

            // Pending sync indicator (action was accepted locally, waiting for signal)
            if viewModel.isPendingSync {
                HStack(spacing: 6) {
                    Image(systemName: "arrow.triangle.2.circlepath")
                        .font(.caption)
                    Text("Saved — will sync when signal returns")
                        .font(.caption)
                }
                .foregroundStyle(Color.MW.green)
                .padding(.horizontal, 12)
                .padding(.vertical, 6)
                .background(Color.MW.green.opacity(0.10))
                .clipShape(Capsule())
            }

            // Elapsed time (only when clocked in)
            if viewModel.clockedIn {
                Text(viewModel.elapsedFormatted)
                    .font(.system(size: 44, weight: .semibold, design: .monospaced))
                    .foregroundStyle(Color.MW.green)

                if let clockIn = viewModel.clockInTime {
                    Text("Since \(formattedClockIn(clockIn))")
                        .font(.caption)
                        .foregroundStyle(.secondary)
                }
            }

            // Clock In / Out button
            Button {
                Task {
                    if viewModel.clockedIn {
                        await viewModel.clockOut()
                    } else {
                        await viewModel.clockIn()
                    }
                }
            } label: {
                HStack(spacing: 8) {
                    if viewModel.isLoading {
                        ProgressView()
                            .tint(.white)
                            .scaleEffect(0.8)
                    } else {
                        Image(systemName: viewModel.clockedIn ? "stop.fill" : "play.fill")
                    }
                    Text(viewModel.clockedIn ? "Clock Out" : "Clock In")
                        .font(.headline)
                }
                .frame(maxWidth: .infinity)
                .padding(.vertical, 14)
                .background(viewModel.clockedIn ? Color.red : Color.MW.green)
                .foregroundStyle(.white)
                .clipShape(RoundedRectangle(cornerRadius: 12))
            }
            .disabled(viewModel.isLoading)
        }
        .padding(24)
        .background(Color(.systemBackground))
        .clipShape(RoundedRectangle(cornerRadius: 16))
        .shadow(color: .black.opacity(0.05), radius: 8, x: 0, y: 2)
    }

    // MARK: - Active Job Card

    private func activeJobCard(_ job: ActiveJobTimer) -> some View {
        VStack(alignment: .leading, spacing: 12) {
            Label("Active Job", systemImage: "briefcase.fill")
                .font(.footnote.weight(.semibold))
                .foregroundStyle(.secondary)
                .textCase(.uppercase)

            HStack {
                VStack(alignment: .leading, spacing: 4) {
                    Text(job.jobTitle ?? "Job in Progress")
                        .font(.subheadline.bold())

                    if let address = job.propertyAddress {
                        Text(address)
                            .font(.caption)
                            .foregroundStyle(.secondary)
                    }
                }

                Spacer()

                VStack(alignment: .trailing, spacing: 2) {
                    Text(formattedElapsed(job.elapsedSeconds))
                        .font(.subheadline.monospacedDigit().bold())
                        .foregroundStyle(Color.MW.green)
                    Text("elapsed")
                        .font(.caption2)
                        .foregroundStyle(.secondary)
                }
            }
        }
        .padding(16)
        .background(Color.MW.green.opacity(0.06))
        .clipShape(RoundedRectangle(cornerRadius: 12))
        .overlay(RoundedRectangle(cornerRadius: 12).stroke(Color.MW.green.opacity(0.2), lineWidth: 1))
    }

    // MARK: - Error Banner

    private func errorBanner(_ message: String) -> some View {
        HStack(spacing: 10) {
            Image(systemName: "exclamationmark.triangle.fill")
                .foregroundStyle(Color.MW.orange)
            Text(message)
                .font(.subheadline)
                .foregroundStyle(Color.MW.orange)
            Spacer()
            Button { viewModel.errorMessage = nil } label: {
                Image(systemName: "xmark")
                    .font(.caption.bold())
                    .foregroundStyle(Color.MW.orange)
            }
        }
        .padding(12)
        .background(Color.MW.orange.opacity(0.08))
        .clipShape(RoundedRectangle(cornerRadius: 10))
        .overlay(RoundedRectangle(cornerRadius: 10).stroke(Color.MW.orange.opacity(0.25), lineWidth: 1))
    }

    // MARK: - Helpers

    private func formattedClockIn(_ raw: String) -> String {
        let iso = ISO8601DateFormatter()
        iso.formatOptions = [.withFullDate, .withTime, .withDashSeparatorInDate, .withColonSeparatorInTime]
        let fallback = DateFormatter()
        fallback.dateFormat = "yyyy-MM-dd HH:mm:ss"

        let date = iso.date(from: raw) ?? fallback.date(from: raw)
        guard let date else { return raw }

        let display = DateFormatter()
        display.dateFormat = "h:mm a"
        display.locale     = Locale(identifier: "en_CA")
        return display.string(from: date)
    }

    private func formattedElapsed(_ seconds: Int) -> String {
        let h = seconds / 3600
        let m = (seconds % 3600) / 60
        let s = seconds % 60
        if h > 0 { return String(format: "%d:%02d:%02d", h, m, s) }
        return String(format: "%02d:%02d", m, s)
    }
}

#Preview {
    TimeClockView(authSession: AuthSession())
        .environmentObject(AuthSession())
}
