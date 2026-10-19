//
//  DayListView.swift
//  MowologyCRM
//
//  Created by Mowology on 2026-03-03.
//

import SwiftUI
import CoreLocation

struct DayListView: View {
    @Environment(\.itAppStyle) private var appStyle

    let stops: [Stop]
    /// Quote visits for the day (their own cards, above the stops).
    var appointments: [Appointment] = []
    let isLoading: Bool
    let errorMessage: String?
    let isOffline: Bool
    let isAdmin: Bool
    /// Device position the list is sorted from; nil until a fix arrives.
    var userLocation: CLLocation? = nil
    let onRefresh: () async -> Void
    /// Admin only: asks the parent to open the Move Stop sheet for this stop.
    var onMove: ((Stop) -> Void)? = nil

    /// Tracks whether the one-time initial scroll has already fired.
    @State private var hasAutoScrolled = false

    /// The stop ID to scroll to on first load.
    /// Priority: in-progress → first non-complete → nil (stay at top).
    private var autoScrollTargetId: Int? {
        if let active = stops.first(where: { $0.isInProgress }) { return active.id }
        if let next   = stops.first(where: { !$0.isResolved })  { return next.id   }
        return nil
    }

    var body: some View {
        Group {
            if isLoading && stops.isEmpty {
                loadingSkeleton
            } else if let message = errorMessage, stops.isEmpty {
                errorBanner(message: message)
            } else if stops.isEmpty && appointments.isEmpty {
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

                ForEach(appointments) { appt in
                    AppointmentCardView(appointment: appt)
                        .listRowBackground(Color.clear)
                        .listRowSeparator(.hidden)
                        .listRowInsets(EdgeInsets(top: 4, leading: 16, bottom: 4, trailing: 16))
                }

                ForEach(stops) { stop in
                    NavigationLink(value: stop) {
                        StopCardView(stop: stop,
                                     isAdmin: isAdmin,
                                     distanceMeters: distance(to: stop))
                            .padding(.vertical, appStyle.isEnabled ? 0 : 4)
                    }
                    .listRowBackground(appStyle.isEnabled ? appStyle.surface : Color.clear)
                    .listRowSeparator(appStyle.isEnabled ? .visible : .hidden)
                    .listRowSeparatorTint(appStyle.line)
                    .listRowInsets(EdgeInsets(top: appStyle.isEnabled ? 0 : 4, leading: 16, bottom: appStyle.isEnabled ? 0 : 4, trailing: 16))
                    .id(stop.id)
                    .swipeActions(edge: .trailing, allowsFullSwipe: true) {
                        if isAdmin, stop.canBeMoved, let onMove {
                            Button {
                                onMove(stop)
                            } label: {
                                Label("Move", systemImage: "calendar.badge.clock")
                            }
                            .tint(appStyle.tenant)
                        }
                    }
                    .contextMenu {
                        if isAdmin, stop.canBeMoved, let onMove {
                            Button {
                                onMove(stop)
                            } label: {
                                Label("Move to another date", systemImage: "calendar.badge.clock")
                            }
                        }
                    }
                }
            }
            .listStyle(.plain)
            .scrollContentBackground(.hidden)
            .background(appStyle.page)
            .refreshable { await onRefresh() }
            .onChange(of: stops) { _, newStops in
                guard !hasAutoScrolled, !newStops.isEmpty else { return }
                hasAutoScrolled = true
                guard let targetId = autoScrollTargetId else { return }
                // Brief delay lets the List finish its layout pass before scrolling.
                DispatchQueue.main.asyncAfter(deadline: .now() + 0.15) {
                    withAnimation(.easeInOut(duration: 0.35)) {
                        proxy.scrollTo(targetId, anchor: .top)
                    }
                }
            }
        }
    }

    private func distance(to stop: Stop) -> CLLocationDistance? {
        guard let userLocation, let lat = stop.latitude, let lng = stop.longitude else { return nil }
        return userLocation.distance(from: CLLocation(latitude: lat, longitude: lng))
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
        .background(appStyle.page)
    }

    // MARK: - Empty State

    private var emptyState: some View {
        VStack(spacing: 16) {
            Spacer()

            Image(systemName: "calendar.badge.clock")
                .font(appStyle.font(size: 52))
                .foregroundStyle(appStyle.color(.meta, legacy: Color(.systemGray3)))

            Text("No Stops Scheduled")
                .font(appStyle.font(.title3, weight: .bold))
                .foregroundStyle(appStyle.text)

            Text("There are no stops on this day.")
                .font(appStyle.font(.subheadline))
                .foregroundStyle(appStyle.meta)
                .multilineTextAlignment(.center)

            Spacer()
        }
        .frame(maxWidth: .infinity)
        .padding(32)
        .background(appStyle.page)
        // Allow pull-to-refresh on empty state.
        .refreshable { await onRefresh() }
    }

    // MARK: - Offline Banner

    private var offlineBanner: some View {
        HStack(spacing: 10) {
            Image(systemName: "wifi.slash")
                .foregroundStyle(appStyle.meta)
                .font(appStyle.font(.subheadline))

            Text("No signal — showing last synced schedule")
                .font(appStyle.font(.caption))
                .foregroundStyle(appStyle.meta)

            Spacer()
        }
        .padding(10)
        .background(appStyle.sunk)
        .clipShape(RoundedRectangle(cornerRadius: appStyle.cornerRadius(8)))
        .padding(.horizontal, 16)
        .padding(.vertical, 4)
    }

    // MARK: - Error Banner

    private func errorBanner(message: String) -> some View {
        HStack(alignment: .top, spacing: 10) {
            Image(systemName: "wifi.exclamationmark")
                .foregroundStyle(appStyle.color(.statusAttention, legacy: .orange))
                .font(appStyle.font(.subheadline))

            Text(message)
                .font(appStyle.font(.subheadline))
                .foregroundStyle(appStyle.color(.statusAttention, legacy: .orange))
                .fixedSize(horizontal: false, vertical: true)

            Spacer()
        }
        .padding(12)
        .background(appStyle.color(.statusAttention, legacy: Color.orange).opacity(0.08))
        .clipShape(RoundedRectangle(cornerRadius: appStyle.cornerRadius(10)))
        .overlay(
            RoundedRectangle(cornerRadius: appStyle.cornerRadius(10))
                .stroke(appStyle.color(.statusAttention, legacy: Color.orange).opacity(0.25), lineWidth: 1)
        )
        .padding(.horizontal, 16)
        .padding(.vertical, 8)
    }
}

// MARK: - Skeleton Card

private struct SkeletonCard: View {
    @Environment(\.itAppStyle) private var appStyle

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
        .background(appStyle.surface)
        .clipShape(RoundedRectangle(cornerRadius: appStyle.cornerRadius(14)))
        .shadow(color: appStyle.shadow(0.04), radius: 6, x: 0, y: 2)
        .onAppear { isAnimating = true }
    }

    private func skeletonBar(width: CGFloat, height: CGFloat) -> some View {
        RoundedRectangle(cornerRadius: height / 2)
            .fill(appStyle.color(.line, legacy: Color(.systemGray5)))
            .frame(width: width, height: height)
            .opacity(isAnimating ? 0.5 : 1.0)
            .animation(
                .easeInOut(duration: 0.9).repeatForever(autoreverses: true),
                value: isAnimating
            )
    }
}
