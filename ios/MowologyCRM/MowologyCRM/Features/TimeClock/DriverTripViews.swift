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
    @ObservedObject var viewModel: DriverTripViewModel

    var body: some View {
        VStack(spacing: 20) {
            Image(systemName: "steeringwheel")
                .font(.system(size: 52))
                .foregroundStyle(Color.MW.green)
                .padding(.top, 28)

            Text("Are you driving a company vehicle this shift?")
                .font(.title3.bold())
                .multilineTextAlignment(.center)

            Text("The driver has to inspect the vehicle before it moves and log the trip — it's a legal requirement. If you're riding along, you don't. You can change this later if you take over the wheel.")
                .font(.subheadline)
                .foregroundStyle(.secondary)
                .multilineTextAlignment(.center)

            Spacer(minLength: 0)

            Button {
                viewModel.declareDriving()
            } label: {
                Label("Yes, I'm driving", systemImage: "steeringwheel")
                    .font(.headline)
                    .frame(maxWidth: .infinity)
                    .padding(.vertical, 14)
                    .background(Color.MW.green)
                    .foregroundStyle(.white)
                    .clipShape(Capsule())
            }

            Button {
                Task { await viewModel.declareNotDriving() }
            } label: {
                Text("No, I'm not driving")
                    .font(.headline)
                    .frame(maxWidth: .infinity)
                    .padding(.vertical, 14)
                    .overlay(Capsule().stroke(Color(.separator), lineWidth: 1))
            }
            .tint(.primary)
        }
        .padding(24)
        .presentationDetents([.medium, .large])
        .interactiveDismissDisabled()
    }
}

// MARK: - Pre-trip

struct PreTripView: View {
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
                                    .font(.title3)
                                    .foregroundStyle(checked.contains(item.field) ? Color.MW.green : Color(.systemGray3))
                                Text(item.label).foregroundStyle(.primary)
                                Spacer(minLength: 0)
                            }
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
                    Toggle("Trailer unhitched because of this", isOn: $unhitched).tint(Color.MW.orange)
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
                    Toggle("This vehicle is safe to drive", isOn: $safeToDrive).tint(Color.MW.green)
                }

                if let error = viewModel.errorMessage {
                    Section { Text(error).font(.footnote).foregroundStyle(Color.MW.orange) }
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
                            else { Text("Save inspection").font(.headline) }
                            Spacer()
                        }
                    }
                    .disabled(viewModel.isWorking || (viewModel.vehicles.count > 1 && vehicleId == nil))
                } footer: {
                    if !allChecked && criticalDefects.isEmpty && otherDefects.isEmpty {
                        Text("Some items are unticked with no defect described — add a note so the log explains why.")
                    }
                }
            }
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
                        Text(odometerProblem).font(.footnote).foregroundStyle(Color.MW.orange)
                        Toggle("This reading is correct", isOn: $confirmOdometer).tint(Color.MW.orange)
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
                    Section { Text(error).font(.footnote).foregroundStyle(Color.MW.orange) }
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
                            else { Text("Close trip").font(.headline) }
                            Spacer()
                        }
                    }
                    .disabled(viewModel.isWorking)
                }
            }
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
    @ObservedObject var viewModel: DriverTripViewModel

    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            HStack(spacing: 10) {
                Image(systemName: "steeringwheel")
                    .foregroundStyle(viewModel.hasOpenTrip ? Color.MW.green : .secondary)
                VStack(alignment: .leading, spacing: 1) {
                    Text(headline).font(.subheadline.weight(.semibold))
                    Text(detail).font(.caption).foregroundStyle(.secondary)
                }
                Spacer(minLength: 0)
            }

            if let confirmation = viewModel.confirmation, viewModel.waitingToSync == 0 {
                Label(confirmation, systemImage: "checkmark.seal.fill")
                    .font(.caption)
                    .foregroundStyle(Color.MW.green)
            }

            if viewModel.waitingToSync > 0 {
                Label("No signal when you did this — \(viewModel.waitingToSync) saved on this phone as a backup. It files itself when you're back in range.",
                      systemImage: "arrow.triangle.2.circlepath")
                    .font(.caption)
                    .foregroundStyle(Color.MW.green)
                    .fixedSize(horizontal: false, vertical: true)
            }

            if let rejected = viewModel.rejectedMessage {
                VStack(alignment: .leading, spacing: 6) {
                    Label("An entry could not be filed: \(rejected)", systemImage: "exclamationmark.triangle.fill")
                        .font(.caption)
                        .foregroundStyle(Color.MW.orange)
                        .fixedSize(horizontal: false, vertical: true)
                    Text("It is still saved on this phone. Tell the office so your log can be corrected.")
                        .font(.caption2).foregroundStyle(.secondary)
                    Button("I've dealt with it — remove") { viewModel.discardRejected() }
                        .font(.caption.weight(.semibold))
                        .tint(Color.MW.orange)
                }
            }

            if let grounded = viewModel.groundedMessage {
                Label(grounded, systemImage: "exclamationmark.octagon.fill")
                    .font(.footnote.weight(.semibold))
                    .foregroundStyle(.red)
                    .fixedSize(horizontal: false, vertical: true)
            }

            Button {
                if viewModel.hasOpenTrip { viewModel.showPostTrip = true } else { viewModel.showPreTrip = true }
            } label: {
                Text(viewModel.hasOpenTrip ? "End trip (post-trip)" : "I'm driving now")
                    .font(.footnote.weight(.semibold))
                    .frame(maxWidth: .infinity)
                    .padding(.vertical, 9)
                    .overlay(Capsule().stroke(Color.MW.green.opacity(0.5), lineWidth: 1))
            }
            .tint(Color.MW.green)
        }
        .padding(14)
        .background(Color(.systemBackground))
        .clipShape(RoundedRectangle(cornerRadius: 12))
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
