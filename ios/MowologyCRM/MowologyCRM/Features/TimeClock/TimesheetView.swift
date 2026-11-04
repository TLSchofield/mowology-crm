//
//  TimesheetView.swift
//  MowologyCRM
//
//  "My Timesheet" — the crew member's own hours, a week at a time. Read-only:
//  corrections are made by the office, and show here flagged as edited.
//

import SwiftUI

struct TimesheetView: View {
    @Environment(\.itAppStyle) private var appStyle
    @Environment(\.dynamicTypeSize) private var dynamicTypeSize

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
                        .font(appStyle.font(.subheadline))
                        .foregroundStyle(appStyle.attention)
                        .frame(maxWidth: .infinity, alignment: .leading)
                        .padding(12)
                        .background(appStyle.attention.opacity(0.08))
                        .clipShape(RoundedRectangle(cornerRadius: appStyle.cornerRadius(10)))
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
        .background(appStyle.page)
        .itAppScreen()
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
                Image(systemName: "chevron.left").font(appStyle.font(.headline)).frame(width: appStyle.isEnabled ? 48 : 44, height: appStyle.isEnabled ? 48 : 44)
            }

            Spacer()
            VStack(spacing: 2) {
                Text(viewModel.weekLabel).font(appStyle.font(.headline))
                if viewModel.isCurrentWeek {
                    Text("This week").font(appStyle.font(.caption)).foregroundStyle(appStyle.meta)
                }
            }
            Spacer()

            Button {
                Task { await viewModel.shiftWeek(by: 1) }
            } label: {
                Image(systemName: "chevron.right").font(appStyle.font(.headline)).frame(width: appStyle.isEnabled ? 48 : 44, height: appStyle.isEnabled ? 48 : 44)
            }
            .disabled(viewModel.isCurrentWeek)
        }
        .tint(appStyle.tenant)
        .disabled(viewModel.isLoading)
    }

    // MARK: - Totals

    private func totalsCard(_ week: TimesheetWeekResponse) -> some View {
        AnyLayout(appStyle.isEnabled && dynamicTypeSize.isAccessibilitySize ? AnyLayout(VStackLayout(spacing: 12)) : AnyLayout(HStackLayout(spacing: 0))) {
            totalColumn(title: "Clocked", value: TimesheetViewModel.duration(week.totalSeconds), tint: appStyle.tenant)
            Divider().frame(height: appStyle.isEnabled && dynamicTypeSize.isAccessibilitySize ? 1 : 36)
            totalColumn(title: "On jobs", value: TimesheetViewModel.duration(week.jobTotalSeconds), tint: appStyle.text)
            if let brk = week.breakSeconds, brk > 0 {
                Divider().frame(height: appStyle.isEnabled && dynamicTypeSize.isAccessibilitySize ? 1 : 36)
                totalColumn(title: (week.breaksPaid ?? true) ? "Breaks (paid)" : "Breaks (unpaid)",
                            value: TimesheetViewModel.duration(brk), tint: appStyle.attention)
            }
        }
        .padding(.vertical, 16)
        .background(appStyle.surface)
        .clipShape(RoundedRectangle(cornerRadius: appStyle.cornerRadius(12)))
    }

    private func totalColumn(title: String, value: String, tint: Color) -> some View {
        VStack(spacing: 4) {
            Text(value).font(appStyle.font(.title2, weight: .bold)).foregroundStyle(tint)
            Text(title).font(appStyle.font(.caption)).foregroundStyle(appStyle.meta)
        }
        .frame(maxWidth: .infinity)
    }

    // MARK: - Day Card

    private func dayCard(_ day: TimesheetDay) -> some View {
        VStack(alignment: .leading, spacing: 10) {
            HStack {
                Text(TimesheetViewModel.dayLabel(day.date)).font(appStyle.font(.subheadline, weight: .bold))
                Spacer()
                Text(TimesheetViewModel.duration(day.totalSeconds))
                    .font(appStyle.font(.subheadline, weight: .bold))
                    .foregroundStyle(appStyle.tenant)
            }

            ForEach(day.entries) { entry in
                AnyLayout(appStyle.isEnabled && dynamicTypeSize.isAccessibilitySize ? AnyLayout(VStackLayout(alignment: .leading, spacing: 8)) : AnyLayout(HStackLayout(spacing: 8))) {
                    Image(systemName: entry.isOpen ? "clock.badge.checkmark.fill" : "clock")
                        .foregroundStyle(entry.isOpen ? appStyle.tenant : appStyle.meta)
                    Text("\(TimesheetViewModel.time(entry.clockIn)) – \(TimesheetViewModel.time(entry.clockOut))")
                        .font(appStyle.font(.subheadline))
                    if entry.edited {
                        Text("Edited")
                            .font(appStyle.font(.caption2, weight: .bold))
                            .padding(.horizontal, 6).padding(.vertical, 2)
                            .background(appStyle.attention.opacity(0.12))
                            .foregroundStyle(appStyle.attention)
                            .clipShape(RoundedRectangle(cornerRadius: appStyle.isEnabled ? appStyle.cornerRadius(12) : 999))
                    }
                    Spacer()
                    Text(TimesheetViewModel.duration(entry.durationSeconds))
                        .font(appStyle.font(.subheadline))
                        .foregroundStyle(appStyle.meta)
                }
                if let notes = entry.notes {
                    Text(notes).font(appStyle.font(.caption)).foregroundStyle(appStyle.meta).padding(.leading, 28)
                }
                ForEach(entry.breaks ?? []) { brk in
                    AnyLayout(appStyle.isEnabled && dynamicTypeSize.isAccessibilitySize ? AnyLayout(VStackLayout(alignment: .leading, spacing: 8)) : AnyLayout(HStackLayout(spacing: 8))) {
                        Image(systemName: "cup.and.saucer")
                            .font(appStyle.font(.caption))
                            .foregroundStyle(appStyle.attention)
                        Text("Break \(TimesheetViewModel.time(brk.start)) – \(TimesheetViewModel.time(brk.end))"
                             + (brk.tapped ? "" : " (found by GPS)"))
                            .font(appStyle.font(.caption))
                            .foregroundStyle(appStyle.meta)
                        Spacer()
                        Text(TimesheetViewModel.duration(brk.minutes * 60))
                            .font(appStyle.font(.caption))
                            .foregroundStyle(appStyle.meta)
                    }
                    .padding(.leading, 28)
                }
            }

            if !day.jobs.isEmpty {
                Divider()
                ForEach(day.jobs) { job in
                    HStack(alignment: .top, spacing: 8) {
                        Image(systemName: "leaf.fill")
                            .font(appStyle.font(.caption))
                            .foregroundStyle(appStyle.tenant)
                            .padding(.top, 3)
                        VStack(alignment: .leading, spacing: 1) {
                            Text(job.propertyAddress ?? job.jobTitle ?? "Visit #\(job.visitId)")
                                .font(appStyle.font(.footnote))
                            if job.propertyAddress != nil, let title = job.jobTitle {
                                Text(title).font(appStyle.font(.caption2)).foregroundStyle(appStyle.meta)
                            }
                        }
                        Spacer()
                        Text(TimesheetViewModel.duration(job.durationSeconds))
                            .font(appStyle.font(.footnote))
                            .foregroundStyle(appStyle.meta)
                    }
                }
            }
        }
        .padding(14)
        .background(appStyle.surface)
        .clipShape(RoundedRectangle(cornerRadius: appStyle.cornerRadius(12)))
    }

    private var emptyState: some View {
        VStack(spacing: 8) {
            Image(systemName: "calendar.badge.clock")
                .font(appStyle.font(size: 40))
                .foregroundStyle((appStyle.isEnabled ? appStyle.meta : Color(.systemGray3)))
            Text("No hours recorded this week").font(appStyle.font(.subheadline)).foregroundStyle(appStyle.meta)
        }
        .padding(.top, 40)
    }
}
