//
//  DriverTripViews.swift
//  MowologyCRM
//
//  The commercial vehicle log, scoped to whoever is actually driving this shift:
//    DriverDeclarationSheet — asked once after clock-in
//    PreTripView            — walk-around inspection before driving
//    PostTripView           — closes the trip (required before clock-out)
//    DriverCard             — Time Clock tab: current state + "I'm driving now" / "End trip"
//

import SwiftUI

// MARK: - Declaration

struct DriverDeclarationSheet: View {
    @Environment(\.itAppStyle) private var appStyle
    @ObservedObject var viewModel: DriverTripViewModel

    var body: some View {
        Group {
            if appStyle.isEnabled {
                ScrollView { declarationContent }
            } else {
                declarationContent
            }
        }
        .itAppScreen()
        .presentationDetents([.medium, .large])
        .interactiveDismissDisabled()
    }

    private var declarationContent: some View {
        VStack(spacing: 20) {
            Image(systemName: "steeringwheel")
                .font(appStyle.font(size: 52))
                .foregroundStyle(appStyle.tenant)
                .padding(.top, 28)

            Text("Are you driving a company vehicle this shift?")
                .font(appStyle.font(.title3, weight: .bold))
                .multilineTextAlignment(.center)

            Text("The driver has to inspect the vehicle before it moves and log the trip — it's a legal requirement. If you're riding along, you don't. You can change this later if you take over the wheel.")
                .font(appStyle.font(.subheadline))
                .foregroundStyle(appStyle.meta)
                .multilineTextAlignment(.center)

            Spacer(minLength: 0)

            Button {
                viewModel.declareDriving()
            } label: {
                Label("Yes, I'm driving", systemImage: "steeringwheel")
                    .font(appStyle.font(.headline))
                    .frame(maxWidth: .infinity, minHeight: appStyle.isEnabled ? 48 : nil)
                    .padding(.vertical, 14)
                    .background(appStyle.tenant)
                    .foregroundStyle(appStyle.onTenant)
                    .clipShape(RoundedRectangle(cornerRadius: appStyle.isEnabled ? appStyle.cornerRadius(12) : 999))
            }

            Button {
                Task { await viewModel.declareNotDriving() }
            } label: {
                Text("No, I'm not driving")
                    .font(appStyle.font(.headline))
                    .frame(maxWidth: .infinity, minHeight: appStyle.isEnabled ? 48 : nil)
                    .padding(.vertical, 14)
                    .overlay(RoundedRectangle(cornerRadius: appStyle.isEnabled ? appStyle.cornerRadius(12) : 999).stroke(appStyle.line, lineWidth: 1))
            }
            .tint(appStyle.text)
        }
        .padding(24)
    }
}

// MARK: - Pre-trip

struct PreTripView: View {
    @Environment(\.itAppStyle) private var appStyle
    @ObservedObject var viewModel: DriverTripViewModel
    @Environment(\.dismiss) private var dismiss

    @State private var vehicleId: String?
    @State private var odometer = ""
    @State private var checked = Set<String>()
    @State private var criticalDefects = ""
    @State private var unhitched = false
    @State private var otherDefects = ""
    @State private var safeToDrive = false

    private var allChecked: Bool { checked.count == viewModel.checks.count && !viewModel.checks.isEmpty }

    var body: some View {
        NavigationStack {
            Form {
                if viewModel.vehicles.count > 1 {
                    Section("Vehicle") {
                        Picker("Vehicle", selection: $vehicleId) {
                            Text("Choose…").tag(String?.none)
                            ForEach(viewModel.vehicles) { v in Text(v.label).tag(String?.some(v.id)) }
                        }
                    }
                } else if let only = viewModel.vehicles.first {
                    Section("Vehicle") { Text(only.label) }
                }

                Section {
                    TextField(viewModel.status?.lastOdometer.map { "Last reading: \($0) km" } ?? "Odometer (km)", text: $odometer)
                        .keyboardType(.numberPad)
                } header: {
                    Text("Odometer start")
                }

                Section {
                    // Deliberately no "tick all": each item is a thing to look at.
                    ForEach(viewModel.checks) { item in
                        Button {
                            if checked.contains(item.field) { checked.remove(item.field) } else { checked.insert(item.field) }
                        } label: {
                            HStack(spacing: 12) {
                                Image(systemName: checked.contains(item.field) ? "checkmark.circle.fill" : "circle")
                                    .font(appStyle.font(.title3))
                                    .foregroundStyle(checked.contains(item.field) ? appStyle.tenant : (appStyle.isEnabled ? appStyle.meta : Color(.systemGray3)))
                                Text(item.label).foregroundStyle(appStyle.text)
                                Spacer(minLength: 0)
                            }
                            .frame(minHeight: appStyle.isEnabled ? 48 : nil)
                            .contentShape(Rectangle())
                        }
                        .buttonStyle(.plain)
                    }
                } header: {
                    Text("Walk-around inspection")
                } footer: {
                    Text("\(checked.count) of \(viewModel.checks.count) checked. Leave an item unticked if it isn't right, and describe it below.")
                }

                Section {
                    TextField("Anything that makes the vehicle unsafe", text: $criticalDefects, axis: .vertical)
                        .lineLimit(2...5)
                    Toggle("Trailer unhitched because of this", isOn: $unhitched).tint(appStyle.attention)
                } header: {
                    Text("Critical defects")
                } footer: {
                    Text("Recording a critical defect means the vehicle must not be driven. The office is notified immediately.")
                }

                Section("Other defects (not urgent)") {
                    TextField("e.g. chip in windshield", text: $otherDefects, axis: .vertical)
                        .lineLimit(1...4)
                }

                Section {
                    Toggle("This vehicle is safe to drive", isOn: $safeToDrive).tint(appStyle.tenant)
                }

                if let error = viewModel.errorMessage {
                    Section { Text(error).font(appStyle.font(.footnote)).foregroundStyle(appStyle.attention) }
                }

                Section {
                    Button {
                        Task {
                            await viewModel.submitPreTrip(
                                vehicleId: vehicleId ?? viewModel.vehicles.first?.id,
                                odometer: odometer, checked: checked,
                                criticalDefects: criticalDefects, unhitched: unhitched,
                                otherDefects: otherDefects, safeToDrive: safeToDrive)
                        }
                    } label: {
                        HStack {
                            Spacer()
                            if viewModel.isWorking { ProgressView() }
                            else { Text("Save inspection").font(appStyle.font(.headline)) }
                            Spacer()
                        }
                        .frame(minHeight: appStyle.isEnabled ? 48 : nil)
                    }
                    .disabled(viewModel.isWorking || (viewModel.vehicles.count > 1 && vehicleId == nil))
                } footer: {
                    if !allChecked && criticalDefects.isEmpty && otherDefects.isEmpty {
                        Text("Some items are unticked with no defect described — add a note so the log explains why.")
                    }
                }
            }
            .itAppForm()
            .itAppScreen()
            .navigationTitle("Pre-trip inspection")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .topBarLeading) {
                    Button("Not driving") {
                        Task { await viewModel.declareNotDriving(); dismiss() }
                    }
                }
            }
            .scrollDismissesKeyboard(.interactively)
            .interactiveDismissDisabled()
        }
    }
}

// MARK: - Post-trip

struct PostTripView: View {
    @Environment(\.itAppStyle) private var appStyle
    @ObservedObject var viewModel: DriverTripViewModel
    /// Called after the trip is closed — the clock-out gate uses this to carry on.
    var onClosed: () -> Void = {}
    @Environment(\.dismiss) private var dismiss

    @State private var odometer = ""
    @State private var remarks = ""
    @State private var hosDriving = ""
    @State private var hosOther = ""
    @State private var hosOff = ""
    @State private var confirmOdometer = false
    @State private var odometerProblem: String?

    var body: some View {
        NavigationStack {
            Form {
                if viewModel.hasOpenTrip {
                    Section("Trip") {
                        if let vehicle = viewModel.openTripVehicle { LabeledContent("Vehicle", value: vehicle) }
                        if let start = viewModel.openTripOdometerStart { LabeledContent("Odometer start", value: "\(start) km") }
                    }
                }

                Section("Odometer end") {
                    TextField("Odometer (km)", text: $odometer).keyboardType(.numberPad)
                    if let odometerProblem {
                        Text(odometerProblem).font(appStyle.font(.footnote)).foregroundStyle(appStyle.attention)
                        Toggle("This reading is correct", isOn: $confirmOdometer).tint(appStyle.attention)
                    }
                }

                Section {
                    TextField("On duty — driving (e.g. 3h 30m)", text: $hosDriving)
                    TextField("On duty — other work", text: $hosOther)
                    TextField("Off duty", text: $hosOff)
                } header: {
                    Text("Hours of service")
                }

                Section("End-of-day remarks") {
                    TextField("Anything the next driver or the office should know", text: $remarks, axis: .vertical)
                        .lineLimit(2...6)
                }

                if let error = viewModel.errorMessage {
                    Section { Text(error).font(appStyle.font(.footnote)).foregroundStyle(appStyle.attention) }
                }

                Section {
                    Button {
                        Task {
                            odometerProblem = await viewModel.submitPostTrip(
                                odometer: odometer, remarks: remarks, hosDriving: hosDriving,
                                hosOther: hosOther, hosOff: hosOff, confirmOdometer: confirmOdometer)
                            if odometerProblem == nil { dismiss(); onClosed() }
                        }
                    } label: {
                        HStack {
                            Spacer()
                            if viewModel.isWorking { ProgressView() }
                            else { Text("Close trip").font(appStyle.font(.headline)) }
                            Spacer()
                        }
                        .frame(minHeight: appStyle.isEnabled ? 48 : nil)
                    }
                    .disabled(viewModel.isWorking)
                }
            }
            .itAppForm()
            .itAppScreen()
            .navigationTitle("Post-trip")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar {
                ToolbarItem(placement: .topBarLeading) { Button("Cancel") { dismiss() } }
            }
            .scrollDismissesKeyboard(.interactively)
        }
    }
}

// MARK: - Time Clock card

struct DriverCard: View {
    @Environment(\.itAppStyle) private var appStyle
    @ObservedObject var viewModel: DriverTripViewModel

    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            HStack(spacing: 10) {
                Image(systemName: "steeringwheel")
                    .foregroundStyle(viewModel.hasOpenTrip ? appStyle.tenant : appStyle.meta)
                VStack(alignment: .leading, spacing: 1) {
                    Text(headline).font(appStyle.font(.subheadline, weight: .semibold))
                    Text(detail).font(appStyle.font(.caption)).foregroundStyle(appStyle.meta)
                }
                Spacer(minLength: 0)
            }

            if let confirmation = viewModel.confirmation, viewModel.waitingToSync == 0 {
                Label(confirmation, systemImage: "checkmark.seal.fill")
                    .font(appStyle.font(.caption))
                    .foregroundStyle(appStyle.isEnabled ? appStyle.sent : appStyle.tenant)
            }

            if viewModel.waitingToSync > 0 {
                Label("No signal when you did this — \(viewModel.waitingToSync) saved on this phone as a backup. It files itself when you're back in range.",
                      systemImage: "arrow.triangle.2.circlepath")
                    .font(appStyle.font(.caption))
                    .foregroundStyle(appStyle.isEnabled ? appStyle.saved : appStyle.tenant)
                    .fixedSize(horizontal: false, vertical: true)
            }

            if let rejected = viewModel.rejectedMessage {
                VStack(alignment: .leading, spacing: 6) {
                    Label("An entry could not be filed: \(rejected)", systemImage: "exclamationmark.triangle.fill")
                        .font(appStyle.font(.caption))
                        .foregroundStyle(appStyle.attention)
                        .fixedSize(horizontal: false, vertical: true)
                    Text("It is still saved on this phone. Tell the office so your log can be corrected.")
                        .font(appStyle.font(.caption2)).foregroundStyle(appStyle.meta)
                    Button("I've dealt with it — remove") { viewModel.discardRejected() }
                        .font(appStyle.font(.caption, weight: .semibold))
                        .tint(appStyle.attention)
                }
            }

            if let grounded = viewModel.groundedMessage {
                Label(grounded, systemImage: "exclamationmark.octagon.fill")
                    .font(appStyle.font(.footnote, weight: .semibold))
                    .foregroundStyle(appStyle.blocked)
                    .fixedSize(horizontal: false, vertical: true)
            }

            Button {
                if viewModel.hasOpenTrip { viewModel.showPostTrip = true } else { viewModel.showPreTrip = true }
            } label: {
                Text(viewModel.hasOpenTrip ? "End trip (post-trip)" : "I'm driving now")
                    .font(appStyle.font(.footnote, weight: .semibold))
                    .frame(maxWidth: .infinity)
                    .frame(minHeight: appStyle.isEnabled ? 48 : nil)
                    .padding(.vertical, 9)
                    .overlay(RoundedRectangle(cornerRadius: appStyle.isEnabled ? appStyle.cornerRadius(12) : 999).stroke(appStyle.tenant.opacity(0.5), lineWidth: 1))
            }
            .tint(appStyle.tenant)
        }
        .padding(14)
        .background(appStyle.surface)
        .clipShape(RoundedRectangle(cornerRadius: appStyle.cornerRadius(12)))
    }

    private var headline: String {
        if viewModel.hasOpenTrip { return "Driving \(viewModel.openTripVehicle ?? "a company vehicle")" }
        return viewModel.status?.declared == false ? "Not driving this shift" : "Vehicle log"
    }

    private var detail: String {
        if viewModel.hasOpenTrip { return "Pre-trip done. Close the trip before you clock out or hand over the wheel." }
        return "Taking over the wheel? Do a pre-trip inspection first."
    }
}
