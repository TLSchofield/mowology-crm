//
//  CrewMapView.swift
//  MowologyCRM
//
//  Admin-only live crew location map.
//  Refreshes every 30 s automatically.
//

import SwiftUI
import MapKit

struct CrewMapView: View {

    @StateObject private var viewModel: CrewMapViewModel
    @State private var showSheet = false

    init(authSession: AuthSession) {
        _viewModel = StateObject(wrappedValue: CrewMapViewModel(authSession: authSession))
    }

    var body: some View {
        ZStack(alignment: .bottom) {
            // Map
            Map(coordinateRegion: $viewModel.region, annotationItems: viewModel.crew) { member in
                MapAnnotation(coordinate: member.coordinate) {
                    CrewPin(member: member) {
                        viewModel.selectedMember = member
                        showSheet = true
                    }
                }
            }
            .ignoresSafeArea(edges: .horizontal)

            // Loading overlay
            if viewModel.isLoading && viewModel.crew.isEmpty {
                loadingOverlay
            }

            // Error banner
            if let err = viewModel.errorMessage {
                errorBanner(err)
            }

            // Crew count pill
            if !viewModel.crew.isEmpty {
                crewCountPill
                    .padding(.bottom, 24)
            }
        }
        .navigationTitle("Crew Map")
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
        .task {
            await viewModel.load()
            viewModel.startAutoRefresh()
        }
        .onDisappear {
            viewModel.stopAutoRefresh()
        }
        .sheet(item: $viewModel.selectedMember) { member in
            CrewMemberSheet(member: member)
                .presentationDetents([.medium])
        }
    }

    // MARK: - Subviews

    private var loadingOverlay: some View {
        ZStack {
            Color(.systemBackground).opacity(0.8)
            VStack(spacing: 10) {
                ProgressView()
                Text("Loading crew positions…")
                    .font(.subheadline)
                    .foregroundStyle(.secondary)
            }
        }
        .frame(maxWidth: .infinity, maxHeight: .infinity)
        .ignoresSafeArea()
    }

    private func errorBanner(_ message: String) -> some View {
        Label(message, systemImage: "exclamationmark.triangle.fill")
            .font(.caption)
            .foregroundStyle(.white)
            .padding(.horizontal, 14)
            .padding(.vertical, 8)
            .background(Color.orange)
            .clipShape(Capsule())
            .padding(.bottom, 60)
    }

    private var crewCountPill: some View {
        let clocked = viewModel.crew.filter(\.isClockedIn).count
        return Label("\(viewModel.crew.count) tracked · \(clocked) clocked in",
                     systemImage: "location.fill")
            .font(.caption.weight(.medium))
            .padding(.horizontal, 14)
            .padding(.vertical, 8)
            .background(.ultraThinMaterial)
            .clipShape(Capsule())
    }
}

// MARK: - Crew Pin

private struct CrewPin: View {
    let member: CrewMember
    let onTap: () -> Void

    private var pinColor: Color {
        switch member.staleness {
        case .fresh: return member.isClockedIn ? Color.MW.green : .blue
        case .stale: return .orange
        case .old:   return .gray
        }
    }

    var body: some View {
        Button(action: onTap) {
            VStack(spacing: 2) {
                ZStack {
                    Circle()
                        .fill(pinColor)
                        .frame(width: 36, height: 36)
                        .shadow(radius: 3)
                    Image(systemName: "person.fill")
                        .foregroundStyle(.white)
                        .font(.system(size: 16))
                }
                // Callout label
                Text(member.fullName.components(separatedBy: " ").first ?? member.fullName)
                    .font(.system(size: 10, weight: .semibold))
                    .foregroundStyle(.primary)
                    .padding(.horizontal, 5)
                    .padding(.vertical, 2)
                    .background(.ultraThinMaterial)
                    .clipShape(Capsule())
            }
        }
        .buttonStyle(.plain)
    }
}

// MARK: - Crew Member Detail Sheet

private struct CrewMemberSheet: View {
    let member: CrewMember

    var body: some View {
        NavigationStack {
            List {
                Section {
                    HStack(spacing: 16) {
                        Image(systemName: "person.circle.fill")
                            .font(.system(size: 50))
                            .foregroundStyle(member.isClockedIn ? Color.MW.green : .secondary)
                        VStack(alignment: .leading, spacing: 3) {
                            Text(member.fullName)
                                .font(.headline)
                            Text(member.role.capitalized)
                                .font(.subheadline)
                                .foregroundStyle(.secondary)
                            Label(
                                member.isClockedIn ? "Clocked in" : "Not clocked in",
                                systemImage: member.isClockedIn ? "clock.fill" : "clock"
                            )
                            .font(.caption)
                            .foregroundStyle(member.isClockedIn ? Color.MW.green : .secondary)
                        }
                    }
                    .padding(.vertical, 4)
                }

                Section("Last Known Location") {
                    LabeledContent("Updated", value: member.lastSeenText)
                    LabeledContent("Latitude",  value: String(format: "%.6f", member.lat))
                    LabeledContent("Longitude", value: String(format: "%.6f", member.lng))
                    if let acc = member.accuracyMeters {
                        LabeledContent("Accuracy", value: "\(acc) m")
                    }
                    LabeledContent("Device", value: member.deviceType.capitalized)
                }
            }
            .navigationTitle(member.fullName)
            .navigationBarTitleDisplayMode(.inline)
        }
    }
}
