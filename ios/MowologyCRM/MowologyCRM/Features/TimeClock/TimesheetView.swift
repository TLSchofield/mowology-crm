//
//  TimesheetView.swift
//  MowologyCRM
//
//  "My Timesheet" — the crew member's own hours, a week at a time. Read-only:
//  corrections are made by the office, and show here flagged as edited.
//

import SwiftUI

struct TimesheetView: View {

    @StateObject private var viewModel: TimesheetViewModel

    init(authSession: AuthSession) {
        _viewModel = StateObject(wrappedValue: TimesheetViewModel(authSession: authSession))
    }

    var body: some View {
        ScrollView {
            VStack(spacing: 16) {
                weekNavigator

                if let error = viewModel.errorMessage {
                    Label(error, systemImage: "exclamationmark.triangle.fill")
                        .font(.subheadline)
                        .foregroundStyle(Color.MW.orange)
                        .frame(maxWidth: .infinity, alignment: .leading)
                        .padding(12)
                        .background(Color.MW.orange.opacity(0.08))
                        .clipShape(RoundedRectangle(cornerRadius: 10))
                }

                if let week = viewModel.week {
                    totalsCard(week)
                    ForEach(week.days.filter { !$0.isEmpty }) { day in
                        dayCard(day)
                    }
                    if week.days.allSatisfy(\.isEmpty) {
                        emptyState
                    }
                } else if viewModel.isLoading {
                    ProgressView().padding(.top, 40)
                }
            }
            .padding(.horizontal, 16)
            .padding(.vertical, 16)
        }
        .background(Color(.systemGroupedBackground))
        .navigationTitle("My Timesheet")
        .navigationBarTitleDisplayMode(.inline)
        .task { await viewModel.load() }
        .refreshable { await viewModel.load() }
    }

    // MARK: - Week Navigator

    private var weekNavigator: some View {
        HStack {
            Button {
                Task { await viewModel.shiftWeek(by: -1) }
            } label: {
                Image(systemName: "chevron.left").font(.headline).frame(width: 44, height: 44)
            }

            Spacer()
            VStack(spacing: 2) {
                Text(viewModel.weekLabel).font(.headline)
                if viewModel.isCurrentWeek {
                    Text("This week").font(.caption).foregroundStyle(.secondary)
                }
            }
            Spacer()

            Button {
                Task { await viewModel.shiftWeek(by: 1) }
            } label: {
                Image(systemName: "chevron.right").font(.headline).frame(width: 44, height: 44)
            }
            .disabled(viewModel.isCurrentWeek)
        }
        .tint(Color.MW.green)
        .disabled(viewModel.isLoading)
    }

    // MARK: - Totals

    private func totalsCard(_ week: TimesheetWeekResponse) -> some View {
        HStack(spacing: 0) {
            totalColumn(title: "Clocked", value: TimesheetViewModel.duration(week.totalSeconds), tint: Color.MW.green)
            Divider().frame(height: 36)
            totalColumn(title: "On jobs", value: TimesheetViewModel.duration(week.jobTotalSeconds), tint: .primary)
        }
        .padding(.vertical, 16)
        .background(Color(.systemBackground))
        .clipShape(RoundedRectangle(cornerRadius: 12))
    }

    private func totalColumn(title: String, value: String, tint: Color) -> some View {
        VStack(spacing: 4) {
            Text(value).font(.title2.bold()).foregroundStyle(tint)
            Text(title).font(.caption).foregroundStyle(.secondary)
        }
        .frame(maxWidth: .infinity)
    }

    // MARK: - Day Card

    private func dayCard(_ day: TimesheetDay) -> some View {
        VStack(alignment: .leading, spacing: 10) {
            HStack {
                Text(TimesheetViewModel.dayLabel(day.date)).font(.subheadline.bold())
                Spacer()
                Text(TimesheetViewModel.duration(day.totalSeconds))
                    .font(.subheadline.bold())
                    .foregroundStyle(Color.MW.green)
            }

            ForEach(day.entries) { entry in
                HStack(spacing: 8) {
                    Image(systemName: entry.isOpen ? "clock.badge.checkmark.fill" : "clock")
                        .foregroundStyle(entry.isOpen ? Color.MW.green : .secondary)
                    Text("\(TimesheetViewModel.time(entry.clockIn)) – \(TimesheetViewModel.time(entry.clockOut))")
                        .font(.subheadline)
                    if entry.edited {
                        Text("Edited")
                            .font(.caption2.bold())
                            .padding(.horizontal, 6).padding(.vertical, 2)
                            .background(Color.MW.orange.opacity(0.12))
                            .foregroundStyle(Color.MW.orange)
                            .clipShape(Capsule())
                    }
                    Spacer()
                    Text(TimesheetViewModel.duration(entry.durationSeconds))
                        .font(.subheadline)
                        .foregroundStyle(.secondary)
                }
                if let notes = entry.notes {
                    Text(notes).font(.caption).foregroundStyle(.secondary).padding(.leading, 28)
                }
            }

            if !day.jobs.isEmpty {
                Divider()
                ForEach(day.jobs) { job in
                    HStack(alignment: .top, spacing: 8) {
                        Image(systemName: "leaf.fill")
                            .font(.caption)
                            .foregroundStyle(Color.MW.green)
                            .padding(.top, 3)
                        VStack(alignment: .leading, spacing: 1) {
                            Text(job.propertyAddress ?? job.jobTitle ?? "Visit #\(job.visitId)")
                                .font(.footnote)
                            if job.propertyAddress != nil, let title = job.jobTitle {
                                Text(title).font(.caption2).foregroundStyle(.secondary)
                            }
                        }
                        Spacer()
                        Text(TimesheetViewModel.duration(job.durationSeconds))
                            .font(.footnote)
                            .foregroundStyle(.secondary)
                    }
                }
            }
        }
        .padding(14)
        .background(Color(.systemBackground))
        .clipShape(RoundedRectangle(cornerRadius: 12))
    }

    private var emptyState: some View {
        VStack(spacing: 8) {
            Image(systemName: "calendar.badge.clock")
                .font(.system(size: 40))
                .foregroundStyle(Color(.systemGray3))
            Text("No hours recorded this week").font(.subheadline).foregroundStyle(.secondary)
        }
        .padding(.top, 40)
    }
}
