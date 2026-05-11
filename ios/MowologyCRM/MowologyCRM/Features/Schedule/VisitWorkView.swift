//
//  VisitWorkView.swift
//  MowologyCRM
//
//  Native on-site job timer screen — equivalent of visit-work.php for iOS.
//  Pushed from VisitDetailView when the crew member taps "Start Job".
//
//  State machine:
//    scheduled  → "Start Job" button → in_progress  → "End Job" button → completed
//
//  GPS breadcrumbs are collected every second and flushed to pow-gps-sync.php
//  every 30s while the visit is in_progress. The elapsed timer is local-only;
//  the authoritative duration is computed server-side from job_time_entries timestamps.

import SwiftUI

struct VisitWorkView: View {

    let stop: Stop
    let visit: Visit

    @StateObject private var vm: VisitWorkViewModel
    @Environment(\.dismiss) private var dismiss

    init(stop: Stop, visit: Visit, authSession: AuthSession) {
        self.stop  = stop
        self.visit = visit
        _vm = StateObject(wrappedValue: VisitWorkViewModel(visit: visit, authSession: authSession))
    }

    // MARK: - Body

    var body: some View {
        ScrollView {
            VStack(spacing: 24) {

                // ── Property header ─────────────────────────────────────────
                propertyHeader

                // ── Timer display ───────────────────────────────────────────
                timerCard

                // ── Billing context chip ────────────────────────────────────
                if vm.visitStatus.lowercased() == "in_progress" {
                    billingContextChip
                }

                // ── Photo-warning banner (admin only, non-blocking) ─────────
                if !vm.photoWarnings.isEmpty {
                    HStack(spacing: 8) {
                        Image(systemName: "camera.badge.exclamationmark")
                            .foregroundStyle(.orange)
                        Text("Note: \(vm.photoWarnings.joined(separator: ", ")) photo missing")
                            .font(.caption)
                            .foregroundStyle(.orange)
                        Spacer()
                    }
                    .padding(10)
                    .background(Color.orange.opacity(0.08))
                    .clipShape(RoundedRectangle(cornerRadius: 8))
                }

                // ── Action button ───────────────────────────────────────────
                actionButton

                // ── Notes ───────────────────────────────────────────────────
                if vm.visitStatus.lowercased() == "in_progress" {
                    notesCard
                }

                // ── Completion banner ───────────────────────────────────────
                if vm.visitStatus.lowercased() == "completed" {
                    completionBanner
                }

                Spacer(minLength: 24)
            }
            .padding(.horizontal, 16)
            .padding(.top, 20)
        }
        .background(Color(.systemGroupedBackground))
        .navigationTitle("On Site")
        .navigationBarTitleDisplayMode(.inline)
        .alert("Error", isPresented: Binding(
            get: { vm.errorMessage != nil },
            set: { if !$0 { vm.errorMessage = nil } }
        )) {
            Button("OK", role: .cancel) { vm.errorMessage = nil }
        } message: {
            Text(vm.errorMessage ?? "")
        }
        .sheet(item: $vm.invoicePrefill) { prefill in
            InvoiceComposeView(
                visitId: visit.visitId,
                prefill: prefill,
                apiClient: vm.apiClient
            )
            .presentationDetents([.medium, .large])
            .presentationDragIndicator(.visible)
        }
        // Photo-gate alert: crew is blocked until required photos are uploaded
        .alert("Photos Required", isPresented: Binding(
            get: { vm.blockedByPhotos != nil },
            set: { if !$0 { vm.blockedByPhotos = nil } }
        )) {
            Button("OK", role: .cancel) { vm.blockedByPhotos = nil }
        } message: {
            let missing = vm.blockedByPhotos?.joined(separator: ", ") ?? ""
            Text("Please upload the required \(missing) photo(s) before completing this visit.")
        }
    }

    // MARK: - Property Header

    private var propertyHeader: some View {
        VStack(alignment: .leading, spacing: 6) {
            Text(stop.displayName ?? stop.propertyAddress)
                .font(.title3.weight(.semibold))
                .foregroundStyle(Color.MW.dark)

            Text(stop.fullAddress)
                .font(.subheadline)
                .foregroundStyle(.secondary)

            Text(visit.serviceTypeLabel)
                .font(.caption.weight(.medium))
                .padding(.horizontal, 10)
                .padding(.vertical, 4)
                .background(Color.MW.green.opacity(0.12))
                .foregroundStyle(Color.MW.green)
                .clipShape(Capsule())
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .padding(16)
        .background(Color(.secondarySystemGroupedBackground))
        .clipShape(RoundedRectangle(cornerRadius: 12))
    }

    // MARK: - Timer Card

    private var timerCard: some View {
        VStack(spacing: 12) {
            // Status badge
            Text(statusLabel)
                .font(.caption.weight(.semibold))
                .textCase(.uppercase)
                .foregroundStyle(statusColor)
                .tracking(1)

            // Elapsed time — only shown while in progress
            if vm.visitStatus.lowercased() == "in_progress" {
                Text(vm.elapsedFormatted)
                    .font(.system(size: 52, weight: .light, design: .monospaced))
                    .foregroundStyle(Color.MW.dark)
                    .contentTransition(.numericText())
                    .animation(.linear(duration: 0.2), value: vm.elapsedSeconds)

                Text("elapsed on site")
                    .font(.caption)
                    .foregroundStyle(.secondary)
            } else if vm.visitStatus.lowercased() == "completed" {
                Image(systemName: "checkmark.circle.fill")
                    .font(.system(size: 52))
                    .foregroundStyle(Color.MW.green)
            } else {
                Image(systemName: "timer")
                    .font(.system(size: 52))
                    .foregroundStyle(Color.MW.green.opacity(0.4))

                Text("Timer starts when you tap Start Job")
                    .font(.caption)
                    .foregroundStyle(.secondary)
                    .multilineTextAlignment(.center)
            }
        }
        .frame(maxWidth: .infinity)
        .padding(.vertical, 28)
        .padding(.horizontal, 16)
        .background(Color(.secondarySystemGroupedBackground))
        .clipShape(RoundedRectangle(cornerRadius: 12))
    }

    // MARK: - Action Button

    @ViewBuilder
    private var actionButton: some View {
        let status = vm.visitStatus.lowercased()

        if status == "scheduled" {
            Button {
                Task { await vm.startVisit() }
            } label: {
                Label("Start Job", systemImage: "play.fill")
                    .font(.headline)
                    .frame(maxWidth: .infinity)
                    .padding(.vertical, 16)
                    .background(Color.MW.green)
                    .foregroundStyle(.white)
                    .clipShape(RoundedRectangle(cornerRadius: 12))
            }
            .disabled(vm.isLoading)
            .overlay {
                if vm.isLoading {
                    RoundedRectangle(cornerRadius: 12)
                        .fill(Color.MW.green.opacity(0.7))
                    ProgressView().tint(.white)
                }
            }

        } else if status == "in_progress" {
            VStack(spacing: 12) {
                if vm.isAdmin {
                    // Primary: smart complete. Auto-opens invoice compose for
                    // after_visit billing; silently completes for end_of_month/upfront.
                    Button {
                        Task { await vm.completeJob() }
                    } label: {
                        Label("Complete Job", systemImage: "checkmark.circle.fill")
                            .font(.headline)
                            .frame(maxWidth: .infinity)
                            .padding(.vertical, 16)
                            .background(vm.isLoading ? Color.MW.green.opacity(0.6) : Color.MW.green)
                            .foregroundStyle(.white)
                            .clipShape(RoundedRectangle(cornerRadius: 12))
                    }
                    .disabled(vm.isLoading)
                    .overlay {
                        if vm.isLoading {
                            RoundedRectangle(cornerRadius: 12)
                                .fill(Color.MW.green.opacity(0.5))
                            ProgressView().tint(.white)
                        }
                    }

                    // Secondary escape hatch: always end without triggering invoice
                    Button(role: .destructive) {
                        Task { await vm.endVisit() }
                    } label: {
                        Label("End without invoice", systemImage: "stop.fill")
                            .font(.subheadline)
                            .frame(maxWidth: .infinity)
                            .padding(.vertical, 12)
                            .background(Color(.systemBackground))
                            .foregroundStyle(.red)
                            .clipShape(RoundedRectangle(cornerRadius: 12))
                            .overlay(
                                RoundedRectangle(cornerRadius: 12)
                                    .stroke(Color.red.opacity(0.4), lineWidth: 1)
                            )
                    }
                    .disabled(vm.isLoading)

                } else {
                    // Crew: plain End Job
                    Button(role: .destructive) {
                        Task { await vm.endVisit() }
                    } label: {
                        Label("End Job", systemImage: "stop.fill")
                            .font(.headline)
                            .frame(maxWidth: .infinity)
                            .padding(.vertical, 16)
                            .background(Color.red.opacity(0.9))
                            .foregroundStyle(.white)
                            .clipShape(RoundedRectangle(cornerRadius: 12))
                    }
                    .disabled(vm.isLoading)
                    .overlay {
                        if vm.isLoading {
                            RoundedRectangle(cornerRadius: 12)
                                .fill(Color.red.opacity(0.7))
                            ProgressView().tint(.white)
                        }
                    }
                }
            }
        }
    }

    // MARK: - Billing Context Chip

    /// Shows billing type and photo requirement status during an active visit.
    private var billingContextChip: some View {
        HStack(spacing: 8) {
            Image(systemName: "creditcard")
                .font(.caption2)
                .foregroundStyle(Color.MW.green)

            Text(visit.billingLabel)
                .font(.caption.weight(.medium))
                .foregroundStyle(Color.MW.dark)

            if visit.invoiceTiming == "end_of_month" {
                Text("• Billed at month end")
                    .font(.caption2)
                    .foregroundStyle(.secondary)
            } else if visit.invoiceTiming == "upfront" {
                Text("• Prepaid")
                    .font(.caption2)
                    .foregroundStyle(.secondary)
            }

            if !visit.photoTypesRequired.isEmpty {
                Spacer()
                ForEach(visit.photoTypesRequired, id: \.self) { type in
                    let count = type == "before" ? visit.beforePhotoCount : visit.afterPhotoCount
                    Label(type.capitalized, systemImage: count > 0 ? "camera.fill" : "camera")
                        .font(.caption2)
                        .foregroundStyle(count > 0 ? Color.MW.green : Color(.systemGray3))
                }
            }
        }
        .padding(.horizontal, 12)
        .padding(.vertical, 8)
        .background(Color.MW.green.opacity(0.06))
        .clipShape(RoundedRectangle(cornerRadius: 8))
    }

    // MARK: - Notes Card

    private var notesCard: some View {
        VStack(alignment: .leading, spacing: 12) {
            Text("Job Notes")
                .font(.subheadline.weight(.semibold))
                .foregroundStyle(Color.MW.dark)

            TextEditor(text: $vm.pendingNoteText)
                .frame(minHeight: 100)
                .padding(8)
                .background(Color(.tertiarySystemGroupedBackground))
                .clipShape(RoundedRectangle(cornerRadius: 8))
                .overlay(
                    RoundedRectangle(cornerRadius: 8)
                        .stroke(Color(.separator), lineWidth: 0.5)
                )

            HStack {
                if vm.noteSaved {
                    Label("Saved", systemImage: "checkmark.circle.fill")
                        .font(.caption.weight(.medium))
                        .foregroundStyle(Color.MW.green)
                        .transition(.opacity)
                }
                Spacer()
                Button("Save Note") {
                    Task { await vm.saveNote() }
                }
                .font(.subheadline.weight(.medium))
                .foregroundStyle(vm.pendingNoteText.trimmingCharacters(in: .whitespaces).isEmpty
                    ? .secondary : Color.MW.green)
                .disabled(vm.pendingNoteText.trimmingCharacters(in: .whitespaces).isEmpty)
            }
            .animation(.easeInOut(duration: 0.2), value: vm.noteSaved)
        }
        .padding(16)
        .background(Color(.secondarySystemGroupedBackground))
        .clipShape(RoundedRectangle(cornerRadius: 12))
    }

    // MARK: - Completion Banner

    private var completionBanner: some View {
        VStack(spacing: 10) {
            Text("Visit Complete")
                .font(.headline)
                .foregroundStyle(Color.MW.dark)
            Text("Great work! This visit has been recorded.")
                .font(.subheadline)
                .foregroundStyle(.secondary)
                .multilineTextAlignment(.center)
            Button("Back to Schedule") {
                dismiss()
            }
            .font(.subheadline.weight(.semibold))
            .foregroundStyle(Color.MW.green)
            .padding(.top, 4)
        }
        .frame(maxWidth: .infinity)
        .padding(20)
        .background(Color.MW.green.opacity(0.08))
        .overlay(
            RoundedRectangle(cornerRadius: 12)
                .stroke(Color.MW.green.opacity(0.3), lineWidth: 1)
        )
        .clipShape(RoundedRectangle(cornerRadius: 12))
    }

    // MARK: - Helpers

    private var statusLabel: String {
        switch vm.visitStatus.lowercased() {
        case "in_progress": return "In Progress"
        case "completed":   return "Completed"
        case "cancelled":   return "Cancelled"
        default:            return "Scheduled"
        }
    }

    private var statusColor: Color {
        switch vm.visitStatus.lowercased() {
        case "in_progress": return Color.MW.green
        case "completed":   return .green
        case "cancelled":   return .red
        default:            return .blue
        }
    }
}
