//
//  ScheduleView.swift
//  MowologyCRM
//
//  Created by Mowology on 2026-03-03.
//

import SwiftUI

struct ScheduleView: View {

    @EnvironmentObject private var authSession: AuthSession
    @StateObject private var viewModel: ScheduleViewModel

    private let mwGreen = Color(red: 0.176, green: 0.525, blue: 0.349)

    // MARK: - Init

    init(authSession: AuthSession? = nil) {
        // APIClient is created inline here; in a larger app you would inject
        // it via environment. We create it with a temporary AuthSession and
        // patch it via .task after environment resolves.
        let session = authSession ?? AuthSession()
        let client  = APIClient(authSession: session)
        _viewModel  = StateObject(wrappedValue: ScheduleViewModel(apiClient: client))
    }

    // MARK: - Body

    var body: some View {
        NavigationStack {
            VStack(spacing: 0) {

                // MARK: Week Navigation
                HStack(spacing: 0) {
                    Button {
                        Task { await viewModel.advanceWeek(by: -1) }
                    } label: {
                        Image(systemName: "chevron.left")
                            .font(.system(size: 15, weight: .semibold))
                            .foregroundStyle(mwGreen)
                            .frame(width: 44, height: 44)
                    }
                    .disabled(viewModel.isLoading)

                    Spacer()

                    Text(viewModel.weekRangeLabel)
                        .font(.subheadline.weight(.semibold))
                        .foregroundStyle(.primary)

                    Spacer()

                    Button {
                        Task { await viewModel.advanceWeek(by: 1) }
                    } label: {
                        Image(systemName: "chevron.right")
                            .font(.system(size: 15, weight: .semibold))
                            .foregroundStyle(mwGreen)
                            .frame(width: 44, height: 44)
                    }
                    .disabled(viewModel.isLoading)
                }
                .padding(.horizontal, 4)
                .background(Color(.systemBackground))

                // MARK: Week Strip
                WeekStripView(
                    selectedDate: weekStripBinding,
                    weekDays: viewModel.weekDays
                )

                Divider()

                // MARK: Day List
                DayListView(
                    stops:        viewModel.stops,
                    isLoading:    viewModel.isLoading,
                    errorMessage: viewModel.errorMessage,
                    isAdmin:      authSession.user?.isAdmin ?? false,
                    onRefresh:    { await viewModel.refresh() }
                )
                .navigationDestination(for: Stop.self) { stop in
                    VisitDetailView(
                        stop:        stop,
                        isAdmin:     authSession.user?.isAdmin ?? false,
                        authSession: authSession
                    )
                }
            }
            .navigationTitle(navigationTitle)
            .navigationBarTitleDisplayMode(.inline)
            .toolbar { toolbarContent }
            .background(Color(.systemGroupedBackground))
        }
        .task {
            // Initial load when the view appears.
            await viewModel.refresh()
        }
    }

    // MARK: - Computed

    /// Binding that triggers a day + (conditionally) week reload on change.
    private var weekStripBinding: Binding<Date> {
        Binding(
            get: { viewModel.selectedDate },
            set: { newDate in
                Task { await viewModel.selectDate(newDate) }
            }
        )
    }

    private var navigationTitle: String {
        let formatter = DateFormatter()
        formatter.dateFormat = "MMMM yyyy"
        formatter.locale     = Locale(identifier: "en_CA")
        return formatter.string(from: viewModel.selectedDate)
    }

    // MARK: - Toolbar

    @ToolbarContentBuilder
    private var toolbarContent: some ToolbarContent {
        // Today button — jumps back to the current date.
        ToolbarItem(placement: .navigationBarLeading) {
            Button {
                Task { await viewModel.selectDate(.now) }
            } label: {
                Text("Today")
                    .font(.subheadline.weight(.medium))
                    .foregroundStyle(mwGreen)
            }
        }

        // Refresh button.
        ToolbarItem(placement: .navigationBarTrailing) {
            Button {
                Task { await viewModel.invalidateAndRefresh() }
            } label: {
                Image(systemName: "arrow.clockwise")
                    .foregroundStyle(mwGreen)
            }
            .disabled(viewModel.isLoading)
        }

        // Account moved to bottom tab bar — no need for sign-out here.
    }
}

#Preview {
    ScheduleView()
        .environmentObject(AuthSession())
}
