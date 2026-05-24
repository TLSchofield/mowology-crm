//
//  GPSTrackingBanner.swift
//  MowologyCRM
//
//  Persistent top banner that surfaces GPS tracking state to the crew.
//  - Green banner + pulsing shield: tracking active (clocked in).
//  - Red   banner + pulsing shield: tracking off (not clocked in).
//
//  Placement #2 + glyph #9 from the indicator design pass. The shield
//  pulses in both states — a steady "live indicator" cue — with colour
//  doing the work of communicating state.
//
//  Attach once at the root with `.gpsTrackingBanner()`. The modifier
//  uses `safeAreaInset(edge: .top)` so it cascades into every tab.
//

import SwiftUI

// MARK: - Brand

private extension Color {
    /// Mowology `--mw-green` (#2D8659).
    static let mwGreen = Color(red: 0x2D / 255, green: 0x86 / 255, blue: 0x59 / 255)
    /// `--mw-dark` (#1A5F4A) — used for the pin inside the shield.
    static let mwDark  = Color(red: 0x1A / 255, green: 0x5F / 255, blue: 0x4A / 255)
    /// Deep red that reads on white text without screaming. Matches iOS
    /// `.systemRed` in light mode; we set it explicitly to stay consistent
    /// across appearance modes.
    static let mwAlert = Color(red: 0xC4 / 255, green: 0x1E / 255, blue: 0x3A / 255)
}

// MARK: - Live-wired banner

struct GPSTrackingBanner: View {
    @ObservedObject private var tracking = GPSTrackingService.shared

    var body: some View {
        GPSTrackingBannerContent(
            isTracking:          tracking.isTracking,
            activeVisitId:       tracking.activeVisitId,
            activeVisitStartedAt: tracking.activeVisitStartedAt
        )
    }
}

// MARK: - Pure-visual content (testable / previewable)

private struct GPSTrackingBannerContent: View {
    let isTracking: Bool
    let activeVisitId: Int?
    let activeVisitStartedAt: Date?

    @State private var showSheet = false
    @State private var pulseScale: CGFloat = 1.0

    private var isJobActive: Bool { activeVisitId != nil && activeVisitStartedAt != nil }

    var body: some View {
        Button { showSheet = true } label: {
            HStack(spacing: 10) {
                pulsingShield
                Text(headline)
                    .font(.footnote.weight(.semibold))
                    .foregroundStyle(.white)
                Spacer(minLength: 8)
                trailingContent
                Image(systemName: "chevron.right")
                    .font(.caption2.weight(.bold))
                    .foregroundStyle(.white.opacity(0.7))
            }
            .padding(.horizontal, 14)
            .padding(.vertical, 10)
            .frame(maxWidth: .infinity, minHeight: 44)
            .background(backgroundColor)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .accessibilityElement(children: .combine)
        .accessibilityLabel(accessibilityLabel)
        .onAppear { startPulse() }
        .sheet(isPresented: $showSheet) {
            GPSTrackingDetailSheet(
                isTracking:          isTracking,
                activeVisitId:       activeVisitId,
                activeVisitStartedAt: activeVisitStartedAt
            )
            .presentationDetents([.medium, .large])
        }
    }

    // MARK: - Trailing content

    /// When a job is active: clock glyph + live elapsed timer.
    /// Otherwise: contextual hint ("tap for details" / "clock in to start").
    @ViewBuilder
    private var trailingContent: some View {
        if isJobActive, let startedAt = activeVisitStartedAt {
            HStack(spacing: 4) {
                Image(systemName: "clock.fill")
                    .font(.caption2.weight(.bold))
                    .foregroundStyle(.white)
                TimelineView(.periodic(from: .now, by: 1)) { context in
                    Text(formattedElapsed(from: startedAt, to: context.date))
                        .font(.caption.weight(.semibold))
                        .foregroundStyle(.white)
                        .monospacedDigit()
                }
            }
        } else {
            Text(trailing)
                .font(.caption2.weight(.medium))
                .foregroundStyle(.white.opacity(0.85))
        }
    }

    /// MM:SS for jobs under an hour; H:MM:SS otherwise. Clamped at zero.
    private func formattedElapsed(from start: Date, to now: Date) -> String {
        let total = max(0, Int(now.timeIntervalSince(start)))
        let h = total / 3600
        let m = (total % 3600) / 60
        let s = total % 60
        if h > 0 {
            return String(format: "%d:%02d:%02d", h, m, s)
        } else {
            return String(format: "%d:%02d", m, s)
        }
    }

    // MARK: - Pulsing shield glyph

    /// Composed shield + location-pin (SF Symbols doesn't ship one).
    /// The halo behind the shield pulses on a 1.2 s loop — same animation
    /// regardless of state; colour is what distinguishes on vs off.
    private var pulsingShield: some View {
        ZStack {
            Circle()
                .fill(.white.opacity(0.25))
                .frame(width: 30, height: 30)
                .scaleEffect(pulseScale)
                .opacity(2.0 - pulseScale)   // halo fades as it expands
            Image(systemName: "shield.fill")
                .font(.system(size: 22, weight: .bold))
                .foregroundStyle(.white)
            Image(systemName: "location.fill")
                .font(.system(size: 11, weight: .bold))
                .foregroundStyle(isTracking ? Color.mwDark : Color.mwAlert)
                .offset(y: 1)
        }
        .frame(width: 30, height: 30)
        .accessibilityHidden(true)
    }

    private func startPulse() {
        pulseScale = 1.0
        withAnimation(.easeOut(duration: 1.2).repeatForever(autoreverses: false)) {
            pulseScale = 1.6
        }
    }

    // MARK: - State-derived strings & colours

    private var backgroundColor: Color {
        isTracking ? .mwGreen : .mwAlert
    }

    private var headline: String {
        if isJobActive { return "Job in progress" }
        return isTracking ? "Location tracking on" : "Not tracking"
    }

    private var trailing: String {
        isTracking ? "tap for details" : "clock in to start"
    }

    private var accessibilityLabel: String {
        if isJobActive {
            return "Job timer running. Tap for details."
        }
        return isTracking
            ? "Location tracking is on. Tap for details."
            : "Location tracking is off. Tap for details."
    }
}

// MARK: - Detail sheet

private struct GPSTrackingDetailSheet: View {
    let isTracking: Bool
    let activeVisitId: Int?
    let activeVisitStartedAt: Date?
    @Environment(\.dismiss) private var dismiss

    private var isJobActive: Bool { activeVisitId != nil && activeVisitStartedAt != nil }

    var body: some View {
        NavigationStack {
            List {
                Section {
                    LabeledContent("Status",
                        value: isTracking ? "Tracking" : "Not tracking")
                    LabeledContent("Reason",
                        value: isTracking ? "You are clocked in" : "You are off shift")
                }

                if isJobActive, let startedAt = activeVisitStartedAt {
                    Section("Job timer") {
                        LabeledContent("Visit", value: "#\(activeVisitId ?? 0)")
                        TimelineView(.periodic(from: .now, by: 1)) { context in
                            LabeledContent("Elapsed",
                                value: liveElapsed(from: startedAt, to: context.date))
                        }
                        Text("Started automatically when you arrived at the property. Stop the timer from the visit detail screen.")
                            .font(.footnote)
                            .foregroundStyle(.secondary)
                    }
                }

                Section(isTracking ? "Why we track" : "Why this is off") {
                    Text(isTracking
                        ? "Location is recorded while you're clocked in. It's used to confirm arrivals at properties, calculate drive time between jobs, and provide a record of work performed. Tracking stops automatically when you clock out."
                        : "You are not currently being tracked. The app only records location while you are clocked in. Clock in from the Time Clock tab to start a shift."
                    )
                    .font(.callout)
                }
                Section {
                    NavigationLink("Privacy & data policy") {
                        // Hook to your real privacy view when ready.
                        Text("Privacy policy goes here.")
                            .padding()
                    }
                }
            }
            .navigationTitle("Location tracking")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .confirmationAction) {
                    Button("Done") { dismiss() }
                }
            }
        }
    }

    private func liveElapsed(from start: Date, to now: Date) -> String {
        let total = max(0, Int(now.timeIntervalSince(start)))
        let h = total / 3600
        let m = (total % 3600) / 60
        let s = total % 60
        return h > 0
            ? String(format: "%d:%02d:%02d", h, m, s)
            : String(format: "%d:%02d", m, s)
    }
}

// MARK: - Modifier

extension View {
    /// Pins the GPS tracking banner above this view's safe area. Attach
    /// once on the root (e.g. `RootView`'s authenticated branch). The
    /// banner is always visible — green when tracking, red when not.
    func gpsTrackingBanner() -> some View {
        safeAreaInset(edge: .top, spacing: 0) {
            GPSTrackingBanner()
        }
    }
}

// MARK: - Previews

#Preview("Tracking on, no job") {
    NavigationStack {
        Color(.systemGroupedBackground)
            .ignoresSafeArea()
    }
    .safeAreaInset(edge: .top, spacing: 0) {
        GPSTrackingBannerContent(
            isTracking: true,
            activeVisitId: nil,
            activeVisitStartedAt: nil
        )
    }
}

#Preview("Tracking on, job running") {
    NavigationStack {
        Color(.systemGroupedBackground)
            .ignoresSafeArea()
    }
    .safeAreaInset(edge: .top, spacing: 0) {
        GPSTrackingBannerContent(
            isTracking: true,
            activeVisitId: 12345,
            activeVisitStartedAt: Date().addingTimeInterval(-754)  // 12:34
        )
    }
}

#Preview("Tracking off") {
    NavigationStack {
        Color(.systemGroupedBackground)
            .ignoresSafeArea()
    }
    .safeAreaInset(edge: .top, spacing: 0) {
        GPSTrackingBannerContent(
            isTracking: false,
            activeVisitId: nil,
            activeVisitStartedAt: nil
        )
    }
}
