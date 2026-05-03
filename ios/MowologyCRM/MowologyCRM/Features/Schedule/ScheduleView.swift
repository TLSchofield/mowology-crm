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

    @State private var showDatePicker  = false
    @State private var pickerDate      = Date()

    private let impactLight  = UIImpactFeedbackGenerator(style: .light)
    private let impactMedium = UIImpactFeedbackGenerator(style: .medium)

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

                // MARK: Week Navigation
                weekNavBar

                // MARK: Week Strip
                WeekStripView(
                    selectedDate: weekStripBinding,
                    weekDays: viewModel.weekDays
                )
                // Horizontal swipe on the strip navigates weeks.
                // minimumDistance:20 lets the inner horizontal scroll still work
                // for small movements; the gesture only fires on deliberate
                // side-swipes that are more horizontal than vertical.
                .gesture(
                    DragGesture(minimumDistance: 30)
                        .onEnded { value in
                            let dx = value.translation.width
                            let dy = abs(value.translation.height)
                            guard abs(dx) > dy * 1.2, abs(dx) > 40 else { return }
                            impactMedium.impactOccurred()
                            Task { await viewModel.advanceWeek(by: dx < 0 ? 1 : -1) }
                        }
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
            await viewModel.refresh()
        }
        .sheet(isPresented: $showDatePicker) {
            datePicker
        }
    }

    // MARK: - Week Nav Bar

    private var weekNavBar: some View {
        HStack(spacing: 0) {
            Button {
                impactMedium.impactOccurred()
                Task { await viewModel.advanceWeek(by: -1) }
            } label: {
                Image(systemName: "chevron.left")
                    .font(.system(size: 15, weight: .semibold))
                    .foregroundStyle(Color.MW.green)
                    .frame(width: 44, height: 44)
            }
            .disabled(viewModel.isLoading)

            Spacer()

            // Tap to open full calendar picker
            Button {
                pickerDate = viewModel.selectedDate
                showDatePicker = true
            } label: {
                HStack(spacing: 5) {
                    Text(viewModel.weekRangeLabel)
                        .font(.subheadline.weight(.semibold))
                        .foregroundStyle(.primary)
                    Image(systemName: "calendar")
                        .font(.system(size: 12, weight: .medium))
                        .foregroundStyle(Color.MW.green)
                }
                .contentShape(Rectangle())
            }
            .buttonStyle(.plain)

            Spacer()

            Button {
                impactMedium.impactOccurred()
                Task { await viewModel.advanceWeek(by: 1) }
            } label: {
                Image(systemName: "chevron.right")
                    .font(.system(size: 15, weight: .semibold))
                    .foregroundStyle(Color.MW.green)
                    .frame(width: 44, height: 44)
            }
            .disabled(viewModel.isLoading)
        }
        .padding(.horizontal, 4)
        .background(Color(.systemBackground))
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

    private var navigationTitle: String {
        let formatter = DateFormatter()
        formatter.dateFormat = "MMMM yyyy"
        formatter.locale     = Locale(identifier: "en_CA")
        return formatter.string(from: viewModel.selectedDate)
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
