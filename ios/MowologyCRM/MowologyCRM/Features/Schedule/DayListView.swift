//
//  DayListView.swift
//  MowologyCRM
//
//  Created by Mowology on 2026-03-03.
//

import SwiftUI

struct DayListView: View {

    let stops: [Stop]
    let isLoading: Bool
    let errorMessage: String?
    let isOffline: Bool
    let isAdmin: Bool
    let onRefresh: () async -> Void
    var scrollTargetId: Int? = nil

    var body: some View {
        Group {
            if isLoading && stops.isEmpty {
                loadingSkeleton
            } else if let message = errorMessage, stops.isEmpty {
                errorBanner(message: message)
            } else if stops.isEmpty {
                emptyState
            } else {
                stopList
            }
        }
    }

    // MARK: - Stop List

    private var stopList: some View {
        ScrollViewReader { proxy in
            List {
                // Offline banner — shown above the cached stop list.
                if isOffline {
                    Section {
                        offlineBanner
                            .listRowBackground(Color.clear)
                            .listRowInsets(EdgeInsets(top: 0, leading: 0, bottom: 0, trailing: 0))
                    }
                }

                // Inline error banner above the list when we have stale data + an error.
                if let message = errorMessage {
                    Section {
                        errorBanner(message: message)
                            .listRowBackground(Color.clear)
                            .listRowInsets(EdgeInsets(top: 0, leading: 0, bottom: 0, trailing: 0))
                    }
                }

                ForEach(stops) { stop in
                    NavigationLink(value: stop) {
                        StopCardView(stop: stop, isAdmin: isAdmin)
                            .padding(.vertical, 4)
                    }
                    .listRowBackground(Color.clear)
                    .listRowSeparator(.hidden)
                    .listRowInsets(EdgeInsets(top: 4, leading: 16, bottom: 4, trailing: 16))
                }
            }
            .listStyle(.plain)
            .scrollContentBackground(.hidden)
            .background(Color(.systemGroupedBackground))
            .refreshable { await onRefresh() }
            .onChange(of: scrollTargetId) { _, newId in
                guard let id = newId else { return }
                Task { @MainActor in
                    try? await Task.sleep(for: .milliseconds(350))
                    withAnimation(.easeInOut(duration: 0.45)) {
                        proxy.scrollTo(id, anchor: .top)
                    }
                }
            }
        }
    }

    // MARK: - Loading Skeleton

    private var loadingSkeleton: some View {
        VStack(spacing: 12) {
            ForEach(0..<4, id: \.self) { _ in
                SkeletonCard()
                    .padding(.horizontal, 16)
            }
            Spacer()
        }
        .padding(.top, 8)
        .background(Color(.systemGroupedBackground))
    }

    // MARK: - Empty State

    private var emptyState: some View {
        VStack(spacing: 16) {
            Spacer()

            Image(systemName: "calendar.badge.clock")
                .font(.system(size: 52))
                .foregroundStyle(Color(.systemGray3))

            Text("No Stops Scheduled")
                .font(.title3.bold())
                .foregroundStyle(.primary)

            Text("There are no stops on this day.")
                .font(.subheadline)
                .foregroundStyle(.secondary)
                .multilineTextAlignment(.center)

            Spacer()
        }
        .frame(maxWidth: .infinity)
        .padding(32)
        .background(Color(.systemGroupedBackground))
        // Allow pull-to-refresh on empty state.
        .refreshable { await onRefresh() }
    }

    // MARK: - Offline Banner

    private var offlineBanner: some View {
        HStack(spacing: 10) {
            Image(systemName: "wifi.slash")
                .foregroundStyle(.secondary)
                .font(.subheadline)

            Text("No signal — showing last synced schedule")
                .font(.caption)
                .foregroundStyle(.secondary)

            Spacer()
        }
        .padding(10)
        .background(Color(.systemGray6))
        .clipShape(RoundedRectangle(cornerRadius: 8))
        .padding(.horizontal, 16)
        .padding(.vertical, 4)
    }

    // MARK: - Error Banner

    private func errorBanner(message: String) -> some View {
        HStack(alignment: .top, spacing: 10) {
            Image(systemName: "wifi.exclamationmark")
                .foregroundStyle(.orange)
                .font(.subheadline)

            Text(message)
                .font(.subheadline)
                .foregroundStyle(.orange)
                .fixedSize(horizontal: false, vertical: true)

            Spacer()
        }
        .padding(12)
        .background(Color.orange.opacity(0.08))
        .clipShape(RoundedRectangle(cornerRadius: 10))
        .overlay(
            RoundedRectangle(cornerRadius: 10)
                .stroke(Color.orange.opacity(0.25), lineWidth: 1)
        )
        .padding(.horizontal, 16)
        .padding(.vertical, 8)
    }
}

// MARK: - Skeleton Card

private struct SkeletonCard: View {

    @State private var isAnimating = false

    var body: some View {
        VStack(alignment: .leading, spacing: 12) {
            HStack {
                VStack(alignment: .leading, spacing: 6) {
                    skeletonBar(width: 180, height: 16)
                    skeletonBar(width: 100, height: 12)
                }
                Spacer()
                skeletonBar(width: 50, height: 30)
            }

            HStack(spacing: 6) {
                skeletonBar(width: 80, height: 22)
                skeletonBar(width: 70, height: 22)
            }

            Divider()

            HStack {
                skeletonBar(width: 120, height: 12)
                Spacer()
                skeletonBar(width: 60, height: 12)
            }
        }
        .padding(16)
        .background(Color(.systemBackground))
        .clipShape(RoundedRectangle(cornerRadius: 14))
        .shadow(color: .black.opacity(0.04), radius: 6, x: 0, y: 2)
        .onAppear { isAnimating = true }
    }

    private func skeletonBar(width: CGFloat, height: CGFloat) -> some View {
        RoundedRectangle(cornerRadius: height / 2)
            .fill(Color(.systemGray5))
            .frame(width: width, height: height)
            .opacity(isAnimating ? 0.5 : 1.0)
            .animation(
                .easeInOut(duration: 0.9).repeatForever(autoreverses: true),
                value: isAnimating
            )
    }
}
