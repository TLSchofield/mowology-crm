//
//  TrackingViews.swift
//  MowologyCRM
//
//  The two pieces of UI that make location tracking honest:
//    TrackingDisclosureSheet — what is collected, why, who sees it; shown before the
//                              first tracked shift and again whenever the text changes.
//    TrackingStatusCard      — while clocked in, says plainly that location is being
//                              shared, and says so loudly when it ISN'T working.
//

import SwiftUI

// MARK: - Disclosure

struct TrackingDisclosureSheet: View {
    @Environment(\.itAppStyle) private var appStyle

    let disclosure: TrackingDisclosure
    let required: Bool
    let onAgree: () async -> Void
    let onDecline: () async -> Void

    @State private var isWorking = false

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 18) {
                    Image(systemName: "location.circle.fill")
                        .font(appStyle.font(size: 44))
                        .foregroundStyle(appStyle.tenant)

                    Text(disclosure.summary)
                        .font(appStyle.font(.title3, weight: .semibold))

                    ForEach(disclosure.sections) { section in
                        VStack(alignment: .leading, spacing: 4) {
                            Text(section.heading.uppercased())
                                .font(appStyle.font(.caption, weight: .bold))
                                .foregroundStyle(appStyle.tenant)
                            Text(section.body)
                                .font(appStyle.font(.subheadline))
                                .foregroundStyle(appStyle.text)
                                .fixedSize(horizontal: false, vertical: true)
                        }
                    }
                }
                .padding(20)
            }
            .safeAreaInset(edge: .bottom) {
                VStack(spacing: 8) {
                    Button {
                        isWorking = true
                        Task { await onAgree(); isWorking = false }
                    } label: {
                        Group {
                            if isWorking { ProgressView().tint(appStyle.onTenant) }
                            else { Text(disclosure.agreeLabel).font(appStyle.font(.headline)) }
                        }
                        .frame(maxWidth: .infinity)
                        .padding(.vertical, 14)
                        .background(appStyle.tenant)
                        .foregroundStyle(appStyle.onTenant)
                        .clipShape(RoundedRectangle(cornerRadius: appStyle.isEnabled ? appStyle.cornerRadius(12) : 999))
                    }
                    .disabled(isWorking)

                    Button(required ? "Cancel clock-in" : "Not now") {
                        Task { await onDecline() }
                    }
                    .font(appStyle.font(.subheadline))
                    .foregroundStyle(appStyle.meta)
                    .frame(minHeight: appStyle.isEnabled ? 48 : nil)
                    .disabled(isWorking)
                }
                .padding(.horizontal, 20)
                .padding(.vertical, 12)
                .background(.bar)
            }
            .itAppScreen()
            .navigationTitle(disclosure.title)
            .navigationBarTitleDisplayMode(.inline)
            .interactiveDismissDisabled()
        }
    }
}

// MARK: - Status card

struct TrackingStatusCard: View {
    @Environment(\.itAppStyle) private var appStyle

    @ObservedObject private var tracking = GPSTrackingService.shared
    @Environment(\.openURL) private var openURL
    @State private var showDiagnostics = false

    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            HStack(spacing: 10) {
                Image(systemName: tracking.problem == nil ? "location.fill" : "location.slash.fill")
                    .foregroundStyle(tracking.problem == nil ? appStyle.tenant : appStyle.attention)

                VStack(alignment: .leading, spacing: 1) {
                    Text(headline).font(appStyle.font(.subheadline, weight: .semibold))
                    Text(detail).font(appStyle.font(.caption)).foregroundStyle(appStyle.meta)
                }
                Spacer(minLength: 0)
                Button {
                    withAnimation { showDiagnostics.toggle() }
                } label: {
                    Image(systemName: showDiagnostics ? "chevron.up" : "info.circle")
                        .foregroundStyle(appStyle.meta)
                        .frame(minWidth: appStyle.isEnabled ? 48 : nil, minHeight: appStyle.isEnabled ? 48 : nil)
                }
            }

            if let problem = tracking.problem {
                VStack(alignment: .leading, spacing: 8) {
                    Text(problem.message)
                        .font(appStyle.font(.footnote))
                        .foregroundStyle(appStyle.attention)
                        .fixedSize(horizontal: false, vertical: true)
                    Button {
                        if let url = URL(string: UIApplication.openSettingsURLString) { openURL(url) }
                    } label: {
                        Label("Open Settings", systemImage: "gear")
                            .font(appStyle.font(.footnote, weight: .semibold))
                            .frame(minHeight: appStyle.isEnabled ? 48 : nil)
                    }
                    .tint(appStyle.attention)
                }
            }

            if showDiagnostics {
                Divider()
                diagnosticRow("Permission", tracking.locationManager.permissionLabel.replacingOccurrences(of: "_", with: " "))
                diagnosticRow("Precise location", tracking.locationManager.isPrecise ? "on" : "off")
                diagnosticRow("Last fix", age(of: tracking.lastFixAt))
                diagnosticRow("Last upload", age(of: tracking.lastUploadAt))
                diagnosticRow("Waiting to upload", "\(tracking.queueDepth)")
                let d = tracking.locationManager.diagnostics
                diagnosticRow("Mode", tracking.tier.rawValue + (tracking.activeVisitId.map { " · visit \($0)" } ?? ""))
                diagnosticRow("Fixes from iOS / kept", "\(d.delivered) / \(d.accepted)")
                diagnosticRow("Dropped: vague / old / jump", "\(d.droppedAccuracy) / \(d.droppedOld) / \(d.droppedJump)")
                diagnosticRow("Nudges / location errors", "\(d.nudges) / \(d.failures)")
                diagnosticRow("Uploads ok / failed", "\(tracking.uploadsOk) / \(tracking.uploadsFailed)")
                if let err = tracking.lastUploadError {
                    diagnosticRow("Last upload error", err)
                }
                diagnosticRow("Build", Bundle.main.infoDictionary?["CFBundleVersion"] as? String ?? "?")
                if ProcessInfo.processInfo.isLowPowerModeEnabled {
                    diagnosticRow("Low Power Mode", "on — background updates may slow")
                }
            }
        }
        .padding(14)
        .background(appStyle.surface)
        .clipShape(RoundedRectangle(cornerRadius: appStyle.cornerRadius(12)))
        .overlay(
            RoundedRectangle(cornerRadius: appStyle.cornerRadius(12))
                .stroke(tracking.problem == nil ? Color.clear : appStyle.attention.opacity(0.4), lineWidth: 1)
        )
    }

    private var headline: String {
        guard tracking.isTracking else { return "Location sharing is off" }
        return tracking.tier == .enhanced ? "Sharing location — on site" : "Sharing location"
    }

    private var detail: String {
        guard tracking.isTracking, let since = tracking.trackingSince else {
            return "Nothing is recorded while you're off the clock."
        }
        let f = DateFormatter()
        f.locale     = Locale(identifier: "en_CA")
        f.dateFormat = "h:mm a"
        return "Since \(f.string(from: since)). Stops when you clock out."
    }

    private func diagnosticRow(_ label: String, _ value: String) -> some View {
        HStack {
            Text(label).font(appStyle.font(.caption)).foregroundStyle(appStyle.meta)
            Spacer()
            Text(value).font(appStyle.font(.caption).monospacedDigit())
        }
    }

    private func age(of date: Date?) -> String {
        guard let date else { return "never" }
        let s = Int(Date().timeIntervalSince(date))
        if s < 60   { return "\(s)s ago" }
        if s < 3600 { return "\(s / 60)m ago" }
        return "\(s / 3600)h ago"
    }
}
