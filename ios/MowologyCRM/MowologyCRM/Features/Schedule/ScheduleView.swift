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

    @State private var showDatePicker = false
    @State private var pickerDate     = Date()
    @State private var viewMode       = ViewMode.list

    private let impactLight  = UIImpactFeedbackGenerator(style: .light)
    private let impactMedium = UIImpactFeedbackGenerator(style: .medium)

    private enum ViewMode { case list, map }

    // MARK: - Init

    init(authSession: AuthSession? = nil) {
        let session = authSession ?? AuthSession()
        let client  = APIClient(authSession: session)
        _viewModel  = StateObject(wrappedValue: ScheduleViewModel(apiClient: client))
    }

    // MARK: - Body

    var body: some View {
        NavigationStack {
            VStack(spacing: 0) {

                // MARK: Week Strip
                WeekStripView(
                    selectedDate: weekStripBinding,
                    weekDays: viewModel.weekDays
                )
                .gesture(
                    DragGesture(minimumDistance: 20)
                        .onEnded { value in
                            let dx = value.translation.width
                            let dy = abs(value.translation.height)
                            guard abs(dx) > dy * 1.5, abs(dx) > 40 else { return }
                            impactMedium.impactOccurred()
                            Task { await viewModel.advanceWeek(by: dx < 0 ? 1 : -1) }
                        }
                )

                // MARK: View Mode Toggle
                Picker("View", selection: $viewMode) {
                    Label("List", systemImage: "list.bullet").tag(ViewMode.list)
                    Label("Map",  systemImage: "map").tag(ViewMode.map)
                }
                .pickerStyle(.segmented)
                .padding(.horizontal, 16)
                .padding(.vertical, 8)
                .background(Color(.systemBackground))

                Divider()

                // MARK: Content
                if viewMode == .list {
                    DayListView(
                        stops:        viewModel.stops,
                        isLoading:    viewModel.isLoading,
                        errorMessage: viewModel.errorMessage,
                        isAdmin:      authSession.user?.isAdmin ?? false,
                        onRefresh:    { await viewModel.refresh() }
                    )
                } else {
                    DayMapView(
                        stops:         viewModel.stops,
                        isLoading:     viewModel.isLoading,
                        isAdmin:       authSession.user?.isAdmin ?? false,
                        routes:        viewModel.crewRoutes,
                        liveCrew:      viewModel.crewLive,
                        currentUserId: authSession.user?.id
                    )
                }
            }
            // Single navigationDestination covers both list and map modes.
            .navigationDestination(for: Stop.self) { stop in
                VisitDetailView(
                    stop:        stop,
                    isAdmin:     authSession.user?.isAdmin ?? false,
                    authSession: authSession
                )
            }
            .navigationBarTitleDisplayMode(.inline)
            .toolbar { toolbarContent }
            .background(Color(.systemGroupedBackground))
        }
        .task {
            await viewModel.refresh()
        }
        .sheet(isPresented: $showDatePicker) {
            datePicker
        }
    }

    // MARK: - Date Picker Sheet

    private var datePicker: some View {
        NavigationStack {
            DatePicker(
                "Jump to date",
                selection: $pickerDate,
                displayedComponents: .date
            )
            .datePickerStyle(.graphical)
            .tint(Color.MW.green)
            .padding(.horizontal)
            .navigationTitle("Jump to Date")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .cancellationAction) {
                    Button("Cancel") { showDatePicker = false }
                        .foregroundStyle(Color.MW.green)
                }
                ToolbarItem(placement: .confirmationAction) {
                    Button("Go") {
                        impactLight.impactOccurred()
                        showDatePicker = false
                        Task { await viewModel.selectDate(pickerDate) }
                    }
                    .font(.subheadline.weight(.semibold))
                    .foregroundStyle(Color.MW.green)
                }
            }
        }
        .presentationDetents([.medium])
        .presentationDragIndicator(.visible)
    }

    // MARK: - Computed

    private var weekStripBinding: Binding<Date> {
        Binding(
            get: { viewModel.selectedDate },
            set: { newDate in
                impactLight.impactOccurred()
                Task { await viewModel.selectDate(newDate) }
            }
        )
    }

    // MARK: - Toolbar

    @ToolbarContentBuilder
    private var toolbarContent: some ToolbarContent {
        ToolbarItem(placement: .navigationBarLeading) {
            Button {
                impactLight.impactOccurred()
                Task { await viewModel.selectDate(.now) }
            } label: {
                Text("Today")
                    .font(.subheadline.weight(.medium))
                    .foregroundStyle(Color.MW.green)
            }
        }

        ToolbarItem(placement: .principal) {
            Button {
                pickerDate = viewModel.selectedDate
                showDatePicker = true
            } label: {
                HStack(spacing: 5) {
                    Text(viewModel.weekRangeLabel)
                        .font(.headline.weight(.semibold))
                        .foregroundStyle(.primary)
                    Image(systemName: "calendar")
                        .font(.system(size: 12, weight: .medium))
                        .foregroundStyle(Color.MW.green)
                }
            }
            .buttonStyle(.plain)
        }

        ToolbarItem(placement: .navigationBarTrailing) {
            Button {
                Task { await viewModel.invalidateAndRefresh() }
            } label: {
                Image(systemName: "arrow.clockwise")
                    .foregroundStyle(Color.MW.green)
            }
            .disabled(viewModel.isLoading)
        }
    }
}

#Preview {
    ScheduleView()
        .environmentObject(AuthSession())
}
